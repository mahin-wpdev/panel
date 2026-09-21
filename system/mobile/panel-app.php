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
        // This data is already restricted to the authenticated customer ID.
        return ['available'=>true,'role'=>'customer','profile'=>$data['customer'],
            'package'=>$data['package'],'network'=>$data['network'],
            'monthly_usage'=>$data['monthly_usage'],
            'demo'=>(bool)$data['demo']];
    }
    $scope=jm_app_customer_scope($db,$identity);
    if (!$scope) return jm_app_disabled('Reseller ownership mapping is not installed on this Panel.');
    [$where,$params]=$scope;
    $count=jm_mobile_query($db,
        "SELECT COUNT(*) total, COALESCE(SUM(c.status='Active'),0) active FROM tbl_customers c WHERE $where",
        $params)->fetch(PDO::FETCH_ASSOC);
    $gross=null;
    if (jm_app_table($db,'tbl_transactions')) {
        $s=jm_mobile_query($db,
            "SELECT COALESCE(SUM(CAST(t.price AS DECIMAL(15,2))),0) gross FROM tbl_transactions t " .
            "JOIN tbl_customers c ON c.id=t.user_id WHERE $where AND t.recharged_on >= DATE_FORMAT(CURDATE(),'%Y-%m-01')",
            $params)->fetch(PDO::FETCH_ASSOC);
        $gross=jm_app_num($s['gross']??0);
    }
    $summary=['customers'=>(int)$count['total'],'active_customers'=>(int)$count['active'],
        'monthly_recorded_sales_bdt'=>$gross];
    if ($identity['role']==='reseller') {
        $summary['reseller_name']=$identity['name'];
        $summary['profile_profit_percentage']=jm_app_num($identity['reseller']['profit_percentage']);
        $summary['profit_note']='Profile percentage only; settled/net profit is unavailable without audited accounting data.';
    } else {
        $summary['reseller_profiles']=jm_app_table($db,'tbl_resellers') ?
            (int)jm_mobile_query($db,'SELECT COUNT(*) FROM tbl_resellers')->fetchColumn() : null;
    }
    return ['available'=>true,'role'=>$identity['role'],'summary'=>$summary,
        'note'=>'Monthly sales are totals of recorded transactions; not verified payments, cash balance, or net profit.'];
}
function jm_app_customers(PDO $db, array $identity): array {
    if ($identity['role']==='customer') respond(403,['error'=>'FORBIDDEN']);
    $scope=jm_app_customer_scope($db,$identity);
    if (!$scope) return jm_app_disabled('This Panel has no customer-to-reseller mapping.');
    [$where,$params]=$scope;
    $rows=jm_app_rows($db,"SELECT c.id,c.username,c.fullname,c.status,c.pppoe_username FROM tbl_customers c WHERE $where ORDER BY c.id DESC LIMIT 60",$params);
    return ['available'=>true,'items'=>$rows,'note'=>'Customer records are scoped on the server. No PPPoE passwords are returned.'];
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
        'customers'=>jm_app_customers($db,$identity),
        'sales'=>jm_app_sales($db,$identity),
        'onus'=>jm_app_onus($db,$identity),
        'inbox'=>jm_app_inbox($db,$identity),
        'plans'=>jm_app_plans($db,$identity),
        'resellers'=>jm_app_resellers($db,$identity),
        'routers'=>jm_app_routers($db,$identity),
    };
}
