<?php
_admin();
$isAdmin=in_array($admin['user_type'],['SuperAdmin','Admin'],true);
$isReseller=$admin['user_type']==='Agent';
$ui->assign('_title',$isReseller?'Reseller Dashboard':'Reseller Management');
$ui->assign('_system_menu','reseller');
$ui->assign('_admin',$admin);
$action=$routes[1] ?? 'list';

if($isReseller){
    $profile=ORM::for_table('tbl_resellers')->where('user_id',$admin['id'])->where('status','active')->find_one();
    if(!$profile)_alert('Your reseller profile is not active. Please contact an administrator.','danger','dashboard');
    if(trim((string)$profile['customer_prefix'])==='' && trim((string)$profile['reseller_code'])!==''){$profile->customer_prefix=preg_replace('/[^a-z0-9_-]/i','',strtolower($profile['reseller_code']));$profile->updated_at=date('Y-m-d H:i:s');$profile->save();}
    if($action==='live-status'){
        $customer=ORM::for_table('tbl_customers')->where('id',(int)($routes[2]??0))->where('reseller_id',$profile['id'])->find_one();
        if(!$customer){http_response_code(404);header('Content-Type: application/json');echo json_encode(['status'=>'Unavailable']);exit;}
        $active=ORM::for_table('tbl_user_recharges')->where('customer_id',$customer['id'])->where('status','on')->order_by_desc('id')->find_one();
        $result='Offline';
        try{
            if($active){$plan=ORM::for_table('tbl_plans')->find_one($active['plan_id']);if($plan){$device=Package::getDevice($plan);if($device&&file_exists($device)){require_once $device;ini_set('default_socket_timeout',5);$result=(new $plan['device'])->online_customer($customer,$plan['routers'])?'Online':'Offline';}}}
        }catch(Throwable $e){$result='Unavailable';}
        header('Content-Type: application/json');echo json_encode(['status'=>$result]);exit;
    }
    if($action==='traffic'){
        $customer=ORM::for_table('tbl_customers')->where('id',(int)($routes[2]??0))->where('reseller_id',$profile['id'])->find_one();
        if(!$customer){http_response_code(404);header('Content-Type: application/json');echo json_encode(['error'=>'Customer not found']);exit;}
        header('Content-Type: application/json');echo json_encode(CustomerTraffic::json($customer));exit;
    }
    /* A reseller can manage only the basic identity/contact data of customers it owns.
       Router, package, recharge, ownership and ONU administration remain admin-only. */
    if($action==='customer-add'){
        $ui->assign('is_reseller_view',true);$ui->assign('reseller_screen','customer-add');$ui->assign('profile',$profile);
        $ui->assign('csrf_token',Csrf::generateAndStoreToken());$ui->display('admin/reseller/dashboard.tpl');return;
    }
    if($action==='customers'){
        $search=trim(_req('search'));$filter=_req('filter','all');$order=_req('order','username');$direction=_req('orderby','asc')==='desc'?'desc':'asc';
        $allowedOrder=['username','fullname','created_at','status'];if(!in_array($order,$allowedOrder,true))$order='username';
        $query=ORM::for_table('tbl_customers')->where('reseller_id',$profile['id']);
        if($search!==''){$like='%'.$search.'%';$query->where_raw('(username LIKE ? OR fullname LIKE ? OR phonenumber LIKE ? OR email LIKE ?)',[$like,$like,$like,$like]);}
        if($filter!=='all')$query->where('status',$filter);
        $direction==='desc'?$query->order_by_desc($order):$query->order_by_asc($order);
        $customers=Paginator::findMany($query,['search'=>$search],30,'&filter='.urlencode($filter).'&order='.urlencode($order).'&orderby='.$direction);
        $ui->assign('is_reseller_view',true);$ui->assign('reseller_screen','customers');$ui->assign('profile',$profile);$ui->assign('d',$customers);$ui->assign('search',$search);$ui->assign('filter',$filter);$ui->assign('order',$order);$ui->assign('orderby',$direction);$ui->assign('statuses',ORM::for_table('tbl_customers')->getEnum('status'));$ui->assign('csrf_token',Csrf::generateAndStoreToken());$ui->display('admin/reseller/customers.tpl');return;
    }
    if($action==='customer-save'){
        if($_SERVER['REQUEST_METHOD']!=='POST'||!Csrf::check(_post('csrf_token')))r2(getUrl('reseller/customer-add'),'e','Invalid or expired request token');
        $base=strtolower(trim(alphanumeric(_post('username'), '_-')));$prefix=strtolower(trim($profile['customer_prefix']?:$profile['reseller_code']));
        if($prefix==='')r2(getUrl('reseller'),'e','Your reseller username suffix is not configured. Please contact an administrator.');
        $username=$base.'.'.$prefix;$fullname=trim(_post('fullname'));$password=trim(_post('password'));
        $msg='';if(!preg_match('/^[a-z0-9][a-z0-9_-]{1,40}$/',$base))$msg.='Username must use 2-41 letters, numbers, underscore or hyphen.<br>';
        if(Validator::Length($fullname,36,1)===false)$msg.='Full name should be between 2 and 35 characters.<br>';
        if(!Validator::Length($password,36,2))$msg.='Password should be between 3 and 35 characters.<br>';
        if(ORM::for_table('tbl_customers')->where('username',$username)->find_one()||ORM::for_table('tbl_customers')->where('pppoe_username',$username)->find_one())$msg.='This reseller username already exists.<br>';
        if($msg!=='')r2(getUrl('reseller/customer-add'),'e',$msg);
        $customer=ORM::for_table('tbl_customers')->create();$customer->username=$username;$customer->pppoe_username=$username;$customer->password=$password;$customer->pppoe_password=$password;
        $customer->fullname=$fullname;$customer->email=trim(_post('email'));$customer->phonenumber=Lang::phoneFormat(_post('phonenumber'));$customer->address=trim(_post('address'));
        $customer->service_type=in_array(_post('service_type'),['PPPOE','Hotspot','Others'],true)?_post('service_type'):'PPPOE';$customer->account_type='Personal';$customer->status='Inactive';$customer->approval_status=!empty($profile['auto_approve_customers'])?'approved':'pending';$customer->created_by=$admin['id'];$customer->reseller_id=$profile['id'];$customer->save();
        /* Follow the core customer-create welcome SMS flow.  It is enabled by
           default in the reseller form, but the reseller may deliberately
           untick it before submitting. */
        if(isset($_POST['send_whatsapp'])||isset($_POST['send_sms'])||isset($_POST['send_mail'])){
            try{
                $welcome=Lang::getNotifText('welcome_message');
                $welcome=str_replace(['[[company]]','[[name]]','[[username]]','[[password]]','[[url]]'],[$config['CompanyName'],$customer['fullname'],$customer['username'],$customer['password'],APP_URL.'/?_route=login'],$welcome);
                $sent=[];
                if(!empty($profile['allow_whatsapp'])&&isset($_POST['send_whatsapp'])&&!empty($customer['phonenumber'])){Message::sendWhatsapp($customer['phonenumber'],$welcome);$sent[]='WhatsApp';}
                if(!empty($profile['allow_sms'])&&isset($_POST['send_sms'])&&!empty($customer['phonenumber'])){Message::sendSMS($customer['phonenumber'],$welcome);$sent[]='SMS';}
                if(!empty($profile['allow_email'])&&isset($_POST['send_mail'])&&!empty($customer['email'])){Message::sendEmail($customer['email'],'Welcome to '.$config['CompanyName'],$welcome,$customer['email']);$sent[]='Email';}
                if($sent)_log('Welcome message sent via '.implode(', ',$sent).' for reseller customer #'.$customer->id);
            }catch(Throwable $e){_log('Welcome SMS failed for reseller customer #'.$customer->id.': '.$e->getMessage());}
        }
        _log('Reseller #'.$profile->id.' created customer #'.$customer->id);r2(getUrl('reseller/customer-view/').$customer->id,'s','Customer created. An administrator must assign package/router and activate service.');
    }
    if($action==='customer-edit'||$action==='customer-update')r2(getUrl('reseller/customer-view/').(int)($routes[2]??_post('customer_id')),'e','Reseller customer editing is disabled by administrator policy.');
    if($action==='customer-view'){
        $customerId=(int)($routes[2]??_post('customer_id'));$selected=ORM::for_table('tbl_customers')->where('id',$customerId)->where('reseller_id',$profile['id'])->find_one();
        if(!$selected)r2(getUrl('reseller'),'e','Customer was not found or is not assigned to your reseller.');
        $active=ORM::for_table('tbl_user_recharges')->where('customer_id',$selected->id)->where('status','on')->order_by_desc('id')->find_one();
        $onu=ORM::for_table('tbl_onus')->table_alias('o')->select_many('o.*','olt.name')->left_outer_join('tbl_olts',['o.olt_id','=','olt.id'],'olt')->where('o.customer_id',$selected->id)->find_one();$transactions=ORM::for_table('tbl_transactions')->where('user_id',$selected->id)->order_by_desc('id')->limit(20)->find_many();
        $ui->assign('is_reseller_view',true);$ui->assign('reseller_screen','customer-view');$ui->assign('profile',$profile);$ui->assign('selected_customer',$selected);$ui->assign('customer_package',$active);$ui->assign('customer_onu',$onu);$ui->assign('customer_transactions',$transactions);$ui->assign('csrf_token',Csrf::generateAndStoreToken());$ui->display('admin/reseller/dashboard.tpl');return;
    }
    if($action==='invoice'){
        $transaction=ORM::for_table('tbl_transactions')->table_alias('t')->select_many('t.*')->join('tbl_customers',['t.user_id','=','c.id'],'c')->where('t.id',(int)($routes[2]??0))->where('c.reseller_id',$profile['id'])->find_one();
        if(!$transaction)r2(getUrl('reseller'),'e','Invoice was not found or does not belong to your reseller.');
        Package::createInvoice($transaction);
        $ui->assign('is_reseller_view',true);$ui->assign('profile',$profile);$ui->assign('invoice_transaction',$transaction);$ui->display('admin/reseller/invoice.tpl');return;
    }
    if($action==='report'){
        $from=preg_match('/^\d{4}-\d{2}-\d{2}$/',_req('from'))?_req('from'):date('Y-m-01');$to=preg_match('/^\d{4}-\d{2}-\d{2}$/',_req('to'))?_req('to'):date('Y-m-d');
        $reportRows=ORM::for_table('tbl_reseller_earnings')->table_alias('e')->select_many('e.*','c.fullname','c.username','t.plan_name')->left_outer_join('tbl_customers',['e.customer_id','=','c.id'],'c')->left_outer_join('tbl_transactions',['e.recharge_id','=','t.id'],'t')->where('e.reseller_id',$profile['id'])->where_gte('e.created_at',$from.' 00:00:00')->where_lte('e.created_at',$to.' 23:59:59')->order_by_desc('e.id')->find_many();
        $ui->assign('is_reseller_view',true);$ui->assign('reseller_screen','report');$ui->assign('profile',$profile);$ui->assign('report_rows',$reportRows);$ui->assign('report_from',$from);$ui->assign('report_to',$to);$ui->display('admin/reseller/dashboard.tpl');return;
    }
    if($_SERVER['REQUEST_METHOD']==='POST')r2(getUrl('reseller'),'e','Reseller accounts cannot change customer ownership.');
    $customers=ORM::for_table('tbl_customers')->where('reseller_id',$profile['id'])->order_by_desc('id')->find_many();
    $total=count($customers);$online=0;$expiring=0;$ownedIds=[];foreach($customers as $customer){$ownedIds[]=(int)$customer['id'];if($customer['status']==='Active')$online++;$active=ORM::for_table('tbl_user_recharges')->where('customer_id',$customer['id'])->where('status','on')->order_by_desc('id')->find_one();$customer->package_name=$active?$active['namebp']:'No active package';$customer->package_expiry=$active?$active['expiration'].' '.$active['time']:'—';if($active&&!empty($active['expiration'])){$expiry=strtotime((string)$active['expiration']);if($expiry!==false&&$expiry>=strtotime('today')&&$expiry<=strtotime('+7 days'))$expiring++;}}
    $onuTotal=0;$onuOnline=0;$ownedOnus=[];try{if($ownedIds){$onuTotal=ORM::for_table('tbl_onus')->where_in('customer_id',$ownedIds)->count();$onuOnline=ORM::for_table('tbl_onus')->where_in('customer_id',$ownedIds)->where('status','ONLINE')->count();$ownedOnus=ORM::for_table('tbl_onus')->table_alias('o')->select_many('o.*','c.fullname','c.username','olt.name')->left_outer_join('tbl_customers',['o.customer_id','=','c.id'],'c')->left_outer_join('tbl_olts',['o.olt_id','=','olt.id'],'olt')->where_in('o.customer_id',$ownedIds)->order_by_desc('o.updated_at')->find_many();}}catch(Throwable $e){$onuTotal=0;$onuOnline=0;$ownedOnus=[];}
    $sales=(float)ORM::for_table('tbl_transactions')->table_alias('t')->join('tbl_customers',['t.user_id','=','c.id'],'c')->where('c.reseller_id',$profile['id'])->where_gte('t.recharged_on',date('Y-m-01'))->sum('t.price');
    $earnings=ORM::for_table('tbl_reseller_earnings')->table_alias('e')->select_many('e.*','c.fullname','c.username','t.plan_name')->left_outer_join('tbl_customers',['e.customer_id','=','c.id'],'c')->left_outer_join('tbl_transactions',['e.recharge_id','=','t.id'],'t')->where('e.reseller_id',$profile['id'])->order_by_desc('e.id')->limit(20)->find_array();
    $earned=(float)ORM::for_table('tbl_reseller_earnings')->where('reseller_id',$profile['id'])->where('status','earned')->sum('profit_amount');$settled=(float)ORM::for_table('tbl_reseller_settlements')->where('reseller_id',$profile['id'])->sum('amount');
    $today=(float)ORM::for_table('tbl_reseller_earnings')->where('reseller_id',$profile['id'])->where_gte('created_at',date('Y-m-d').' 00:00:00')->sum('profit_amount');$month=(float)ORM::for_table('tbl_reseller_earnings')->where('reseller_id',$profile['id'])->where_gte('created_at',date('Y-m-01').' 00:00:00')->sum('profit_amount');
    $settlements=ORM::for_table('tbl_reseller_settlements')->where('reseller_id',$profile['id'])->order_by_desc('id')->limit(20)->find_array();
    $allowedPackages=ORM::for_table('tbl_reseller_packages')->table_alias('rp')->select_many('p.*')->join('tbl_plans',['rp.plan_id','=','p.id'],'p')->where('rp.reseller_id',$profile['id'])->where('p.enabled',1)->order_by_asc('p.name_plan')->find_many();
    $ui->assign('is_reseller_view',true);$ui->assign('reseller_screen','dashboard');$ui->assign('profile',$profile);$ui->assign('owned_customers',$customers);$ui->assign('allowed_packages',$allowedPackages);
    $ui->assign('owned_total',$total);$ui->assign('owned_online',$online);$ui->assign('owned_offline',$total-$online);$ui->assign('owned_expiring',$expiring);$ui->assign('owned_onu_total',$onuTotal);$ui->assign('owned_onu_online',$onuOnline);$ui->assign('owned_onus',$ownedOnus);
    $ui->assign('month_sales',$sales);$ui->assign('profit_share',max(0,$earned-$settled));$ui->assign('today_profit',$today);$ui->assign('month_profit',$month);$ui->assign('lifetime_profit',$earned);$ui->assign('total_settled',$settled);$ui->assign('recent_earnings',$earnings);$ui->assign('settlements',$settlements);
    $ui->display('admin/reseller/dashboard.tpl');return;
}
if(!$isAdmin)_alert('You do not have permission to access reseller management.','danger','dashboard');


if($action==='customers'){
    $resellerId=(int)($routes[2]??0);
    $selectedReseller=ORM::for_table('tbl_resellers')->find_one($resellerId);
    if(!$selectedReseller)r2(getUrl('reseller/resellers'),'e','Reseller was not found.');
    $_SESSION['admin_customer_scope_reseller']=$resellerId;
    $search=trim(_req('search'));
    $filter=_req('filter','all');
    $order=_req('order','username');
    $direction=strtolower(_req('orderby','asc'))==='desc'?'desc':'asc';
    $allowedOrders=['username','fullname','created_at','balance','status'];
    if(!in_array($order,$allowedOrders,true)&&$order!=='lastname')$order='username';
    $approval=_req('approval','all');
    $query=ORM::for_table('tbl_customers')->where('reseller_id',$resellerId);
    if($filter!=='all')$query->where('status',$filter);
    if(in_array($approval,['pending','approved','rejected'],true))$query->where('approval_status',$approval);
    if($search!==''){
        $like='%'.$search.'%';
        $query->where_raw('(username LIKE ? OR fullname LIKE ? OR address LIKE ? OR phonenumber LIKE ? OR email LIKE ?)',[$like,$like,$like,$like,$like]);
    }
    if($order==='lastname')$query->order_by_expr("SUBSTR(fullname, INSTR(fullname, ' ')) $direction");
    elseif($direction==='desc')$query->order_by_desc($order);else $query->order_by_asc($order);
    if(_post('export','')==='csv'){
        if(!Csrf::check(_post('csrf_token')))r2(getUrl('reseller/customers/').$resellerId,'e','Invalid or expired request token');
        header('Content-Type: text/csv');header('Content-Disposition: attachment;filename="reseller_'.$resellerId.'_customers_'.date('Y-m-d_H_i').'.csv"');
        $fp=fopen('php://output','wb');fputcsv($fp,['id','username','fullname','address','phone','email','balance','service_type']);
        foreach($query->find_many() as $c)fputcsv($fp,[$c['id'],$c['username'],$c['fullname'],str_replace("\n",' ',$c['address']),$c['phonenumber'],$c['email'],$c['balance'],$c['service_type']]);
        fclose($fp);exit;
    }
    $append='&order='.urlencode($order).'&filter='.urlencode($filter).'&orderby='.urlencode($direction).'&approval='.urlencode($approval);
    $rows=Paginator::findMany($query,['search'=>$search],30,$append);
    $ui->assign('d',$rows);$ui->assign('statuses',ORM::for_table('tbl_customers')->getEnum('status'));
    $ui->assign('filter',$filter);$ui->assign('search',$search);$ui->assign('order',$order);
    $positions=['username'=>0,'fullname'=>1,'lastname'=>1,'created_at'=>8,'balance'=>3,'status'=>7];
    $ui->assign('order_pos',$positions[$order]??0);$ui->assign('orderby',$direction);
    $ui->assign('csrf_token',Csrf::generateAndStoreToken());$ui->assign('customer_list_title',$selectedReseller['name'].' - Customers');
    $ui->assign('customer_list_url',Text::url('reseller/customers/').$resellerId);$ui->assign('reseller_customer_view',true);$ui->assign('approval_filter',$approval);
    $ui->assign('selected_reseller',$selectedReseller);$ui->display('admin/customers/list.tpl');return;
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!Csrf::check(_post('csrf_token')))r2(getUrl('reseller'),'e','Invalid or expired request token');
    if($action==='approve-customer'){
        $customer=ORM::for_table('tbl_customers')->find_one((int)_post('customer_id'));
        if(!$customer||empty($customer['reseller_id']))r2(getUrl('reseller/resellers'),'e','Reseller customer was not found.');
        $customer->approval_status='approved';$customer->save();
        _log('Admin approved reseller customer #'.$customer->id);
        r2(getUrl('reseller/customers/').$customer['reseller_id'].'&approval=pending','s','Customer approved successfully.');
    }
    if($action==='save'){
        /* A reseller is always backed by Arivo ISP Billing's native Agent user.  An
           administrator may select an existing Agent or create one here using
           the same validation and password hashing used by Settings > Users. */
        $mode=($_POST['account_mode']??'existing')==='new'?'new':'existing';
        $user=null;
        if($mode==='new'){
            $username=trim(_post('username'));
            $fullname=trim(_post('fullname'));
            $password=(string)_post('password');
            $message='';
            if(!Validator::Length($username,45,2))$message.='Username should be between 3 to 45 characters.<br>';
            if(!Validator::Length($fullname,45,2))$message.='Full name should be between 3 to 45 characters.<br>';
            if(!Validator::Length($password,1000,5))$message.='Password should be minimum 6 characters.<br>';
            if(ORM::for_table('tbl_users')->where('username',$username)->find_one())$message.='This username is already in use.<br>';
            if($message!=='')r2(getUrl('reseller/resellers'),'e',$message);
            $user=ORM::for_table('tbl_users')->create();
            $user->username=$username;
            $user->fullname=$fullname;
            $user->password=Password::_crypt($password);
            $user->user_type='Agent';
            $user->phone=trim(_post('phone'));
            $user->email=trim(_post('email'));
            $user->city=trim(_post('city'));
            $user->subdistrict=trim(_post('subdistrict'));
            $user->ward=trim(_post('ward'));
            $user->status='Active';
            $user->creationdate=date('Y-m-d H:i:s');
            $user->save();
            _log('['.$admin['username'].']: Created Agent '.$username.' for reseller management',$admin['user_type'],$admin['id']);
        }else{
            $user=ORM::for_table('tbl_users')->find_one((int)_post('user_id'));
            if(!$user||$user['user_type']!=='Agent')r2(getUrl('reseller/resellers'),'e','Select a valid Agent account for this reseller.');
        }
        $record=ORM::for_table('tbl_resellers')->where('user_id',$user['id'])->find_one();
        if(!$record){$record=ORM::for_table('tbl_resellers')->create();$record->user_id=$user['id'];$record->created_at=date('Y-m-d H:i:s');}
        $record->reseller_code=trim(_post('reseller_code'))?:strtolower($user['username']);$record->customer_prefix=preg_replace('/[^a-z0-9_-]/i','',trim(_post('customer_prefix'))?:$record->reseller_code);$record->name=trim(_post('name'))?:$user['fullname'];
        if($record->customer_prefix==='')r2(getUrl('reseller/resellers'),'e','Customer username suffix may contain only letters, numbers, underscore or hyphen.');
        $record->mobile=trim(_post('mobile'))?:$user['phone'];$record->email=trim(_post('email'))?:$user['email'];$record->profit_percentage=max(0,min(100,(float)_post('profit_percentage')));
        $record->status=in_array(_post('status'),['active','suspended','disabled'],true)?_post('status'):'active';$record->auto_approve_customers=isset($_POST['auto_approve_customers'])?1:0;$record->allow_sms=isset($_POST['allow_sms'])?1:0;$record->allow_whatsapp=isset($_POST['allow_whatsapp'])?1:0;$record->allow_email=isset($_POST['allow_email'])?1:0;$record->notes=trim(_post('notes'));$record->updated_at=date('Y-m-d H:i:s');$record->save();
        if(isset($_POST['package_ids'])){ORM::for_table('tbl_reseller_packages')->where('reseller_id',$record['id'])->delete_many();foreach((array)($_POST['package_ids']??[]) as $packageId){$package=ORM::for_table('tbl_plans')->where('id',(int)$packageId)->where('enabled',1)->find_one();if($package){$allow=ORM::for_table('tbl_reseller_packages')->create();$allow->reseller_id=$record->id;$allow->plan_id=$package->id;$allow->customer_price=(float)$package['price'];$allow->created_at=date('Y-m-d H:i:s');$allow->updated_at=date('Y-m-d H:i:s');$allow->save();}}}
        _log('Reseller saved #'.$record->id);r2(getUrl('reseller/resellers/').$user['id'],'s','Reseller profile saved.');
    }
    if($action==='assign'||$action==='unassign'){
        $customer=ORM::for_table('tbl_customers')->find_one((int)_post('customer_id'));if(!$customer)r2(getUrl('reseller'),'e','Customer was not found.');
        if($action==='unassign'){$old=(int)$customer->reseller_id;$customer->reseller_id=null;$customer->save();_log('Customer #'.$customer->id.' removed from reseller #'.$old);r2(getUrl('reseller'),'s','Customer is now unassigned.');}
        $record=ORM::for_table('tbl_resellers')->find_one((int)_post('reseller_id'));if(!$record||$record['status']!=='active')r2(getUrl('reseller'),'e','Choose an active reseller.');
        $customer->reseller_id=$record['id'];$customer->save();_log('Customer #'.$customer->id.' assigned reseller #'.$record->id);r2(getUrl('reseller'),'s','Customer assigned.');
    }
    if($action==='bulk-assign'){
        $record=ORM::for_table('tbl_resellers')->find_one((int)_post('reseller_id'));$customerIds=array_unique(array_filter(array_map('intval',(array)($_POST['customer_ids']??[]))));
        if(!$record||$record['status']!=='active'||!$customerIds)r2(getUrl('reseller'),'e','Select an active reseller and at least one customer.');
        $updated=0;foreach($customerIds as $customerId){$customer=ORM::for_table('tbl_customers')->find_one($customerId);if($customer){$customer->reseller_id=$record['id'];$customer->save();$updated++;}}
        _log('Bulk assigned '.$updated.' customers to reseller #'.$record->id);r2(getUrl('reseller'),'s',$updated.' customer(s) assigned to '.$record['name'].'.');
    }
    if($action==='ownership-bulk'){
        $customerIds=array_unique(array_filter(array_map('intval',(array)($_POST['customer_ids']??[]))));
        $operation=_post('operation');$targetId=(int)_post('reseller_id');
        if(!$customerIds)r2(getUrl('reseller/ownership'),'e','Select at least one customer.');
        $target=null;if($operation==='assign'){$target=ORM::for_table('tbl_resellers')->where('id',$targetId)->where('status','active')->find_one();if(!$target)r2(getUrl('reseller/ownership'),'e','Choose an active reseller.');}
        $updated=0;foreach($customerIds as $customerId){$customer=ORM::for_table('tbl_customers')->find_one($customerId);if(!$customer)continue;$customer->reseller_id=$operation==='unassign'?null:$targetId;$customer->save();$updated++;}
        _log('Ownership bulk '.$operation.' updated '.$updated.' customer(s)');r2(getUrl('reseller/ownership'),'s',$updated.' customer(s) updated successfully.');
    }
    if($action==='ownership-approve'){
        $customer=ORM::for_table('tbl_customers')->find_one((int)_post('customer_id'));
        if(!$customer||empty($customer['reseller_id']))r2(getUrl('reseller/ownership'),'e','Reseller customer was not found.');
        $customer->approval_status='approved';$customer->save();_log('Admin approved reseller customer #'.$customer->id);
        r2(getUrl('reseller/ownership'),'s','Customer approved successfully.');
    }
    if($action==='settle'){
        $record=ORM::for_table('tbl_resellers')->find_one((int)_post('reseller_id'));$amount=round((float)_post('amount'),2);if(!$record||$amount<=0)r2(getUrl('reseller'),'e','Enter a valid reseller and settlement amount.');
        $earned=(float)ORM::for_table('tbl_reseller_earnings')->where('reseller_id',$record['id'])->where('status','earned')->sum('profit_amount');$settled=(float)ORM::for_table('tbl_reseller_settlements')->where('reseller_id',$record['id'])->sum('amount');if($amount>$earned-$settled+0.0001)r2(getUrl('reseller'),'e','Settlement cannot exceed available profit.');
        $settlement=ORM::for_table('tbl_reseller_settlements')->create();$settlement->reseller_id=$record['id'];$settlement->amount=$amount;$settlement->payment_method=trim(_post('payment_method'))?:'Cash';$settlement->reference=trim(_post('reference'));$settlement->note=trim(_post('note'));$settlement->processed_by=$admin['id'];$settlement->created_at=date('Y-m-d H:i:s');$settlement->save();_log('Reseller settlement #'.$settlement->id);r2(getUrl('reseller'),'s','Settlement recorded.');
    }
    if($action==='packages'){
        $record=ORM::for_table('tbl_resellers')->find_one((int)_post('reseller_id'));if(!$record)r2(getUrl('reseller'),'e','Reseller was not found.');
        $customerPrice=max(0,(float)($_POST['customer_price']??0));$baseCost=max(0,(float)($_POST['base_cost']??0));$profitType=($_POST['profit_type']??'percentage')==='fixed'?'fixed':'percentage';$profitValue=max(0,(float)($_POST['profit_value']??0));if($customerPrice>0&&$baseCost>$customerPrice)r2(getUrl('reseller/packages'),'e','Base cost cannot exceed selling price.');
        ORM::for_table('tbl_reseller_packages')->where('reseller_id',$record['id'])->delete_many();foreach((array)($_POST['package_ids']??[]) as $packageId){$package=ORM::for_table('tbl_plans')->where('id',(int)$packageId)->where('enabled',1)->find_one();if($package){$selling=$customerPrice>0?$customerPrice:(float)$package['price'];$allow=ORM::for_table('tbl_reseller_packages')->create();$allow->reseller_id=$record->id;$allow->plan_id=$package->id;$allow->customer_price=$selling;$allow->base_cost=$baseCost;$allow->profit_type=$profitType;$allow->profit_value=$profitValue;$allow->created_at=date('Y-m-d H:i:s');$allow->updated_at=date('Y-m-d H:i:s');$allow->save();}}
        _log('Reseller package permissions updated #'.$record->id);r2(getUrl('reseller'),'s','Allowed packages updated.');
    }
}
$sql="SELECT r.*,u.username,u.fullname,COUNT(c.id) customer_count,COALESCE(SUM(c.status='Active'),0) active_count,COALESCE(SUM(c.approval_status='pending'),0) pending_count,COALESCE((SELECT SUM(t.price) FROM tbl_transactions t JOIN tbl_customers tc ON tc.id=t.user_id WHERE tc.reseller_id=r.id AND t.recharged_on>=DATE_FORMAT(CURDATE(),'%Y-%m-01')),0) month_sales FROM tbl_resellers r LEFT JOIN tbl_users u ON u.id=r.user_id LEFT JOIN tbl_customers c ON c.reseller_id=r.id GROUP BY r.id ORDER BY r.name";
$resellers=ORM::for_table('tbl_resellers')->raw_query($sql)->find_array();foreach($resellers as &$record){$earned=(float)ORM::for_table('tbl_reseller_earnings')->where('reseller_id',$record['id'])->where('status','earned')->sum('profit_amount');$settled=(float)ORM::for_table('tbl_reseller_settlements')->where('reseller_id',$record['id'])->sum('amount');$record['profit_share']=max(0,$earned-$settled);$record['lifetime_earnings']=$earned;$record['total_settled']=$settled;$record['offline_count']=$record['customer_count']-$record['active_count'];}unset($record);
$dashTotal=count($resellers);$dashActive=0;$dashCustomers=0;$dashActiveCustomers=0;$dashPending=0;$dashMonthSales=0;$dashProfitDue=0;foreach($resellers as $rr){if($rr['status']==='active')$dashActive++;$dashCustomers+=(int)$rr['customer_count'];$dashActiveCustomers+=(int)$rr['active_count'];$dashPending+=(int)$rr['pending_count'];$dashMonthSales+=(float)$rr['month_sales'];$dashProfitDue+=(float)$rr['profit_share'];}
$ui->assign('dash_total_resellers',$dashTotal);$ui->assign('dash_active_resellers',$dashActive);$ui->assign('dash_total_customers',$dashCustomers);$ui->assign('dash_active_customers',$dashActiveCustomers);$ui->assign('dash_pending_customers',$dashPending);$ui->assign('dash_month_sales',$dashMonthSales);$ui->assign('dash_profit_due',$dashProfitDue);$ui->assign('dash_active_percent',$dashCustomers>0?round($dashActiveCustomers/$dashCustomers*100):0);
if($action==='ownership'){
    $search=trim(_req('search'));$owner=_req('owner','all');$status=_req('status','all');$approval=_req('approval','all');
    $query=ORM::for_table('tbl_customers')->table_alias('c')->select('c.*')->select('r.name','reseller_name')->select('r.status','reseller_status')->left_outer_join('tbl_resellers',['c.reseller_id','=','r.id'],'r');
    if($owner==='main')$query->where_null('c.reseller_id');elseif($owner==='reseller')$query->where_not_null('c.reseller_id');elseif(ctype_digit((string)$owner))$query->where('c.reseller_id',(int)$owner);
    if($status!=='all')$query->where('c.status',$status);if($approval!=='all')$query->where('c.approval_status',$approval);
    if($search!==''){$like='%'.$search.'%';$query->where_raw('(c.username LIKE ? OR c.fullname LIKE ? OR c.phonenumber LIKE ? OR c.pppoe_username LIKE ?)',[$like,$like,$like,$like]);}
    $rows=$query->order_by_desc('c.id')->find_many();
    foreach($rows as $row){$active=ORM::for_table('tbl_user_recharges')->where('customer_id',$row['id'])->where('status','on')->order_by_desc('id')->find_one();$row->active_package=$active?$active['namebp']:'No active package';$row->package_expiry=$active?$active['expiration'].' '.$active['time']:'';}
    $mainCount=ORM::for_table('tbl_customers')->where_null('reseller_id')->count();$resellerCount=ORM::for_table('tbl_customers')->where_not_null('reseller_id')->count();$pendingCount=ORM::for_table('tbl_customers')->where_not_null('reseller_id')->where('approval_status','pending')->count();$activeCount=ORM::for_table('tbl_customers')->where_not_null('reseller_id')->where('status','Active')->count();
    $ui->assign('ownership_rows',$rows);$ui->assign('ownership_search',$search);$ui->assign('ownership_owner',$owner);$ui->assign('ownership_status',$status);$ui->assign('ownership_approval',$approval);$ui->assign('ownership_main_count',$mainCount);$ui->assign('ownership_reseller_count',$resellerCount);$ui->assign('ownership_pending_count',$pendingCount);$ui->assign('ownership_active_count',$activeCount);$ui->assign('statuses',ORM::for_table('tbl_customers')->getEnum('status'));$ui->assign('resellers',$resellers);$ui->assign('csrf_token',Csrf::generateAndStoreToken());$ui->display('admin/reseller/ownership.tpl');return;
}
$adminSections=['dashboard','resellers','ownership','packages','earnings','settlements','activity'];$adminSection=in_array($action,$adminSections,true)?$action:'dashboard';
$activity=[];try{$activity=ORM::for_table('tbl_reseller_activity_logs')->order_by_desc('id')->limit(100)->find_many();}catch(Throwable $e){}
$selectedAgent=null;$selectedProfile=null;$resellerForm=false;
if($action==='resellers'&&isset($routes[2])&&($routes[2]==='add'||ctype_digit((string)$routes[2]))){$resellerForm=true;if(ctype_digit((string)$routes[2])){$selectedAgent=ORM::for_table('tbl_users')->where('user_type','Agent')->find_one((int)$routes[2]);if($selectedAgent)$selectedProfile=ORM::for_table('tbl_resellers')->where('user_id',$selectedAgent['id'])->find_one();}}
$ui->assign('reseller_form',$resellerForm);
$ui->assign('is_reseller_view',false);$ui->assign('admin_section',$adminSection);$ui->assign('activity_logs',$activity);$ui->assign('resellers',$resellers);$ui->assign('agents',ORM::for_table('tbl_users')->where('user_type','Agent')->order_by_asc('fullname')->find_many());$ui->assign('plans',ORM::for_table('tbl_plans')->where('enabled',1)->order_by_asc('name_plan')->find_many());$ui->assign('customers',ORM::for_table('tbl_customers')->order_by_asc('fullname')->find_many());$ui->assign('selected_agent',$selectedAgent);$ui->assign('selected_profile',$selectedProfile);$ui->assign('csrf_token',Csrf::generateAndStoreToken());
if($action==='resellers'){$ui->display('admin/reseller/users.tpl');return;}
$ui->display('admin/reseller/dashboard.tpl');
