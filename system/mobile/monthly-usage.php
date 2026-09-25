<?php
/** Read-only calendar-month RADIUS accounting for the signed-in customer. */
declare(strict_types=1);

function jm_mobile_monthly_unavailable(string $month, string $reason): array {
    return ['available' => false, 'month' => $month,
        'download_bytes' => null, 'upload_bytes' => null,
        'total_bytes' => null, 'note' => $reason];
}

function jm_mobile_monthly_integer($value): ?int {
    if (!is_string($value) && !is_int($value)) return null;
    $raw = ltrim((string)$value, '0');
    if ($raw === '') return 0;
    if (!ctype_digit($raw) || strlen($raw) > strlen((string)PHP_INT_MAX) ||
        (strlen($raw) === strlen((string)PHP_INT_MAX) &&
        strcmp($raw, (string)PHP_INT_MAX) > 0)) return null;
    return (int)$raw;
}

function jm_mobile_radius_db(PDO $panel): PDO {
    global $config, $radius_user;
    if (!empty($radius_user) && !empty($config['radius_enable'])) {
        // phpNuxBill configures its separate Radius PDO connection in init.php.
        // Do not silently read an unrelated radacct table from the Panel DB.
        $radius = ORM::get_db('radius');
        $radius->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $radius;
    }
    return $panel;
}

function jm_mobile_monthly_usage(PDO $db, string $pppoe): array {
    $start = date('Y-m-01 00:00:00');
    $next = date('Y-m-01 00:00:00', strtotime('first day of next month'));
    $month = substr($start, 0, 7);
    $pppoe = trim($pppoe);
    if ($pppoe === '' || strlen($pppoe) > 100) {
        return jm_mobile_monthly_unavailable($month, 'PPPoE username is not configured.');
    }
    try {
        $db = jm_mobile_radius_db($db);
        // The legacy rad_acct table overwrites cumulative counters and cannot
        // establish a calendar-month total. Only use RADIUS session records.
        $schema = $db->query("SELECT COUNT(DISTINCT COLUMN_NAME) FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'radacct'
            AND COLUMN_NAME IN ('username','acctstarttime','acctstoptime',
                                'acctinputoctets','acctoutputoctets')");
        if ((int)$schema->fetchColumn() !== 5) {
            return jm_mobile_monthly_unavailable($month,
                'No compatible RADIUS session accounting exists in this Panel database.');
        }
        // A session spanning month start cannot be split accurately from
        // its final cumulative counters. Never report a partial sum as total.
        $crossing = $db->prepare('SELECT COUNT(*) FROM radacct
            WHERE username = ? AND BINARY username = BINARY ?
              AND acctstarttime < ?
              AND (acctstoptime IS NULL OR acctstoptime >= ?)');
        $crossing->execute([$pppoe, $pppoe, $start, $start]);
        if ((int)$crossing->fetchColumn() > 0) {
            return jm_mobile_monthly_unavailable($month,
                'A PPPoE session spans month start; exact monthly bytes need periodic accounting snapshots.');
        }
        $sum = $db->prepare('SELECT COUNT(*) AS sessions,
            MIN(acctstarttime) AS coverage_start,
            SUM(COALESCE(acctoutputoctets,0)) AS download_bytes,
            SUM(COALESCE(acctinputoctets,0)) AS upload_bytes,
            SUM(acctoutputoctets IS NOT NULL AND acctinputoctets IS NOT NULL) AS sampled
            FROM radacct WHERE username = ? AND BINARY username = BINARY ?
            AND acctstarttime >= ? AND acctstarttime < ?');
        $sum->execute([$pppoe, $pppoe, $start, $next]);
        $row = $sum->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['sessions'] === 0 ||
            (int)$row['sampled'] !== (int)$row['sessions']) {
            return jm_mobile_monthly_unavailable($month,
                'Complete PPPoE accounting counters are not recorded for this month.');
        }
        $down = jm_mobile_monthly_integer($row['download_bytes']);
        $up = jm_mobile_monthly_integer($row['upload_bytes']);
        if ($down === null || $up === null || $down > PHP_INT_MAX - $up) {
            return jm_mobile_monthly_unavailable($month,
                'Accounting byte totals are outside the supported range.');
        }
        $since = (string)($row['coverage_start'] ?? '');
        $partial = $since !== '' && $since > $start;
        return ['available' => true, 'month' => $month,
            'download_bytes' => $down, 'upload_bytes' => $up,
            'total_bytes' => $down + $up,
            'recorded_since' => $since ?: null,
            'partial_month' => $partial,
            'note' => ($partial
                ? 'Partial month: recorded sessions start ' . $since
                    . '; earlier traffic is not included. '
                : 'Recorded RADIUS session counters for this calendar month. ')
                . 'Values may lag until the next accounting update.'];
    } catch (Throwable $error) {
        error_log('JM monthly accounting read failed (' . get_class($error) . ')');
        return jm_mobile_monthly_unavailable($month,
            'Monthly accounting is temporarily unavailable.');
    }
}

/** Same authenticated RADIUS totals, formatted only for phpNuxBill Smarty views. */
function jm_mobile_monthly_view(PDO $db, string $pppoe): array {
    $usage = jm_mobile_monthly_usage($db, $pppoe);
    foreach (['download', 'upload', 'total'] as $direction) {
        $bytes = $usage[$direction . '_bytes'] ?? null;
        $usage[$direction . '_gb'] = $bytes === null ? null
            : number_format((float)$bytes / 1000000000, 2, '.', '');
    }
    return $usage;
}
