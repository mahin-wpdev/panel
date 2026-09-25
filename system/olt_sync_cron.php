<?php
declare(strict_types=1);

// Background cache refresh only: no OLT configuration is changed here.
$_SERVER['HTTP_HOST'] = '27.147.201.165';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['REQUEST_SCHEME'] = 'http';
$_SERVER['SCRIPT_NAME'] = '/panel/index.php';
$_SERVER['REQUEST_URI'] = '/panel/';

require '/www/wwwroot/27.147.201.165/panel/init.php';
require_once '/www/wwwroot/27.147.201.165/system/autoload/OltManager.php';
require_once '/www/wwwroot/27.147.201.165/panel/system/mobile/auth-core.php';
require_once '/www/wwwroot/27.147.201.165/panel/system/mobile/panel-app.php';
require_once '/www/wwwroot/27.147.201.165/panel/system/mobile/push.php';

$db = ORM::get_db();
$lockPath = '/www/wwwroot/27.147.201.165/system/secure/olt-sync.lock';
$lock = fopen($lockPath, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    exit(0);
}

foreach (ORM::for_table('tbl_olts')->where_not_equal('status', 'Disabled')->find_many() as $olt) {
    try {
        $beforeId = (int) jm_mobile_query(
            $db,
            'SELECT COALESCE(MAX(id),0) FROM tbl_onus WHERE olt_id=?',
            [(int) $olt->id]
        )->fetchColumn();
        $result = OltManager::sync($olt);
        $olt->status = 'Online';
        $olt->last_sync_at = date('Y-m-d H:i:s');
        $olt->last_sync_status = 'success';
        $olt->last_sync_error = null;
        $olt->updated_at = date('Y-m-d H:i:s');
        $olt->save();

        if ((int) ($result['new'] ?? 0) > 0) {
            try {
                $newOnus = jm_mobile_query(
                    $db,
                    'SELECT id,mac_address,pon_port,onu_id,status FROM tbl_onus
                     WHERE olt_id=? AND id>? ORDER BY id ASC',
                    [(int) $olt->id, $beforeId]
                )->fetchAll(PDO::FETCH_ASSOC);
                foreach ($newOnus as $onu) {
                    $location = 'PON 1/' . (string) $onu['pon_port']
                        . ' · ONU ' . (string) $onu['onu_id'];
                    jm_push_send_staff(
                        $db,
                        'New ONU detected',
                        (string) $olt->name . ' · ' . $location . ' · '
                            . (string) $onu['mac_address'],
                        [
                            'type' => 'new_onu',
                            'onu_id' => (string) $onu['id'],
                            'olt_id' => (string) $olt->id,
                            'status' => (string) $onu['status'],
                        ]
                    );
                }
            } catch (Throwable $pushError) {
                error_log(
                    'JM new ONU push failed olt=' . (int) $olt->id . ' '
                    . $pushError->getMessage()
                );
            }
        }

        echo 'OLT ' . $olt->id . ' synced ' . $result['found'] . PHP_EOL;
    } catch (Throwable $e) {
        // A connection problem must not falsely mark every ONU offline.
        $olt->last_sync_at = date('Y-m-d H:i:s');
        $olt->last_sync_status = 'failed';
        $olt->last_sync_error = substr($e->getMessage(), 0, 255);
        $olt->updated_at = date('Y-m-d H:i:s');
        $olt->save();
        echo 'OLT ' . $olt->id . ' failed' . PHP_EOL;
    }
}

flock($lock, LOCK_UN);
fclose($lock);
