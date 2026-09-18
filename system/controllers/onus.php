<?php
if (empty($admin['id'])) r2(getUrl('login'));
if (!in_array($admin['user_type'], ['SuperAdmin','Admin'], true)) _alert('You do not have permission to access this page','danger','dashboard');
$base='onus'; $action=isset($routes[1])&&$routes[1]!==''?$routes[1]:'list';
$ui->assign('_title','ONU Management'); $ui->assign('_system_menu','network');
if($action==='assign'){
    if($_SERVER['REQUEST_METHOD']!=='POST'||!Csrf::check(_post('token')))r2(getUrl($base),'e','Invalid or expired request token');
    $onu=ORM::for_table('tbl_onus')->find_one((int)$routes[2]);$customer=ORM::for_table('tbl_customers')->find_one((int)_post('customer_id'));
    if(!$onu||!$customer)r2(getUrl($base),'e','ONU or customer not found');
    if($onu['customer_id']&&$onu['customer_id']!=$customer['id'])r2(getUrl($base),'e','ONU conflict: existing customer mapping was preserved');
    $onu->customer_id=$customer['id'];$onu->updated_at=date('Y-m-d H:i:s');$onu->save();_log('ONU assigned #'.$onu->id.' customer #'.$customer->id);r2(getUrl($base),'s','ONU assigned');
}
$q=ORM::for_table('tbl_onus')->table_alias('o')->select_many('o.*','c.fullname','c.username','olt.name')->left_outer_join('tbl_customers',['o.customer_id','=','c.id'],'c')->left_outer_join('tbl_olts',['o.olt_id','=','olt.id'],'olt');
if(in_array($action,['online','offline','los'],true))$q->where('o.status',strtoupper($action));if($action==='unassigned')$q->where_null('o.customer_id');
$ui->assign('onus',$q->order_by_desc('o.updated_at')->find_many());$ui->assign('customers',ORM::for_table('tbl_customers')->order_by_asc('fullname')->find_many());$ui->assign('csrf_token',Csrf::generateAndStoreToken());$ui->display('admin/onus/list.tpl');
