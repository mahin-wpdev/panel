<?php

/**
 *  Arivo ISP Billing
 *  Maintainer: Mustafizur Rahman Mahin
 *  Upstream: https://github.com/hotspotbilling/phpnuxbill
 **/

_admin();
$ui->assign('_title', Lang::T('Dashboard'));
$ui->assign('_admin', $admin);

// Agent accounts that have an active reseller profile always land on their
// restricted reseller dashboard instead of the global administrative widgets.
if ($admin['user_type'] === 'Agent') {
    $agentReseller = ORM::for_table('tbl_resellers')->where('user_id', $admin['id'])->where('status', 'active')->find_one();
    if ($agentReseller) {
        r2(getUrl('reseller'));
    }
}

if (isset($_GET['refresh'])) {
    $files = scandir($CACHE_PATH);
    foreach ($files as $file) {
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        if (is_file($CACHE_PATH . DIRECTORY_SEPARATOR . $file) && $ext == 'temp') {
            unlink($CACHE_PATH . DIRECTORY_SEPARATOR . $file);
        }
    }
    r2(getUrl('dashboard'), 's', 'Data Refreshed');
}

$tipeUser = _req("user");
if (empty($tipeUser)) {
    $tipeUser = 'Admin';
}
$ui->assign('tipeUser', $tipeUser);

$reset_day = $config['reset_day'];
if (empty($reset_day)) {
    $reset_day = 1;
}
//first day of month
if (date("d") >= $reset_day) {
    $start_date = date('Y-m-' . $reset_day);
} else {
    $start_date = date('Y-m-' . $reset_day, strtotime("-1 MONTH"));
}

$current_date = date('Y-m-d');
$ui->assign('start_date', $start_date);
$ui->assign('current_date', $current_date);

$tipeUser = $admin['user_type'];
if (in_array($tipeUser, ['SuperAdmin', 'Admin'])) {
    $tipeUser = 'Admin';
    try {
        $ui->assign('olt_total', ORM::for_table('tbl_olts')->count());
        $ui->assign('olt_online_total', ORM::for_table('tbl_olts')->where('status', 'Online')->count());
        $lastOltSync = ORM::for_table('tbl_olts')->where_not_null('last_sync_at')->order_by_desc('last_sync_at')->find_one();
        $ui->assign('olt_last_sync_status', $lastOltSync ? ($lastOltSync['last_sync_status'] ?: 'unknown') : 'never');
        $ui->assign('olt_last_sync_at', $lastOltSync ? $lastOltSync['last_sync_at'] : null);
        $ui->assign('onu_total', ORM::for_table('tbl_onus')->count());
        $ui->assign('onu_online_total', ORM::for_table('tbl_onus')->where('status', 'ONLINE')->count());
        $ui->assign('onu_offline_total', ORM::for_table('tbl_onus')->where('status', 'OFFLINE')->count());
        $ui->assign('onu_los_total', ORM::for_table('tbl_onus')->where('status', 'LOS')->count());
        $ui->assign('onu_unassigned_total', ORM::for_table('tbl_onus')->where_null('customer_id')->count());
    } catch (Throwable $e) {
        $ui->assign('olt_total', 0); $ui->assign('onu_total', 0);
        $ui->assign('olt_online_total', 0); $ui->assign('onu_online_total', 0);
        $ui->assign('olt_last_sync_status', 'never'); $ui->assign('olt_last_sync_at', null);
        $ui->assign('onu_offline_total', 0); $ui->assign('onu_los_total', 0);
        $ui->assign('onu_unassigned_total', 0);
    }
    try {
        $resellerTotal = ORM::for_table('tbl_resellers')->count();
        $resellerActive = ORM::for_table('tbl_resellers')->where('status', 'active')->count();
        $resellerCustomers = ORM::for_table('tbl_customers')->where_not_null('reseller_id')->count();
        $serviceActive = ORM::for_table('tbl_customers')->where('status', 'Active')->count();
        $serviceInactive = ORM::for_table('tbl_customers')->where_not_equal('status', 'Active')->count();
        $earned = (float) ORM::for_table('tbl_reseller_earnings')->where('status', 'earned')->sum('profit_amount');
        $settled = (float) ORM::for_table('tbl_reseller_settlements')->sum('amount');
        $monthProfit = (float) ORM::for_table('tbl_reseller_earnings')
            ->where_gte('created_at', date('Y-m-01') . ' 00:00:00')->sum('profit_amount');
        $ui->assign('reseller_total', $resellerTotal);
        $ui->assign('reseller_active_total', $resellerActive);
        $ui->assign('reseller_customer_total', $resellerCustomers);
        $ui->assign('reseller_profit_due', max(0, $earned - $settled));
        $ui->assign('reseller_month_profit', $monthProfit);
        $ui->assign('service_active_total', $serviceActive);
        $ui->assign('service_inactive_total', $serviceInactive);
    } catch (Throwable $e) {
        $ui->assign('reseller_total', 0); $ui->assign('reseller_active_total', 0);
        $ui->assign('reseller_customer_total', 0); $ui->assign('reseller_profit_due', 0);
        $ui->assign('reseller_month_profit', 0); $ui->assign('service_active_total', 0);
        $ui->assign('service_inactive_total', 0);
    }
}

$widgets = ORM::for_table('tbl_widgets')->where("enabled", 1)->where('user', $tipeUser)->order_by_asc("orders")->findArray();
$count = count($widgets);
for ($i = 0; $i < $count; $i++) {
    try{
        if(file_exists($WIDGET_PATH . DIRECTORY_SEPARATOR . $widgets[$i]['widget'].".php")){
            require_once $WIDGET_PATH . DIRECTORY_SEPARATOR . $widgets[$i]['widget'].".php";
            $widgets[$i]['content'] = (new $widgets[$i]['widget'])->getWidget($widgets[$i]);
        }else{
            $widgets[$i]['content'] = "Widget not found";
        }
    } catch (Throwable $e) {
        $widgets[$i]['content'] = $e->getMessage();
    }
}

$ui->assign('widgets', $widgets);
run_hook('view_dashboard'); #HOOK
$ui->display('admin/dashboard.tpl');
