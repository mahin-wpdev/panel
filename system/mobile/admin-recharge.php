<?php
/** Authenticated Admin/SuperAdmin mobile manual recharge; no payment gateway charge. */
declare(strict_types=1);

function jm_mobile_recharge_is_admin(string $role): bool {
    return $role === 'admin';
}
function jm_mobile_recharge_admin(PDO $db, array $session): array {
    $identity = jm_app_actor($db, $session);
    if (!jm_mobile_recharge_is_admin((string)$identity['role'])) respond(403, ['error'=>'FORBIDDEN']);
    return $identity;
}
function jm_mobile_recharge_plan(PDO $db, int $customerId): ?array {
    return jm_mobile_query($db, "SELECT c.id,c.username,c.fullname,c.status,
        r.plan_id,r.routers,p.name_plan,p.price,p.type,p.validity,p.validity_unit
        FROM tbl_customers c
        LEFT JOIN tbl_user_recharges r ON r.id=(
            SELECT MAX(x.id) FROM tbl_user_recharges x
            WHERE x.customer_id=c.id AND x.routers NOT IN ('balance','Custom Balance'))
        LEFT JOIN tbl_plans p ON p.id=r.plan_id
        WHERE c.id=? LIMIT 1", [$customerId])->fetch(PDO::FETCH_ASSOC) ?: null;
}
function jm_mobile_recharge_search(PDO $db, array $session, string $query): array {
    jm_mobile_recharge_admin($db, $session);
    $query=trim($query);
    if (mb_strlen($query)<2 || mb_strlen($query)>80) respond(400,['error'=>'SEARCH_2_TO_80_CHARS']);
    $like='%'.$query.'%';
    $rows=jm_mobile_query($db, "SELECT c.id,c.username,c.fullname,c.status,
        r.plan_id,r.routers,p.name_plan,p.price,p.type,p.validity,p.validity_unit
        FROM tbl_customers c
        LEFT JOIN tbl_user_recharges r ON r.id=(
            SELECT MAX(x.id) FROM tbl_user_recharges x
            WHERE x.customer_id=c.id AND x.routers NOT IN ('balance','Custom Balance'))
        LEFT JOIN tbl_plans p ON p.id=r.plan_id
        WHERE c.username LIKE ? OR c.fullname LIKE ?
        ORDER BY c.id DESC LIMIT 25",[$like,$like])->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['can_recharge']=$row['status']==='Active'
            && (int)$row['plan_id']>0 && trim((string)$row['routers'])!=='';
    }
    unset($row);
    return ['available'=>true,'items'=>$rows,
        'note'=>'Only active accounts with an existing internet plan can be renewed. Manual recharge is not bKash payment verification.'];
}
function jm_mobile_recharge_verify_password(PDO $db, array $admin, string $password): void {
    $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
    $attemptKey=hash('sha256','mobile-recharge|'.$admin['id'].'|'.$ip);
    $attempt=jm_mobile_query($db,
        'SELECT * FROM tbl_mobile_auth_attempts WHERE attempt_key=?',
        [$attemptKey])->fetch(PDO::FETCH_ASSOC);
    if ($attempt && $attempt['blocked_until'] &&
        strtotime($attempt['blocked_until'])>time())
        respond(429,['error'=>'TOO_MANY_ATTEMPTS']);
    $hash=jm_mobile_query($db,
        'SELECT password FROM tbl_users WHERE id=? LIMIT 1',
        [$admin['id']])->fetchColumn();
    if (!$hash || !hash_equals((string)$hash,sha1($password))) {
        $count=$attempt && strtotime($attempt['window_start'])>time()-900 ?
            (int)$attempt['attempts']+1 : 1;
        jm_mobile_query($db,
            'INSERT INTO tbl_mobile_auth_attempts(attempt_key,attempts,window_start,blocked_until)
             VALUES(?,?,NOW(),?) ON DUPLICATE KEY UPDATE
             attempts=VALUES(attempts),
             window_start=IF(VALUES(attempts)=1,NOW(),window_start),
             blocked_until=VALUES(blocked_until)',
            [$attemptKey,$count,$count>=5?date('Y-m-d H:i:s',time()+900):null]);
        respond(403,['error'=>'ADMIN_PASSWORD_INCORRECT']);
    }
    jm_mobile_query($db,'DELETE FROM tbl_mobile_auth_attempts WHERE attempt_key=?',[$attemptKey]);
}
function jm_mobile_recharge_submit(PDO $db, array $session, array $input): array {
    $admin=jm_mobile_recharge_admin($db,$session);
    if (!jm_app_table($db,'tbl_mobile_admin_recharge_requests'))
        respond(503,['error'=>'RECHARGE_NOT_CONFIGURED']);
    $id=filter_var($input['customer_id']??null,FILTER_VALIDATE_INT,
        ['options'=>['min_range'=>1]]);
    $key=(string)($input['request_key']??'');
    $password=(string)($input['admin_password']??'');
    $ack=$input['payment_verified']??false;
    if (!$id || !preg_match('/^[a-f0-9]{32}$/D',$key) ||
        $ack!==true || strlen($password)>256 || $password==='')
        respond(400,['error'=>'INVALID_RECHARGE_REQUEST']);
    jm_mobile_recharge_verify_password($db,$admin,$password);
    $customer=jm_mobile_recharge_plan($db,(int)$id);
    if (!$customer || $customer['status']!=='Active' || !(int)$customer['plan_id'] ||
        trim((string)$customer['routers'])==='')
        respond(409,['error'=>'CUSTOMER_PLAN_UNAVAILABLE']);
    if ((string)($input['username']??'')!==$customer['username'])
        respond(409,['error'=>'CUSTOMER_CHANGED']);
    $existing=jm_mobile_query($db,
        'SELECT * FROM tbl_mobile_admin_recharge_requests WHERE request_key=?',
        [$key])->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        if ((int)$existing['actor_id']!==$admin['id'] ||
            (int)$existing['customer_id']!==(int)$id)
            respond(409,['error'=>'REQUEST_KEY_CONFLICT']);
        if ($existing['status']==='completed')
            return ['status'=>'completed','invoice'=>$existing['invoice'],'duplicate'=>true];
        respond(409,['error'=>'RECHARGE_CHECK_PANEL_BEFORE_RETRY']);
    }
    // Serialise requests per customer, including requests with different keys.
    $lock='jm_mobile_recharge_'.$id;
    if ((int)jm_mobile_query($db,'SELECT GET_LOCK(?, 0)',[$lock])->fetchColumn()!==1)
        respond(409,['error'=>'RECHARGE_ALREADY_PROCESSING']);
    try {
        $recent=jm_mobile_query($db,
            "SELECT 1 FROM tbl_mobile_admin_recharge_requests
             WHERE customer_id=? AND (status='pending' OR
                 created_at>DATE_SUB(NOW(),INTERVAL 60 SECOND))
             LIMIT 1",[$id])->fetchColumn();
        if ($recent) respond(409,['error'=>'RECENT_RECHARGE_CHECK_PANEL']);
        jm_mobile_query($db,
            "INSERT INTO tbl_mobile_admin_recharge_requests
             (request_key,actor_id,customer_id,plan_id,router,status,created_at)
             VALUES (?,?,?,?,?,'pending',NOW())",
            [$key,$admin['id'],$id,$customer['plan_id'],$customer['routers']]);
        // Legacy Package::rechargeUser also changes PPPoE, invoices and billing.
        // It is not atomic with our request table: uncertain failures MUST be reviewed.
        $GLOBALS['admin']=$admin['actor'];
        $invoice=Package::rechargeUser((int)$id,(string)$customer['routers'],
            (int)$customer['plan_id'],'Admin Manual',$admin['username'],
            'Admin-confirmed manual recharge via JM Broadband mobile app');
        if (!$invoice) respond(409,['error'=>'RECHARGE_CHECK_PANEL_BEFORE_RETRY']);
        jm_mobile_query($db,
            "UPDATE tbl_mobile_admin_recharge_requests
             SET status='completed',invoice=?,completed_at=NOW()
             WHERE request_key=?",[(string)$invoice,$key]);
        _log('Mobile admin '.$admin['username'].' recharged '.$customer['username'].
            ' ['.$invoice.']','Admin',$admin['id']);
        return ['status'=>'completed','invoice'=>(string)$invoice,'duplicate'=>false,
            'username'=>$customer['username']];
    } finally {
        jm_mobile_query($db,'SELECT RELEASE_LOCK(?)',[$lock]);
    }
}
