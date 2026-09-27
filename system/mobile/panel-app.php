<?php
/**
 * JM Broadband Mobile — read-only Panel-aligned endpoints.
 * Uses the authenticated actor in tbl_mobile_auth_sessions; never trusts a customer/reseller ID from the device.
 * No live router/OLT commands, payments, password fields, or financial mutations.
 */
declare(strict_types=1);

function jm_app_table(PDO $db, string $table): bool {
    static $cache = [];
    if (!preg_match('/^[a-z0-9_]+$/', $table)) return false;
    if (!array_key_exists($table, $cache)) {
        $cache[$table] = (bool) jm_mobile_query($db,
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1',
            [$table])->fetchColumn();
    }
    return $cache[$table];
}
function jm_app_column(PDO $db, string $table, string $column): bool {
    static $cache = [];
    $key = $table.'.'.$column;
    if (!preg_match('/^[a-z0-9_]+$/', $table) || !preg_match('/^[a-z0-9_]+$/', $column)) return false;
    if (!array_key_exists($key, $cache)) {
        $cache[$key] = (bool) jm_mobile_query($db,
            'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column])->fetchColumn();
    }
    return $cache[$key];
}
function jm_app_rows(PDO $db, string $sql, array $params=[]): array {
    return jm_mobile_query($db, $sql, $params)->fetchAll(PDO::FETCH_ASSOC);
}
function jm_app_num($n): float { return round((float)$n, 2); }
function jm_app_disabled(string $message): array {
    return ['available'=>false,'message'=>$message,'items'=>[]];
}
function jm_app_actor(PDO $db, array $session): array {
    $id = (int)$session['actor_id'];
    if ($session['actor_type'] === 'customer') {
        $actor = jm_mobile_query($db,
            'SELECT id, username, fullname, status, balance, pppoe_username FROM tbl_customers WHERE id=? LIMIT 1',
            [$id])->fetch(PDO::FETCH_ASSOC);
        if (!$actor || $actor['status'] !== 'Active') respond(403,['error'=>'ACCOUNT_DISABLED']);
        return ['role'=>'customer','id'=>$id,'name'=>$actor['fullname'],'username'=>$actor['username'],
            'actor'=>$actor,'reseller_id'=>null];
    }
    if ($session['actor_type'] !== 'staff') respond(403,['error'=>'FORBIDDEN']);
    $actor = jm_mobile_query($db,
        'SELECT id,username,fullname,user_type,status FROM tbl_users WHERE id=? LIMIT 1',
        [$id])->fetch(PDO::FETCH_ASSOC);
    if (!$actor || $actor['status'] !== 'Active') respond(403,['error'=>'ACCOUNT_DISABLED']);
    if ($actor['user_type']==='SuperAdmin' || $actor['user_type']==='Admin') {
        return ['role'=>'admin','id'=>$id,'name'=>$actor['fullname'],'username'=>$actor['username'],
            'actor'=>$actor,'reseller_id'=>null];
    }
    if ($actor['user_type']!=='Agent' || !jm_app_table($db,'tbl_resellers')) respond(403,['error'=>'FORBIDDEN']);
    $reseller = jm_mobile_query($db,
        'SELECT id,name,status,profit_percentage FROM tbl_resellers WHERE user_id=? LIMIT 1',
        [$id])->fetch(PDO::FETCH_ASSOC);
    if (!$reseller || $reseller['status']!=='active') respond(403,['error'=>'ACCOUNT_DISABLED']);
    return ['role'=>'reseller','id'=>$id,'name'=>$reseller['name'],'username'=>$actor['username'],
        'actor'=>$actor,'reseller_id'=>(int)$reseller['id'],'reseller'=>$reseller];
}
function jm_app_customer_scope(PDO $db, array $identity): ?array {
    if ($identity['role'] === 'customer') return ['c.id = ?',[$identity['id']]];
    if ($identity['role'] === 'admin') return ['1=1',[]];
    if ($identity['role'] === 'reseller') {
        if (!jm_app_column($db,'tbl_customers','reseller_id')) return null;
        return ['c.reseller_id = ?',[$identity['reseller_id']]];
    }
    return null;
}
function jm_app_home(PDO $db, array $identity): array {
    if ($identity['role']==='customer') {
        require_once __DIR__.'/customer-dashboard.php';
        $data=jm_mobile_customer_dashboard($db,$identity['id']);
        if (!$data) respond(403,['error'=>'ACCOUNT_DISABLED']);
        return ['available'=>true,'role'=>'customer','profile'=>$data['customer'],
            'package'=>$data['package'],'network'=>$data['network'],
            'monthly_usage'=>$data['monthly_usage'],
            'traffic_peak'=>$data['traffic_peak'],
            'demo'=>(bool)$data['demo']];
    }

    $scope=jm_app_customer_scope($db,$identity);
    if (!$scope) return jm_app_disabled('Reseller ownership mapping is not installed on this Panel.');
    [$where,$params]=$scope;
    $count=jm_mobile_query($db,
        "SELECT COUNT(*) total, COALESCE(SUM(c.status='Active'),0) active FROM tbl_customers c WHERE $where",
        $params)->fetch(PDO::FETCH_ASSOC);
    $summary=[
        'customers'=>(int)$count['total'],
        'active_customers'=>(int)$count['active'],
        'inactive_customers'=>(int)$count['total']-(int)$count['active'],
    ];

    if (jm_app_table($db,'tbl_transactions')) {
        $s=jm_mobile_query($db,
            "SELECT COALESCE(SUM(CAST(t.price AS DECIMAL(15,2))),0) gross FROM tbl_transactions t " .
            "JOIN tbl_customers c ON c.id=t.user_id WHERE $where AND t.recharged_on >= DATE_FORMAT(CURDATE(),'%Y-%m-01')",
            $params)->fetch(PDO::FETCH_ASSOC);
        $summary['monthly_recorded_sales_bdt']=jm_app_num($s['gross']??0);
    } else {
        $summary['monthly_recorded_sales_bdt']=null;
    }

    if ($identity['role']==='reseller') {
        $resellerId=(int)$identity['reseller_id'];
        $summary['reseller_name']=$identity['name'];
        $summary['profile_profit_percentage']=jm_app_num($identity['reseller']['profit_percentage']);
        $summary['expiring_7_days']=0;
        if (jm_app_table($db,'tbl_user_recharges')) {
            $summary['expiring_7_days']=(int)jm_mobile_query($db,
                "SELECT COUNT(*) FROM tbl_customers c WHERE $where AND EXISTS (
                    SELECT 1 FROM tbl_user_recharges ur
                    WHERE ur.customer_id=c.id AND ur.status='on'
                      AND ur.expiration BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 7 DAY)
                )",$params)->fetchColumn();
        }
        $summary['onu_total']=0;$summary['onu_online']=0;$summary['onu_offline']=0;
        if (jm_app_table($db,'tbl_onus') && jm_app_column($db,'tbl_onus','customer_id')) {
            $onu=jm_mobile_query($db,
                "SELECT COUNT(*) total,COALESCE(SUM(o.status='ONLINE'),0) online
                 FROM tbl_onus o JOIN tbl_customers c ON c.id=o.customer_id WHERE $where",
                $params)->fetch(PDO::FETCH_ASSOC);
            $summary['onu_total']=(int)$onu['total'];
            $summary['onu_online']=(int)$onu['online'];
            $summary['onu_offline']=(int)$onu['total']-(int)$onu['online'];
        }

        $earned=0.0;$settled=0.0;$today=0.0;$month=0.0;
        $recentEarnings=[];$settlements=[];$allowedPackages=[];
        if (jm_app_table($db,'tbl_reseller_earnings')) {
            $earned=(float)jm_mobile_query($db,
                "SELECT COALESCE(SUM(profit_amount),0) FROM tbl_reseller_earnings
                 WHERE reseller_id=? AND status='earned'",[$resellerId])->fetchColumn();
            $today=(float)jm_mobile_query($db,
                "SELECT COALESCE(SUM(profit_amount),0) FROM tbl_reseller_earnings
                 WHERE reseller_id=? AND created_at>=CONCAT(CURDATE(),' 00:00:00')",[$resellerId])->fetchColumn();
            $month=(float)jm_mobile_query($db,
                "SELECT COALESCE(SUM(profit_amount),0) FROM tbl_reseller_earnings
                 WHERE reseller_id=? AND created_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01 00:00:00')",[$resellerId])->fetchColumn();
            $recentEarnings=jm_app_rows($db,
                "SELECT e.created_at,e.recharge_amount,e.profit_percentage,e.profit_amount,
                        c.username,c.fullname,t.plan_name
                 FROM tbl_reseller_earnings e
                 LEFT JOIN tbl_customers c ON c.id=e.customer_id
                 LEFT JOIN tbl_transactions t ON t.id=e.recharge_id
                 WHERE e.reseller_id=? ORDER BY e.id DESC LIMIT 8",[$resellerId]);
        }
        if (jm_app_table($db,'tbl_reseller_settlements')) {
            $settled=(float)jm_mobile_query($db,
                'SELECT COALESCE(SUM(amount),0) FROM tbl_reseller_settlements WHERE reseller_id=?',
                [$resellerId])->fetchColumn();
            $settlements=jm_app_rows($db,
                'SELECT created_at,amount,payment_method,reference,note FROM tbl_reseller_settlements WHERE reseller_id=? ORDER BY id DESC LIMIT 8',
                [$resellerId]);
        }
        if (jm_app_table($db,'tbl_reseller_packages') && jm_app_table($db,'tbl_plans')) {
            $allowedPackages=jm_app_rows($db,
                "SELECT p.id,p.name_plan,p.type,p.price,p.validity,p.validity_unit
                 FROM tbl_reseller_packages rp JOIN tbl_plans p ON p.id=rp.plan_id
                 WHERE rp.reseller_id=? AND p.enabled=1 ORDER BY p.name_plan ASC LIMIT 20",[$resellerId]);
        }
        $summary['profit_payable_bdt']=jm_app_num(max(0,$earned-$settled));
        $summary['profit_today_bdt']=jm_app_num($today);
        $summary['profit_month_bdt']=jm_app_num($month);
        $summary['profit_lifetime_bdt']=jm_app_num($earned);
        $summary['total_settled_bdt']=jm_app_num($settled);
        $summary['allowed_packages'] = count($allowedPackages);
        return ['available'=>true,'role'=>'reseller','summary'=>$summary,
            'recent_earnings'=>$recentEarnings,'settlements'=>$settlements,
            'allowed_packages'=>$allowedPackages,
            'note'=>'Sales and profit figures are recorded Panel accounting values; they do not verify cash, bKash or Nagad settlement.'];
    }

    $summary['service_active_total']=$summary['active_customers'];
    $summary['service_inactive_total']=$summary['inactive_customers'];
    $summary['reseller_total']=0;$summary['reseller_active_total']=0;
    $summary['reseller_customer_total']=0;$summary['reseller_pending_customers']=0;
    $summary['reseller_profit_due_bdt']=0.0;$summary['reseller_month_profit_bdt']=0.0;
    $summary['reseller_month_sales_bdt']=0.0;
    $resellerOverview=[];
    if (jm_app_table($db,'tbl_resellers')) {
        $summary['reseller_total']=(int)jm_mobile_query($db,'SELECT COUNT(*) FROM tbl_resellers')->fetchColumn();
        $summary['reseller_active_total']=(int)jm_mobile_query($db,"SELECT COUNT(*) FROM tbl_resellers WHERE status='active'")->fetchColumn();
        if (jm_app_column($db,'tbl_customers','reseller_id')) {
            $summary['reseller_customer_total']=(int)jm_mobile_query($db,'SELECT COUNT(*) FROM tbl_customers WHERE reseller_id IS NOT NULL')->fetchColumn();
            if (jm_app_column($db,'tbl_customers','approval_status')) {
                $summary['reseller_pending_customers']=(int)jm_mobile_query($db,
                    "SELECT COUNT(*) FROM tbl_customers WHERE reseller_id IS NOT NULL AND approval_status='pending'")->fetchColumn();
            }
        }
        $monthSalesExpr=jm_app_table($db,'tbl_transactions')
            ? "COALESCE((SELECT SUM(t.price) FROM tbl_transactions t
                JOIN tbl_customers tc ON tc.id=t.user_id
                WHERE tc.reseller_id=r.id
                  AND t.recharged_on>=DATE_FORMAT(CURDATE(),'%Y-%m-01')),0)"
            : "0";
        $profitDueExpr=(jm_app_table($db,'tbl_reseller_earnings') && jm_app_table($db,'tbl_reseller_settlements'))
            ? "GREATEST(0,
                COALESCE((SELECT SUM(e.profit_amount) FROM tbl_reseller_earnings e
                    WHERE e.reseller_id=r.id AND e.status='earned'),0)
                - COALESCE((SELECT SUM(s.amount) FROM tbl_reseller_settlements s
                    WHERE s.reseller_id=r.id),0))"
            : "0";
        $resellerOverview=jm_app_rows($db,
            "SELECT r.id,r.name,r.status,r.profit_percentage,u.username,
                    COUNT(c.id) customer_count,
                    COALESCE(SUM(c.status='Active'),0) active_count,
                    $monthSalesExpr month_sales,
                    $profitDueExpr profit_due
             FROM tbl_resellers r
             LEFT JOIN tbl_users u ON u.id=r.user_id
             LEFT JOIN tbl_customers c ON c.reseller_id=r.id
             GROUP BY r.id ORDER BY r.name ASC LIMIT 20");
    }
    if (jm_app_table($db,'tbl_reseller_earnings')) {
        $earned=(float)jm_mobile_query($db,
            "SELECT COALESCE(SUM(profit_amount),0) FROM tbl_reseller_earnings WHERE status='earned'")->fetchColumn();
        $monthProfit=(float)jm_mobile_query($db,
            "SELECT COALESCE(SUM(profit_amount),0) FROM tbl_reseller_earnings
             WHERE created_at>=DATE_FORMAT(CURDATE(),'%Y-%m-01 00:00:00')")->fetchColumn();
        $settled=jm_app_table($db,'tbl_reseller_settlements') ? (float)jm_mobile_query($db,
            'SELECT COALESCE(SUM(amount),0) FROM tbl_reseller_settlements')->fetchColumn() : 0.0;
        $summary['reseller_profit_due_bdt']=jm_app_num(max(0,$earned-$settled));
        $summary['reseller_month_profit_bdt']=jm_app_num($monthProfit);
    }
    if (jm_app_table($db,'tbl_transactions') && jm_app_column($db,'tbl_customers','reseller_id')) {
        $summary['reseller_month_sales_bdt']=jm_app_num(jm_mobile_query($db,
            "SELECT COALESCE(SUM(CAST(t.price AS DECIMAL(15,2))),0)
             FROM tbl_transactions t JOIN tbl_customers c ON c.id=t.user_id
             WHERE c.reseller_id IS NOT NULL AND t.recharged_on>=DATE_FORMAT(CURDATE(),'%Y-%m-01')")->fetchColumn());
    }

    $summary['onu_total']=0;$summary['onu_online_total']=0;$summary['onu_offline_total']=0;
    $summary['onu_los_total']=0;$summary['onu_unassigned_total']=0;
    if (jm_app_table($db,'tbl_onus')) {
        $summary['onu_total']=(int)jm_mobile_query($db,'SELECT COUNT(*) FROM tbl_onus')->fetchColumn();
        $summary['onu_online_total']=(int)jm_mobile_query($db,"SELECT COUNT(*) FROM tbl_onus WHERE status='ONLINE'")->fetchColumn();
        $summary['onu_offline_total']=(int)jm_mobile_query($db,"SELECT COUNT(*) FROM tbl_onus WHERE status='OFFLINE'")->fetchColumn();
        $summary['onu_los_total']=(int)jm_mobile_query($db,"SELECT COUNT(*) FROM tbl_onus WHERE status='LOS'")->fetchColumn();
        if (jm_app_column($db,'tbl_onus','customer_id'))
            $summary['onu_unassigned_total']=(int)jm_mobile_query($db,'SELECT COUNT(*) FROM tbl_onus WHERE customer_id IS NULL')->fetchColumn();
    }
    $summary['olt_total']=0;$summary['olt_online_total']=0;
    $summary['olt_last_sync_status']='never';$summary['olt_last_sync_at']=null;
    if (jm_app_table($db,'tbl_olts')) {
        $summary['olt_total']=(int)jm_mobile_query($db,'SELECT COUNT(*) FROM tbl_olts')->fetchColumn();
        $summary['olt_online_total']=(int)jm_mobile_query($db,"SELECT COUNT(*) FROM tbl_olts WHERE status='Online'")->fetchColumn();
        if (jm_app_column($db,'tbl_olts','last_sync_at')) {
            $last=jm_mobile_query($db,
                'SELECT last_sync_at,last_sync_status FROM tbl_olts WHERE last_sync_at IS NOT NULL ORDER BY last_sync_at DESC LIMIT 1')
                ->fetch(PDO::FETCH_ASSOC);
            if ($last) {
                $summary['olt_last_sync_at']=$last['last_sync_at'];
                $summary['olt_last_sync_status']=$last['last_sync_status'] ?: 'unknown';
            }
        }
    }
    return ['available'=>true,'role'=>'admin','summary'=>$summary,
        'reseller_overview'=>$resellerOverview,
        'note'=>'Dashboard values mirror the Panel overview. Sales/profit values are recorded accounting totals, not independently verified payments.'];
}
function jm_app_customers(PDO $db, array $identity, string $query=''): array {
    if ($identity['role']==='customer') respond(403,['error'=>'FORBIDDEN']);
    $scope=jm_app_customer_scope($db,$identity);
    if (!$scope) return jm_app_disabled('This Panel has no customer-to-reseller mapping.');
    [$where,$params]=$scope;
    $query=trim($query);
    if (mb_strlen($query)>80) respond(400,['error'=>'SEARCH_TOO_LONG']);
    if ($query!=='') {
        $where.=" AND (c.username LIKE ? OR c.fullname LIKE ? OR c.pppoe_username LIKE ? OR c.phonenumber LIKE ?)";
        $like='%'.$query.'%';
        array_push($params,$like,$like,$like,$like);
    }
    $rows=jm_app_rows($db,"SELECT c.id,c.username,c.fullname,c.status,c.pppoe_username FROM tbl_customers c WHERE $where ORDER BY c.id DESC LIMIT 60",$params);
    /* Monthly RADIUS usage is loaded on demand in the profile, not the list. */
    return ['available'=>true,'items'=>$rows,
        'note'=>'Showing up to 60 matching customers. Search by name, username, PPPoE or phone; details and RADIUS usage are available in Profile.'];
}
function jm_app_sales(PDO $db, array $identity): array {
    if (!jm_app_table($db,'tbl_transactions')) return jm_app_disabled('Transactions table is unavailable.');
    $scope=jm_app_customer_scope($db,$identity);
    if (!$scope) return jm_app_disabled('Reseller ownership mapping is unavailable.');
    [$where,$params]=$scope;
    $sql="SELECT t.id,t.invoice,t.plan_name,t.price,t.recharged_on,t.expiration,t.method,".
        "c.username FROM tbl_transactions t JOIN tbl_customers c ON c.id=t.user_id " .
        "WHERE $where ORDER BY t.id DESC LIMIT 60";
    return ['available'=>true,'items'=>jm_app_rows($db,$sql,$params),
        'note'=>'Recorded transactions only. This screen cannot initiate recharge, payment or refunds.'];
}
function jm_app_onus(PDO $db, array $identity): array {
    if (!jm_app_table($db,'tbl_onus') || !jm_app_column($db,'tbl_onus','customer_id'))
        return jm_app_disabled('This Panel has no compatible synced ONU inventory.');
    $scope=jm_app_customer_scope($db,$identity);
    if (!$scope) return jm_app_disabled('Reseller ownership mapping is unavailable.');
    [$where,$params]=$scope;
    // Select only columns verified in this database; do not assume every OLT integration has the same schema.
    $select=['o.id','c.username'];
    foreach (['status','rx_power','tx_power','last_seen','mac_address','pon_port','onu_id'] as $column) {
        if (jm_app_column($db,'tbl_onus',$column)) $select[]='o.'.$column;
    }
    $rows=jm_app_rows($db,'SELECT '.implode(',',$select).' FROM tbl_onus o JOIN tbl_customers c ON c.id=o.customer_id '.
        "WHERE $where ORDER BY o.id DESC LIMIT 60",$params);
    return ['available'=>true,'items'=>$rows,
        'note'=>'Last synced Panel inventory, not a live OLT query. Empty or outdated optical values mean unavailable.'];
}
function jm_app_inbox(PDO $db, array $identity): array {
    if ($identity['role']!=='customer') respond(403,['error'=>'FORBIDDEN']);
    if (!jm_app_table($db,'tbl_customers_inbox')) return jm_app_disabled('Customer inbox is unavailable.');
    return ['available'=>true,'items'=>jm_app_rows($db,
        'SELECT id,date_created,subject,body FROM tbl_customers_inbox WHERE customer_id=? ORDER BY id DESC LIMIT 40',
        [$identity['id']])];
}
function jm_app_plans(PDO $db, array $identity): array {
    if ($identity['role']!=='admin') respond(403,['error'=>'FORBIDDEN']);
    if (!jm_app_table($db,'tbl_plans')) return jm_app_disabled('Plans table is unavailable.');
    return ['available'=>true,'items'=>jm_app_rows($db,
        'SELECT id,name_plan,price,type,validity,validity_unit,enabled FROM tbl_plans ORDER BY id DESC LIMIT 60'),
        'note'=>'Read-only global packages. Reseller-specific package prices are not represented by this schema.'];
}
function jm_app_resellers(PDO $db, array $identity): array {
    if ($identity['role']!=='admin') respond(403,['error'=>'FORBIDDEN']);
    if (!jm_app_table($db,'tbl_resellers')) return jm_app_disabled('Reseller profiles are not installed on this Panel database.');
    $rows=jm_app_rows($db,'SELECT r.id,r.name,r.reseller_code,r.status,r.profit_percentage,u.username '.
        'FROM tbl_resellers r LEFT JOIN tbl_users u ON u.id=r.user_id ORDER BY r.id DESC LIMIT 60');
    return ['available'=>true,'items'=>$rows,
        'note'=>'Configured percentage is not verified reseller/admin profit or settlement.'];
}
function jm_app_routers(PDO $db, array $identity): array {
    if ($identity['role']!=='admin') respond(403,['error'=>'FORBIDDEN']);
    if (!jm_app_table($db,'tbl_routers')) return jm_app_disabled('Routers table is unavailable.');
    // No router IP, management username, or credentials leave the server.
    return ['available'=>true,'items'=>jm_app_rows($db,
        'SELECT id,name,status,enabled,last_seen FROM tbl_routers ORDER BY id DESC LIMIT 60'),
        'note'=>'Panel inventory status; no direct MikroTik connection is performed.'];
}
function jm_mobile_app_data(PDO $db, array $session, string $section): array {
    $identity=jm_app_actor($db,$session);
    $allowed=['home','customers','sales','onus','inbox','plans','resellers','routers'];
    if (!in_array($section,$allowed,true)) respond(404,['error'=>'UNKNOWN_ENDPOINT']);
    return match ($section) {
        'home'=>jm_app_home($db,$identity),
        'customers'=>jm_app_customers($db,$identity,(string)($_GET['q']??'')),
        'sales'=>jm_app_sales($db,$identity),
        'onus'=>jm_app_onus($db,$identity),
        'inbox'=>jm_app_inbox($db,$identity),
        'plans'=>jm_app_plans($db,$identity),
        'resellers'=>jm_app_resellers($db,$identity),
        'routers'=>jm_app_routers($db,$identity),
    };
}
