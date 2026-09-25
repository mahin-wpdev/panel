<?php

// Collect WAN interface byte deltas once per minute. This never resets RouterOS counters.
chdir(dirname(__DIR__));
require 'init.php';

$lock = fopen($CACHE_PATH . DIRECTORY_SEPARATOR . 'jm_isp_usage.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit;
}

ORM::raw_execute("CREATE TABLE IF NOT EXISTS tbl_isp_usage_state (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    interface_name VARCHAR(64) NOT NULL UNIQUE,
    rx_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    tx_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

ORM::raw_execute("CREATE TABLE IF NOT EXISTS tbl_isp_usage_daily (
    usage_date DATE PRIMARY KEY,
    cycle_start DATE NOT NULL,
    isp1_rx BIGINT UNSIGNED NOT NULL DEFAULT 0,
    isp1_tx BIGINT UNSIGNED NOT NULL DEFAULT 0,
    isp2_rx BIGINT UNSIGNED NOT NULL DEFAULT 0,
    isp2_tx BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    KEY idx_cycle_start (cycle_start)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

function jmCycleStart($resetDay)
{
    $resetDay = max(1, min(28, (int)$resetDay));
    $candidate = date('Y-m-') . sprintf('%02d', $resetDay);
    return date('Y-m-d') >= $candidate ? $candidate : date('Y-m-d', strtotime($candidate . ' -1 month'));
}

try {
    $router = ORM::for_table('tbl_routers')->where('enabled', '1')->find_one();
    if (!$router) {
        throw new RuntimeException('No enabled router');
    }
    $client = Mikrotik::getClient($router['ip_address'], $router['username'], $router['password']);
    $responses = $client->sendSync(new PEAR2\Net\RouterOS\Request('/interface/print'));
    $wanted = ['ORBIT' => 'isp1', 'LINK-3' => 'isp2'];
    $current = [];
    foreach ($responses as $response) {
        $name = (string)$response->getProperty('name');
        if (isset($wanted[$name])) {
            $current[$name] = [
                'rx' => max(0, (int)$response->getProperty('rx-byte')),
                'tx' => max(0, (int)$response->getProperty('tx-byte')),
            ];
        }
    }
    if (count($current) !== 2) {
        throw new RuntimeException('ORBIT or LINK-3 interface was not returned by RouterOS');
    }

    $delta = ['isp1_rx' => 0, 'isp1_tx' => 0, 'isp2_rx' => 0, 'isp2_tx' => 0];
    foreach ($wanted as $interface => $key) {
        $state = ORM::for_table('tbl_isp_usage_state')->where('interface_name', $interface)->find_one();
        $rx = $current[$interface]['rx'];
        $tx = $current[$interface]['tx'];
        // On first install, include bytes accumulated since the current RouterOS boot.
        // After a reboot/counter reset, the new counter is the valid delta.
        $delta[$key . '_rx'] = $state ? ($rx >= (int)$state['rx_bytes'] ? $rx - (int)$state['rx_bytes'] : $rx) : $rx;
        $delta[$key . '_tx'] = $state ? ($tx >= (int)$state['tx_bytes'] ? $tx - (int)$state['tx_bytes'] : $tx) : $tx;
        if (!$state) {
            $state = ORM::for_table('tbl_isp_usage_state')->create();
            $state->interface_name = $interface;
        }
        $state->rx_bytes = $rx;
        $state->tx_bytes = $tx;
        $state->updated_at = date('Y-m-d H:i:s');
        $state->save();
    }

    $today = date('Y-m-d');
    $cycleStart = jmCycleStart($config['reset_day'] ?? 1);
    ORM::raw_execute(
        "INSERT INTO tbl_isp_usage_daily (usage_date, cycle_start, isp1_rx, isp1_tx, isp2_rx, isp2_tx, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE cycle_start=VALUES(cycle_start),
           isp1_rx=isp1_rx+VALUES(isp1_rx), isp1_tx=isp1_tx+VALUES(isp1_tx),
           isp2_rx=isp2_rx+VALUES(isp2_rx), isp2_tx=isp2_tx+VALUES(isp2_tx), updated_at=NOW()",
        [$today, $cycleStart, $delta['isp1_rx'], $delta['isp1_tx'], $delta['isp2_rx'], $delta['isp2_tx']]
    );
    file_put_contents($CACHE_PATH . DIRECTORY_SEPARATOR . 'jm_isp_usage_last_run.txt', date('Y-m-d H:i:s'));
} catch (Throwable $e) {
    error_log('JM ISP usage collector: ' . $e->getMessage());
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
