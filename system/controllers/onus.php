<?php
if (empty($admin['id'])) r2(getUrl('login'));
if (!in_array($admin['user_type'], ['SuperAdmin','Admin'], true)) _alert('You do not have permission to access this page','danger','dashboard');
$base='onus'; $action=isset($routes[1])&&$routes[1]!==''?$routes[1]:'list';
$ui->assign('_title','ONU Management'); $ui->assign('_system_menu','olt'); $ui->assign('_admin',$admin);
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
        // Once live OLT removal is verified, remove the local discovery row
        // and its telemetry history so it disappears from the dashboard too.
        $removedOnuId = (int)$onu->id;
        ORM::for_table('tbl_onu_power_logs')->where('onu_id', $removedOnuId)->delete_many();
        ORM::for_table('tbl_onu_status_logs')->where('onu_id', $removedOnuId)->delete_many();
        $onu->delete();
        _log('Offline ONU removed from OLT and dashboard #'.$olt->id.' ONU #'.$removedOnuId);
        $notice = ['s', 'ONU removed from OLT and dashboard'];
    } catch (Throwable $e) {
        _log('OLT ONU removal failed or unverified #'.$onu->id.': '.$e->getMessage());
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
$totalOnus=ORM::for_table('tbl_onus')->count();
$onlineOnus=ORM::for_table('tbl_onus')->where('status','ONLINE')->count();
$offlineOnus=ORM::for_table('tbl_onus')->where('status','OFFLINE')->count();
$losOnus=ORM::for_table('tbl_onus')->where('status','LOS')->count();
$unassignedOnus=ORM::for_table('tbl_onus')->where_null('customer_id')->count();
$search=trim(_req('search'));$oltFilter=(int)_req('olt_id');
$statusFilter=in_array($action,['online','offline','los','unassigned'],true)?$action:_req('status','all');
$q=ORM::for_table('tbl_onus')->table_alias('o')
    ->select_many('o.*','c.fullname','c.username','c.pppoe_username','c.phonenumber','olt.name')
    ->select('r.name','reseller_name')
    ->left_outer_join('tbl_customers',['o.customer_id','=','c.id'],'c')
    ->left_outer_join('tbl_resellers',['c.reseller_id','=','r.id'],'r')
    ->left_outer_join('tbl_olts',['o.olt_id','=','olt.id'],'olt');
if(in_array($statusFilter,['online','offline','los'],true))$q->where('o.status',strtoupper($statusFilter));
if($statusFilter==='unassigned')$q->where_null('o.customer_id');
if($oltFilter>0)$q->where('o.olt_id',$oltFilter);
if($search!==''){$like='%'.$search.'%';$q->where_raw('(o.mac_address LIKE ? OR o.serial_number LIKE ? OR c.fullname LIKE ? OR c.username LIKE ? OR c.pppoe_username LIKE ?)',[$like,$like,$like,$like,$like]);}
$availableCustomers=ORM::for_table('tbl_customers')->table_alias('c')->select_many('c.id','c.fullname','c.username','c.pppoe_username')->select('r.name','reseller_name')->left_outer_join('tbl_onus',['c.id','=','mapped.customer_id'],'mapped')->left_outer_join('tbl_resellers',['c.reseller_id','=','r.id'],'r')->where_null('mapped.id')->where('c.approval_status','approved')->order_by_asc('c.fullname')->find_many();
$ui->assign('onus',$q->order_by_desc('o.updated_at')->find_many());
$ui->assign('customers',$availableCustomers);$ui->assign('olts',ORM::for_table('tbl_olts')->order_by_asc('name')->find_many());
$ui->assign('onu_total',$totalOnus);$ui->assign('onu_online',$onlineOnus);$ui->assign('onu_offline',$offlineOnus);$ui->assign('onu_los',$losOnus);$ui->assign('onu_unassigned',$unassignedOnus);
$ui->assign('onu_search',$search);$ui->assign('onu_status_filter',$statusFilter);$ui->assign('onu_olt_filter',$oltFilter);
$ui->assign('csrf_token',Csrf::generateAndStoreToken());$ui->display('admin/onus/list.tpl');
