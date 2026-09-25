<?php
/** Read-only customer self-care dashboard for the isolated mobile API. */
declare(strict_types=1);

function jm_mobile_customer_dashboard(PDO $db, int $customerId): ?array
{
    // Always filter by the authenticated customer ID; never accept an ID from a query string.
    $stmt = $db->prepare(<<<'SQL'
SELECT c.id, c.username, c.fullname, c.status, c.balance, c.pppoe_username,
       r.namebp, r.expiration, r.status AS recharge_status,
       p.name_plan, p.price, b.rate_down, b.rate_down_unit,
       b.rate_up, b.rate_up_unit
FROM tbl_customers c
LEFT JOIN tbl_user_recharges r ON r.id = (
    SELECT latest.id FROM tbl_user_recharges latest
    WHERE latest.customer_id = c.id
    ORDER BY latest.expiration DESC, latest.id DESC LIMIT 1
)
LEFT JOIN tbl_plans p ON p.id = r.plan_id
LEFT JOIN tbl_bandwidth b ON b.id = p.id_bw
WHERE c.id = ? LIMIT 1
SQL);
    $stmt->execute([$customerId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['status'] !== 'Active') {
        return null;
    }

    $expiry = $row['expiration'] ?: null;
    $days = $expiry ? (int)((strtotime($expiry . ' 00:00:00') - strtotime('today')) / 86400) : null;
    $hasPackage = $row['namebp'] !== null;
    $isActive = $hasPackage && $days !== null && $days >= 0 && $row['recharge_status'] === 'on';
    $speed = $row['rate_down'] === null ? null :
        ((int)$row['rate_down']) . ' ' . $row['rate_down_unit'];
    require_once __DIR__ . '/monthly-usage.php';
    $pppoe = (string)($row['pppoe_username'] ?: $row['username']);
    $monthlyUsage = jm_mobile_monthly_usage($db, $pppoe);
    $pppoeOnline = null;
    $connectedSince = null;
    $connectedSeconds = null;
    try {
        $radius = jm_mobile_radius_db($db);
        $schema = $radius->query("SELECT COUNT(DISTINCT COLUMN_NAME)
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='radacct'
              AND COLUMN_NAME IN ('username','acctstarttime','acctstoptime')");
        if ((int)$schema->fetchColumn() === 3) {
            $sessionStmt = $radius->prepare("SELECT acctstarttime
                FROM radacct
                WHERE username=? AND BINARY username=BINARY ?
                  AND acctstoptime IS NULL
                ORDER BY acctstarttime DESC LIMIT 1");
            $sessionStmt->execute([$pppoe, $pppoe]);
            $sessionStart = $sessionStmt->fetchColumn();
            if ($sessionStart !== false && $sessionStart !== null) {
                $connectedSince = (string)$sessionStart;
                $startedAt = strtotime($connectedSince);
                if ($startedAt !== false) {
                    $connectedSeconds = max(0, time() - $startedAt);
                    $pppoeOnline = true;
                }
            } else {
                $pppoeOnline = false;
            }
        }
    } catch (Throwable $ignored) {
        // Dashboard remains usable if live RADIUS session data is unavailable.
    }
    require_once __DIR__ . '/radius-peak.php';
    $serverPeak = jm_radius_peak_for_customer($db, $customerId);
    $onuRx = null;
    $onuStatus = null;
    try {
        $onuStmt = $db->prepare("SELECT rx_power,status FROM tbl_onus
            WHERE customer_id=? AND status<>'REMOVED'
            ORDER BY CASE WHEN status='ONLINE' THEN 0 ELSE 1 END,
                     updated_at DESC,id DESC LIMIT 1");
        $onuStmt->execute([$customerId]);
        $onu = $onuStmt->fetch(PDO::FETCH_ASSOC);
        if ($onu) {
            $onuRx = $onu['rx_power'] === null ? null : (float)$onu['rx_power'];
            $onuStatus = $onu['status'] ?: null;
        }
    } catch (Throwable $ignored) {
        // Older installations may not have ONU inventory yet.
    }

    return [
        'customer' => [
            'id' => (int)$row['id'],
            'username' => $row['username'],
            'name' => $row['fullname'],
            'status' => $row['status'],
            'pppoe_username' => $row['pppoe_username'],
        ],
        'package' => [
            'name' => $row['name_plan'] ?: $row['namebp'],
            'speed' => $speed,
            'price_bdt' => $row['price'] === null ? null : (float)$row['price'],
            'balance_bdt' => $row['balance'] === null ? null : (float)$row['balance'],
            'expiration' => $expiry,
            'days_remaining' => $days === null ? null : max(0, $days),
            'state' => !$hasPackage ? 'none' : ($isActive ? 'active' : 'expired'),
        ],
        'demo' => str_starts_with($row['username'], 'jm_demo_'),
        'monthly_usage' => $monthlyUsage,
        'traffic_peak' => $serverPeak,
        'network' => [
            'pppoe_online' => $pppoeOnline,
            'connected_since' => $connectedSince,
            'connected_seconds' => $connectedSeconds,
            'usage_download_bytes' => null,
            'usage_upload_bytes' => null,
            'onu_rx_dbm' => $onuRx,
            'onu_status' => $onuStatus,
        ],
    ];
}
