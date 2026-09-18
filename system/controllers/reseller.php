<?php
_admin();
if (!in_array($admin['user_type'], ['SuperAdmin','Admin'], true)) _alert('You do not have permission to access this page','danger','dashboard');
$ui->assign('_title','Reseller Management'); $ui->assign('_system_menu','reseller');
$routeParts=isset($_GET['_route']) ? explode('/',trim($_GET['_route'],'/')) : [];
$action=isset($routes[1]) && $routes[1]!=='' ? $routes[1] : (isset($routeParts[1]) ? $routeParts[1] : 'list');
// These are admin Network pages deliberately dispatched through this stable
// first-party controller; the legacy nginx rewrite loses standalone handler names.
if ($action==='olt') { require $root_path . 'system/controllers/olts.php'; return; }
if ($action==='onu') { require $root_path . 'system/controllers/onus.php'; return; }
// Upgrade legacy profiles whose linked Agent account was removed.  Only an
// exact, unambiguous name match is repaired; nothing is guessed or reassigned.
$orphans=ORM::for_table('tbl_resellers')->table_alias('r')->select_many('r.id','r.name')->left_outer_join('tbl_users',['r.user_id','=','u.id'],'u')->where_null('u.id')->find_array();
foreach($orphans as $orphan){
    $matches=ORM::for_table('tbl_users')->where_in('user_type',['Agent','Admin'])->where_raw('LOWER(fullname) = LOWER(?) OR LOWER(username) = LOWER(?)',[$orphan['name'],$orphan['name']])->find_many();
    if(count($matches)===1 && !ORM::for_table('tbl_resellers')->where('user_id',$matches[0]['id'])->find_one()){
        $legacy=ORM::for_table('tbl_resellers')->find_one($orphan['id']); $legacy->user_id=$matches[0]['id']; $legacy->updated_at=date('Y-m-d H:i:s'); $legacy->save();
        _log('Legacy reseller profile repaired #'.$legacy->id);
    }
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!Csrf::check(_post('csrf_token'))) r2(getUrl('reseller'),'e','Invalid or expired CSRF token');
    if ($action==='save') {
        $user=ORM::for_table('tbl_users')->find_one((int)_post('user_id'));
        if(!$user || !in_array($user['user_type'],['Agent','Admin'],true)) r2(getUrl('reseller'),'e','Select a valid staff/agent account');
        $r=ORM::for_table('tbl_resellers')->where('user_id',$user['id'])->find_one(); if(!$r){$r=ORM::for_table('tbl_resellers')->create();$r->user_id=$user['id'];$r->created_at=date('Y-m-d H:i:s');}
        $r->reseller_code=trim(_post('reseller_code')) ?: strtolower($user['username']); $r->name=trim(_post('name')) ?: $user['fullname']; $r->mobile=trim(_post('mobile')); $r->email=trim(_post('email')); $r->profit_percentage=max(0,min(100,(float)_post('profit_percentage'))); $r->status=in_array(_post('status'),['active','suspended','disabled'],true)?_post('status'):'active'; $r->notes=trim(_post('notes'));$r->updated_at=date('Y-m-d H:i:s');$r->save();_log('Reseller saved #'.$r->id);r2(getUrl('reseller'),'s','Reseller saved');
    }
    if ($action==='assign') { $c=ORM::for_table('tbl_customers')->find_one((int)_post('customer_id'));$r=ORM::for_table('tbl_resellers')->find_one((int)_post('reseller_id'));if(!$c||!$r||$r['status']!=='active')r2(getUrl('reseller'),'e','Choose an active reseller and valid customer');$c->reseller_id=$r['id'];$c->save();_log('Customer #'.$c->id.' assigned reseller #'.$r->id);r2(getUrl('reseller'),'s','Customer assigned'); }
}
$sql="SELECT r.*,u.username,u.fullname,COUNT(c.id) customer_count,SUM(c.status='Active') active_count,COALESCE((SELECT SUM(t.price) FROM tbl_transactions t JOIN tbl_customers tc ON tc.id=t.user_id WHERE tc.reseller_id=r.id AND t.recharged_on>=DATE_FORMAT(CURDATE(),'%Y-%m-01')),0) month_sales FROM tbl_resellers r LEFT JOIN tbl_users u ON u.id=r.user_id LEFT JOIN tbl_customers c ON c.reseller_id=r.id GROUP BY r.id ORDER BY r.name";
$resellers=ORM::for_table('tbl_resellers')->raw_query($sql)->find_array();foreach($resellers as &$r){$r['profit_share']=round($r['month_sales']*$r['profit_percentage']/100,2);$r['offline_count']=$r['customer_count']-$r['active_count'];}unset($r);
$ui->assign('resellers',$resellers);$ui->assign('agents',ORM::for_table('tbl_users')->where_in('user_type',['Agent','Admin'])->order_by_asc('fullname')->find_many());$ui->assign('customers',ORM::for_table('tbl_customers')->order_by_asc('fullname')->find_many());$ui->assign('csrf_token',Csrf::generateAndStoreToken());$ui->display('admin/reseller/dashboard.tpl');
