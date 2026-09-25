<?php
/** Admin-only, read-only customer operations for JM Broadband mobile. */
declare(strict_types=1);

function jm_mobile_admin_profile(PDO $db, array $session, int $id): array {
    jm_mobile_recharge_admin($db, $session);
    if ($id < 1) respond(400, ['error'=>'INVALID_CUSTOMER']);
    $customer = jm_mobile_query($db, "SELECT c.id,c.username,c.fullname,c.status,
        c.phonenumber,c.address,c.pppoe_username,c.balance,
        r.id AS recharge_id,r.plan_id,r.namebp,r.routers,
        r.expiration,r.time,r.status AS service_status,
        p.name_plan,p.price,p.type,p.validity,p.validity_unit
        FROM tbl_customers c
        LEFT JOIN tbl_user_recharges r ON r.id=(
            SELECT MAX(x.id) FROM tbl_user_recharges x
            WHERE x.customer_id=c.id AND x.routers NOT IN ('balance','Custom Balance'))
        LEFT JOIN tbl_plans p ON p.id=r.plan_id
        WHERE c.id=? LIMIT 1", [$id])->fetch(PDO::FETCH_ASSOC);
    if (!$customer) respond(404,['error'=>'CUSTOMER_NOT_FOUND']);
    $history = jm_mobile_query($db, "SELECT invoice,plan_name,price,method,
        recharged_on,recharged_time,expiration,time,routers
        FROM tbl_transactions WHERE user_id=?
        ORDER BY id DESC LIMIT 25",[$id])->fetchAll(PDO::FETCH_ASSOC);
    $requests = [];
    if (jm_app_table($db,'tbl_mobile_admin_recharge_requests')) {
        $requests = jm_mobile_query($db, "SELECT q.status,q.invoice,
            q.created_at,q.completed_at,q.plan_id,p.name_plan,
            u.username AS admin_username
            FROM tbl_mobile_admin_recharge_requests q
            LEFT JOIN tbl_plans p ON p.id=q.plan_id
            LEFT JOIN tbl_users u ON u.id=q.actor_id
            WHERE q.customer_id=? ORDER BY q.created_at DESC LIMIT 20",
            [$id])->fetchAll(PDO::FETCH_ASSOC);
    }
    $onu = null;
    if (jm_app_table($db,'tbl_onus') &&
        jm_app_column($db,'tbl_onus','customer_id')) {
        $select = ['id'];
        foreach (['status','rx_power','tx_power','last_seen',
                  'mac_address','pon_port','onu_id'] as $column) {
            if (jm_app_column($db,'tbl_onus',$column)) $select[]=$column;
        }
        $onu = jm_mobile_query($db,
            'SELECT '.implode(',',$select).
            ' FROM tbl_onus WHERE customer_id=? ORDER BY id DESC LIMIT 1',
            [$id])->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    require_once __DIR__.'/monthly-usage.php';
    $pppoe = (string)($customer['pppoe_username'] ?: $customer['username']);
    return ['available'=>true,'customer'=>$customer,
        'monthly_usage'=>jm_mobile_monthly_view($db,$pppoe),
        'onu'=>$onu,
        'transactions'=>$history,'admin_recharge_requests'=>$requests,
        'note'=>'Recorded invoices are not independent proof of payment. '
          .'No router credentials or customer passwords are returned.'];
}

function jm_mobile_admin_expiry(PDO $db, array $session, string $window): array {
    jm_mobile_recharge_admin($db,$session);
    if (!in_array($window,['today','3','7','overdue'],true))
        respond(400,['error'=>'INVALID_EXPIRY_WINDOW']);
    $days = ['today'=>1,'3'=>4,'7'=>8][$window] ?? 0;
    $condition = $window==='overdue'
        ? "TIMESTAMP(r.expiration,r.time) < NOW()"
        : "TIMESTAMP(r.expiration,r.time) >= NOW()
           AND TIMESTAMP(r.expiration,r.time) <
           DATE_ADD(CURDATE(), INTERVAL $days DAY)";
    $statusClause = $window==='overdue' ? '' : "AND r.status='on'";
    $where = "c.status='Active' $statusClause
        AND r.routers NOT IN ('balance','Custom Balance')
        AND $condition";
    $join = " FROM tbl_customers c
        JOIN tbl_user_recharges r ON r.id=(
            SELECT MAX(x.id) FROM tbl_user_recharges x
            WHERE x.customer_id=c.id
              AND x.routers NOT IN ('balance','Custom Balance'))
        LEFT JOIN tbl_plans p ON p.id=r.plan_id ";
    $total = (int)jm_mobile_query($db,
        "SELECT COUNT(*) ".$join." WHERE ".$where)->fetchColumn();
    $items = jm_mobile_query($db,
        "SELECT c.id,c.username,c.fullname,c.status,
           r.plan_id,r.namebp,r.routers,r.expiration,r.time,
           p.name_plan,p.price ".$join." WHERE ".$where."
           ORDER BY r.expiration ASC,r.time ASC,c.id ASC LIMIT 150")
        ->fetchAll(PDO::FETCH_ASSOC);
    return ['available'=>true,'window'=>$window,'count'=>$total,
        'items'=>$items,'truncated'=>$total>count($items),
        'note'=>'Based on the latest recorded service expiry, not live '
            .'PPPoE connectivity; disabled/banned accounts are excluded.'];
}
