<?php
if (empty($admin['id'])) r2(getUrl('login'));
if (!in_array($admin['user_type'], ['SuperAdmin','Admin'], true)) _alert('You do not have permission to access this page','danger','dashboard');
require_once $root_path . 'system/autoload/OltManager.php';
$base='olts'; $action=isset($routes[1])&&$routes[1]!==''?$routes[1]:'list';
$ui->assign('_title','OLT Management'); $ui->assign('_system_menu','olt'); $ui->assign('_admin',$admin);
function oltRedirect($base,$type,$message){ r2(getUrl($base),$type==='s'?'s':'e',$message); }
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!Csrf::check(_post('token'))) oltRedirect($base,'e','Invalid or expired request token');
    $id=(int)_post('id');
    if($action==='save'){
        $host=trim(_post('management_host')); $protocol=strtolower(trim(_post('protocol'))); $port=(int)_post('port');
        if(trim(_post('name'))===''||trim(_post('vendor'))===''||!OltManager::validHost($host)||$port<1||$port>65535||!in_array($protocol,['snmp','ssh','telnet','api'],true)) oltRedirect($base,'e','Enter valid OLT connection details');
        $o=$id?ORM::for_table('tbl_olts')->find_one($id):null; $isNew=!$o; if(!$o)$o=ORM::for_table('tbl_olts')->create();
        $o->name=trim(_post('name'));$o->vendor=trim(_post('vendor'));$o->model=trim(_post('model'));$o->management_host=$host;$o->management_vlan=trim(_post('management_vlan'));$o->protocol=$protocol;$o->port=$port;$o->username=trim(_post('username'));$o->location=trim(_post('location'));$o->area=trim(_post('area'));$o->notes=trim(_post('notes'));$o->updated_at=date('Y-m-d H:i:s');
        if($isNew){$o->status='Unknown';$o->created_at=date('Y-m-d H:i:s');}
        $password=_post('password'); if($password!=='')$o->encrypted_password=OltManager::encrypt($password); if($isNew&&empty($o->encrypted_password))oltRedirect($base,'e','Password is required for a new OLT');
        $o->save();_log('OLT '.($isNew?'created':'updated').' #'.$o->id);oltRedirect($base,'s','OLT saved');
    }
    $o=ORM::for_table('tbl_olts')->find_one($id); if(!$o)oltRedirect($base,'e','OLT not found');
    if($action==='test'){ $result=OltManager::test($o);$o->status=$result['status']==='Connected'?'Online':'Offline';$o->updated_at=date('Y-m-d H:i:s');$o->save();_log('OLT connection test #'.$o->id.' '.$result['status']);oltRedirect($base,$result['status']==='Connected'?'s':'e','Connection test: '.$result['status']); }
    if($action==='sync'){try{$result=OltManager::sync($o);$o->status='Online';$o->last_sync_at=date('Y-m-d H:i:s');$o->last_sync_status='success';$o->last_sync_error=null;$o->updated_at=date('Y-m-d H:i:s');$o->save();_log('OLT sync #'.$o->id.' found '.$result['found']);oltRedirect($base,'s','Sync complete: '.$result['found'].' ONU found');}catch(Throwable $e){$o->last_sync_at=date('Y-m-d H:i:s');$o->last_sync_status='failed';$o->last_sync_error=substr($e->getMessage(),0,255);$o->updated_at=date('Y-m-d H:i:s');$o->save();_log('OLT sync failed #'.$o->id);oltRedirect($base,'e','Sync failed. Review OLT credentials and connectivity.');}}
    if($action==='delete'){if(ORM::for_table('tbl_onus')->where('olt_id',$o->id)->count()>0)oltRedirect($base,'e','Cannot delete an OLT with discovered ONUs. Keep it disabled instead.');$o->delete();_log('OLT deleted #'.$id);oltRedirect($base,'s','OLT deleted');}
}
if($action==='add'||$action==='edit'){$record=$action==='edit'?ORM::for_table('tbl_olts')->find_one((int)$routes[2]):null;if($action==='edit'&&!$record)oltRedirect($base,'e','OLT not found');$ui->assign('olt',$record);$ui->assign('csrf_token',Csrf::generateAndStoreToken());$ui->display('admin/olts/form.tpl');return;}
$olts=ORM::for_table('tbl_olts')->order_by_desc('id')->find_many();foreach($olts as $o){$o->onu_count=ORM::for_table('tbl_onus')->where('olt_id',$o->id)->count();$o->online_count=ORM::for_table('tbl_onus')->where('olt_id',$o->id)->where('status','ONLINE')->count();$o->offline_count=ORM::for_table('tbl_onus')->where('olt_id',$o->id)->where('status','OFFLINE')->count();$o->los_count=ORM::for_table('tbl_onus')->where('olt_id',$o->id)->where('status','LOS')->count();$o->unassigned_count=ORM::for_table('tbl_onus')->where('olt_id',$o->id)->where_null('customer_id')->count();}
$ui->assign('total_onu',ORM::for_table('tbl_onus')->count());$ui->assign('total_online',ORM::for_table('tbl_onus')->where('status','ONLINE')->count());
$ui->assign('olts',$olts);$ui->assign('csrf_token',Csrf::generateAndStoreToken());$ui->display('admin/olts/list.tpl');
