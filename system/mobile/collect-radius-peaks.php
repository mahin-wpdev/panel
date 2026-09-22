<?php
/**
 * CLI-only RADIUS accounting rate collector. Run every minute on the server.
 * It does not touch billing, PPPoE, RouterOS, or OLT state.
 * Average bps = (new cumulative octets - previous octets) * 8 / interval seconds.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
$isApi = true;
require dirname(__DIR__, 2) . '/init.php';
require_once __DIR__ . '/monthly-usage.php';

function jm_peak_octets($raw): ?int {
    if (!is_string($raw) && !is_int($raw)) return null;
    $raw = (string)$raw;
    if ($raw === '' || !ctype_digit($raw)) return null;
    $trimmed = ltrim($raw, '0');
    if ($trimmed === '') return 0;
    $max = (string)PHP_INT_MAX;
    if (strlen($trimmed) > strlen($max) ||
        (strlen($trimmed) === strlen($max) && strcmp($trimmed, $max) > 0)) return null;
    return (int)$trimmed;
}

function jm_peak_collect(PDO $panel, PDO $radius): array {
    $result = ['seen'=>0,'baselines'=>0,'samples'=>0,'skipped'=>0,'locked'=>false];
    $panel->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $radius->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    if ((int)$panel->query("SELECT GET_LOCK('jm_radius_speed_peaks',0)")->fetchColumn() !== 1) {
        $result['locked'] = true;
        return $result;
    }
    try {
        // No customer ID is accepted from the app or a command-line argument.
        // Ambiguous PPPoE usernames are excluded to prevent cross-account peaks.
        $names = [];
        $customers = $panel->query("SELECT id, username, pppoe_username
            FROM tbl_customers WHERE status='Active'");
        foreach ($customers as $customer) {
            $name = trim((string)($customer['pppoe_username'] ?: $customer['username']));
            if ($name === '') continue;
            if (array_key_exists($name, $names)) $names[$name] = null;
            else $names[$name] = (int)$customer['id'];
        }
        // A stopped session's last update may precede the stop timestamp.
        $rows = $radius->query("SELECT acctuniqueid,username,
            acctinputoctets,acctoutputoctets,
            UNIX_TIMESTAMP(COALESCE(acctstoptime,acctupdatetime,acctstarttime)) AS observed_s
            FROM radacct WHERE acctupdatetime >= NOW() - INTERVAL 5 MINUTE
                OR acctstoptime >= NOW() - INTERVAL 5 MINUTE
            ORDER BY acctupdatetime DESC LIMIT 5000");
        $stateGet = $panel->prepare('SELECT customer_id,recorded_at,input_octets,output_octets
            FROM tbl_mobile_radius_speed_state WHERE acctuniqueid=? LIMIT 1');
        $stateSave = $panel->prepare('INSERT INTO tbl_mobile_radius_speed_state
            (acctuniqueid,customer_id,recorded_at,input_octets,output_octets)
            VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE
            customer_id=VALUES(customer_id),recorded_at=VALUES(recorded_at),
            input_octets=VALUES(input_octets),output_octets=VALUES(output_octets)');
        $peakSave = $panel->prepare('INSERT INTO tbl_mobile_radius_speed_peaks
            (customer_id,download_bps,download_at_ms,upload_bps,upload_at_ms,last_recorded_ms)
            VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE
            download_at_ms=IF(VALUES(download_bps)>download_bps,VALUES(download_at_ms),download_at_ms),
            upload_at_ms=IF(VALUES(upload_bps)>upload_bps,VALUES(upload_at_ms),upload_at_ms),
            download_bps=GREATEST(download_bps,VALUES(download_bps)),
            upload_bps=GREATEST(upload_bps,VALUES(upload_bps)),
            last_recorded_ms=GREATEST(last_recorded_ms,VALUES(last_recorded_ms))');
        foreach ($rows as $row) {
            $result['seen']++;
            $username = (string)$row['username'];
            $id = $names[$username] ?? null;
            $unique = (string)$row['acctuniqueid'];
            $at = jm_peak_octets($row['observed_s']);
            $in = jm_peak_octets($row['acctinputoctets']);
            $out = jm_peak_octets($row['acctoutputoctets']);
            if (!$id || $unique === '' || strlen($unique)>64 ||
                $at === null || $in === null || $out === null) {
                $result['skipped']++; continue;
            }
            $stateGet->execute([$unique]);
            $before = $stateGet->fetch(PDO::FETCH_ASSOC);
            if ($before && ((int)$before['customer_id'] !== $id || $at <= (int)$before['recorded_at'])) {
                $result['skipped']++; continue;
            }
            if ($before) {
                $elapsed = $at - (int)$before['recorded_at'];
                $oldIn = jm_peak_octets($before['input_octets']);
                $oldOut = jm_peak_octets($before['output_octets']);
                // First sample, rollover, stale gap: baseline only, never invent a peak.
                if ($elapsed > 0 && $elapsed <= 900 && $oldIn !== null &&
                    $oldOut !== null && $in >= $oldIn && $out >= $oldOut) {
                    $up = (int)round(($in-$oldIn)*8/$elapsed);
                    $down = (int)round(($out-$oldOut)*8/$elapsed);
                    if ($up <= 1000000000000 && $down <= 1000000000000) {
                        $stamp = $at * 1000;
                        $peakSave->execute([$id,$down,$stamp,$up,$stamp,$stamp]);
                        $result['samples']++;
                    } else $result['skipped']++;
                } else $result['baselines']++;
            } else $result['baselines']++;
            $stateSave->execute([$unique,$id,$at,$in,$out]);
        }
        // Session state is expendable after a month; per-customer peaks persist.
        $panel->exec('DELETE FROM tbl_mobile_radius_speed_state
            WHERE updated_at < NOW() - INTERVAL 30 DAY');
        return $result;
    } finally {
        $panel->query("SELECT RELEASE_LOCK('jm_radius_speed_peaks')")->fetchColumn();
    }
}

try {
    $panel = ORM::get_db();
    $radius = jm_mobile_radius_db($panel);
    $out = jm_peak_collect($panel, $radius);
    echo json_encode($out, JSON_INVALID_UTF8_SUBSTITUTE), PHP_EOL;
} catch (Throwable $error) {
    error_log('JM radius speed collector failed (' . get_class($error) . ')');
    fwrite(STDERR, "RADIUS speed collector unavailable; inspect server logs and migration.\n");
    exit(1);
}
