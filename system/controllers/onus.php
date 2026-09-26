<?php
if (empty($admin['id'])) r2(getUrl('login'));
if (!in_array($admin['user_type'], ['SuperAdmin','Admin'], true)) _alert('You do not have permission to access this page','danger','dashboard');
$base='onus'; $action=isset($routes[1])&&$routes[1]!==''?$routes[1]:'list';
$ui->assign('_title','ONU Management'); $ui->assign('_system_menu','network');
if ($action === 'remove-from-olt') {
    // OLT removal is a separate action from unlinking a customer.
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' ||
        !Csrf::check(_post('token'))) {
        r2(getUrl($base), 'e', 'Invalid or expired request token');
    }
    $onu = ORM::for_table('tbl_onus')->find_one((int)($routes[2] ?? 0));
    if (!$onu || $onu['status'] !== 'OFFLINE' || $onu['customer_id']) {
        r2(getUrl($base), 'e', 'Only offline, unassigned ONUs can be removed from OLT');
    }
    if (!hash_equals(strtoupper((string)$onu['mac_address']),
        strtoupper((string)_post('mac_address')))) {
        r2(getUrl($base), 'e', 'ONU identity changed; refresh the list');
    }
    $olt = ORM::for_table('tbl_olts')->find_one((int)$onu['olt_id']);
    if (!$olt || $olt['status'] === 'Disabled') {
        r2(getUrl($base), 'e', 'OLT not available');
    }
    // Same lock as the active once-per-minute OLT sync. Never race a sync.
    $lock = @fopen('/www/wwwroot/27.147.201.165/system/secure/olt-sync.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        if ($lock) fclose($lock);
        r2(getUrl($base), 'e', 'OLT sync is running. Retry in a moment');
    }
    try {
        $fresh = ORM::for_table('tbl_onus')->find_one((int)$onu['id']);
        if (!$fresh || $fresh['status'] !== 'OFFLINE' || $fresh['customer_id'] ||
            !hash_equals(strtoupper((string)$onu['mac_address']),
                strtoupper((string)$fresh['mac_address'])) ||
            (int)$fresh['olt_id'] !== (int)$olt['id']) {
            throw new RuntimeException('ONU changed while waiting for OLT lock');
        }
        $onu = $fresh;
        require_once $root_path . 'system/autoload/OltManager.php';
        require_once $root_path . 'system/autoload/OltOnuRemoval.php';
        OltOnuRemoval::removeOffline($olt, $onu);
        // Retain row and historical logs as an audit trail. If the ONU
        // re-registers, the regular sync will change status back to ONLINE.
        $onu->status = 'REMOVED';
        $onu->updated_at = date('Y-m-d H:i:s');
        $onu->save();
        $history = ORM::for_table('tbl_onu_status_logs')->create();
        $history->onu_id = $onu->id;
        $history->previous_status = 'OFFLINE';
        $history->status = 'REMOVED';
        $history->recorded_at = date('Y-m-d H:i:s');
        $history->save();
        _log('Offline ONU removed from OLT #'.$olt->id.' ONU #'.$onu->id);
        $notice = ['s', 'OLT removal verified; ONU history retained'];
    } catch (Throwable $e) {
        _log('OLT ONU removal failed or unverified #'.$onu->id);
        $notice = ['e', 'OLT removal could not be verified. Check the OLT before retrying'];
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    r2(getUrl($base), $notice[0], $notice[1]);
}
if ($action === 'unassign') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !Csrf::check(_post('token'))) {
        r2(getUrl($base), 'e', 'Invalid or expired request token');
    }
    $onu = ORM::for_table('tbl_onus')->find_one((int)($routes[2] ?? 0));
    if (!$onu) r2(getUrl($base), 'e', 'ONU not found');
    $onu->customer_id = null;
    $onu->updated_at = date('Y-m-d H:i:s');
    $onu->save();
    _log('ONU customer unassigned #'.$onu->id);
    r2(getUrl($base), 's', 'Customer unassigned; ONU remains on OLT');
}
if($action==='assign'){
    if($_SERVER['REQUEST_METHOD']!=='POST'||!Csrf::check(_post('token')))r2(getUrl($base),'e','Invalid or expired request token');
    $onu=ORM::for_table('tbl_onus')->find_one((int)$routes[2]);$customer=ORM::for_table('tbl_customers')->find_one((int)_post('customer_id'));
    if(!$onu||!$customer)r2(getUrl($base),'e','ONU or customer not found');
    if($onu['status']==='REMOVED')r2(getUrl($base),'e','Removed ONU cannot be assigned until rediscovered by OLT sync');
    if($onu['customer_id']&&$onu['customer_id']!=$customer['id'])r2(getUrl($base),'e','ONU conflict: existing customer mapping was preserved');
    $onu->customer_id=$customer['id'];$onu->updated_at=date('Y-m-d H:i:s');$onu->save();_log('ONU assigned #'.$onu->id.' customer #'.$customer->id);r2(getUrl($base),'s','ONU assigned');
}
$q=ORM::for_table('tbl_onus')->table_alias('o')->select_many('o.*','c.fullname','c.username','olt.name')->left_outer_join('tbl_customers',['o.customer_id','=','c.id'],'c')->left_outer_join('tbl_olts',['o.olt_id','=','olt.id'],'olt');
if(in_array($action,['online','offline','los','removed'],true))$q->where('o.status',strtoupper($action));if($action==='unassigned')$q->where_null('o.customer_id');
$ui->assign('onus',$q->order_by_desc('o.updated_at')->find_many());$ui->assign('customers',ORM::for_table('tbl_customers')->order_by_asc('fullname')->find_many());$ui->assign('csrf_token',Csrf::generateAndStoreToken());$ui->display('admin/onus/list.tpl');
