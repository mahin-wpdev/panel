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
        r.plan_id,r.routers,p.name_plan,p.price,p.type,p.validity,p.validity_unit,p.device,p.prepaid
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
        r.plan_id,r.routers,p.name_plan,p.price,p.type,p.validity,p.validity_unit,p.device,p.prepaid
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
function jm_mobile_recharge_plan_eligible(array $current, array $plan): bool {
    return ($current['status'] ?? '') === 'Active'
        && (int)($current['plan_id'] ?? 0) > 0
        && (int)($plan['enabled'] ?? 0) === 1
        && (string)($plan['routers'] ?? '') === (string)($current['routers'] ?? '')
        && in_array((string)($plan['type'] ?? ''), ['PPPOE','Hotspot'], true)
        && (string)$plan['type'] === (string)($current['type'] ?? '')
        && (string)($plan['device'] ?? '') === (string)($current['device'] ?? '')
        && (string)($plan['prepaid'] ?? '') === (string)($current['prepaid'] ?? '');
}
function jm_mobile_recharge_options(PDO $db, array $session, int $customerId): array {
    jm_mobile_recharge_admin($db,$session);
    if ($customerId < 1) respond(400,['error'=>'INVALID_CUSTOMER']);
    $current=jm_mobile_recharge_plan($db,$customerId);
    if (!$current || $current['status']!=='Active' || !(int)$current['plan_id'])
        respond(409,['error'=>'CUSTOMER_PLAN_UNAVAILABLE']);
    $rows=jm_mobile_query($db,
        "SELECT id,name_plan,price,type,routers,device,prepaid,validity,validity_unit,enabled
         FROM tbl_plans WHERE enabled=1 AND routers=? AND type=? AND device=?
         AND prepaid=? ORDER BY price ASC,id ASC LIMIT 100",
        [$current['routers'],$current['type'],$current['device'],$current['prepaid']])
        ->fetchAll(PDO::FETCH_ASSOC);
    return ['available'=>true,'customer'=>$current,
        'items'=>array_values(array_filter($rows,
            static fn($plan)=>jm_mobile_recharge_plan_eligible($current,$plan))),
        'note'=>'Available internet packages for this customer/router. Amount may also include configured invoices, tax or additional bills.'];
}
function jm_mobile_recharge_calculate_preview(int $customerId, array $plan): array {
    // Match Package::rechargeUser's existing-record branch; do not charge anything.
    [$bills,$extra] = User::getBills($customerId);
    $price = (float)$plan['price'];
    $override = null;
    $base = $price;
    if ((string)$plan['validity_unit'] === 'Period') {
        $invoice = User::getAttribute('Invoice',$customerId);
        if (is_numeric($invoice) && (float)$invoice != 0.0) {
            $base = (float)$invoice;
            $override = $base;
        }
    }
    $amount = round($base + (float)$extra,2);
    return ['package_price_bdt'=>number_format($price,2,'.',''),
        'period_invoice_override_bdt'=>$override===null?null:number_format($override,2,'.',''),
        'additional_bills_bdt'=>number_format((float)$extra,2,'.',''),
        'bills'=>$bills,
        'expected_recorded_amount_bdt'=>number_format($amount,2,'.',''),
        'note'=>'Estimate of the phpNuxBill transaction amount before recharge; '
            .'no payment is collected or verified here. Confirm any separate tax, '
            .'reseller settlement or outstanding payment independently.'];
}
function jm_mobile_recharge_preview(PDO $db, array $session, int $id, int $planId): array {
    jm_mobile_recharge_admin($db,$session);
    if ($id<1 || $planId<1) respond(400,['error'=>'INVALID_PREVIEW_REQUEST']);
    $current=jm_mobile_recharge_plan($db,$id);
    $plan=jm_mobile_query($db,'SELECT id,name_plan,price,type,routers,device,prepaid,enabled,validity_unit
        FROM tbl_plans WHERE id=? LIMIT 1',[$planId])->fetch(PDO::FETCH_ASSOC);
    if (!$current || !$plan || !jm_mobile_recharge_plan_eligible($current,$plan))
        respond(409,['error'=>'SELECTED_PLAN_NOT_ALLOWED']);
    return ['available'=>true,'customer_id'=>$id,'plan_id'=>$planId,
        'current_plan_id'=>(int)$current['plan_id'],
        'plan_name'=>$plan['name_plan'],
        'preview'=>jm_mobile_recharge_calculate_preview($id,$plan)];
}
function jm_mobile_recharge_recover(PDO $db, array $request): ?string {
    if (($request['status']??'')==='completed' && !empty($request['invoice']))
        return (string)$request['invoice'];
    if (empty($request['created_at'])) return null;
    $invoice=jm_mobile_query($db,"SELECT t.invoice
        FROM tbl_transactions t
        JOIN tbl_plans p ON p.id=?
        JOIN tbl_users u ON u.id=?
        WHERE t.user_id=? AND t.admin_id=?
          AND t.plan_name=p.name_plan
          AND t.method=CONCAT('Admin Manual - ',u.username)
          AND TIMESTAMP(t.recharged_on,t.recharged_time)>=
              DATE_SUB(?,INTERVAL 2 MINUTE)
        ORDER BY t.id DESC LIMIT 1",
        [(int)$request['plan_id'],(int)$request['actor_id'],
         (int)$request['customer_id'],(int)$request['actor_id'],
         (string)$request['created_at']])->fetchColumn();
    if (!$invoice) return null;
    jm_mobile_query($db,"UPDATE tbl_mobile_admin_recharge_requests
        SET status='completed',invoice=?,completed_at=COALESCE(completed_at,NOW())
        WHERE request_key=?",[(string)$invoice,(string)$request['request_key']]);
    return (string)$invoice;
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
    $selectedPlan=filter_var($input['plan_id']??null,FILTER_VALIDATE_INT,
        ['options'=>['min_range'=>1]]);
    $expectedPlan=filter_var($input['expected_plan_id']??null,FILTER_VALIDATE_INT,
        ['options'=>['min_range'=>1]]);
    if (!$id || !$selectedPlan || !$expectedPlan || !preg_match('/^[a-f0-9]{32}$/D',$key) ||
        $ack!==true || strlen($password)>256 || $password==='')
        respond(400,['error'=>'INVALID_RECHARGE_REQUEST']);
    jm_mobile_recharge_verify_password($db,$admin,$password);
    $customer=jm_mobile_recharge_plan($db,(int)$id);
    if (!$customer || $customer['status']!=='Active' || !(int)$customer['plan_id'] ||
        trim((string)$customer['routers'])==='')
        respond(409,['error'=>'CUSTOMER_PLAN_UNAVAILABLE']);
    if ((string)($input['username']??'')!==$customer['username'])
        respond(409,['error'=>'CUSTOMER_CHANGED']);
    if ((int)$customer['plan_id']!==(int)$expectedPlan)
        respond(409,['error'=>'PLAN_CHANGED_REFRESH_REQUIRED']);
    $plan=jm_mobile_query($db,'SELECT id,name_plan,price,type,routers,device,prepaid,enabled,validity_unit FROM tbl_plans WHERE id=? LIMIT 1',[$selectedPlan])->fetch(PDO::FETCH_ASSOC);
    if (!$plan || !jm_mobile_recharge_plan_eligible($customer,$plan))
        respond(409,['error'=>'SELECTED_PLAN_NOT_ALLOWED']);
    $expectedAmount=(string)($input['expected_preview_amount']??'');
    if ($expectedAmount!=='' && (!preg_match('/^[0-9]+\\.[0-9]{2}$/D',$expectedAmount) ||
        jm_mobile_recharge_calculate_preview((int)$id,$plan)['expected_recorded_amount_bdt']!==$expectedAmount))
        respond(409,['error'=>'RECHARGE_PREVIEW_CHANGED']);
    $existing=jm_mobile_query($db,
        'SELECT * FROM tbl_mobile_admin_recharge_requests WHERE request_key=?',
        [$key])->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
        if ((int)$existing['actor_id']!==$admin['id'] ||
            (int)$existing['customer_id']!==(int)$id ||
            (int)$existing['plan_id']!==(int)$selectedPlan)
            respond(409,['error'=>'REQUEST_KEY_CONFLICT']);
        $recovered=jm_mobile_recharge_recover($db,$existing);
        if ($recovered)
            return ['status'=>'completed','invoice'=>$recovered,'duplicate'=>true,'recovered'=>true];
        respond(409,['error'=>'RECHARGE_CHECK_PANEL_BEFORE_RETRY']);
    }
    // Serialise requests per customer, including requests with different keys.
    $lock='jm_mobile_recharge_'.$id;
    if ((int)jm_mobile_query($db,'SELECT GET_LOCK(?, 0)',[$lock])->fetchColumn()!==1)
        respond(409,['error'=>'RECHARGE_ALREADY_PROCESSING']);
    try {
        $lockedCustomer=jm_mobile_recharge_plan($db,(int)$id);
        if (!$lockedCustomer || (int)$lockedCustomer['plan_id']!==(int)$expectedPlan ||
            !jm_mobile_recharge_plan_eligible($lockedCustomer,$plan))
            respond(409,['error'=>'PLAN_CHANGED_REFRESH_REQUIRED']);
        if ($expectedAmount!=='' &&
            jm_mobile_recharge_calculate_preview((int)$id,$plan)['expected_recorded_amount_bdt']!==$expectedAmount)
            respond(409,['error'=>'RECHARGE_PREVIEW_CHANGED']);
        $recent=jm_mobile_query($db,
            "SELECT * FROM tbl_mobile_admin_recharge_requests
             WHERE customer_id=? AND (status='pending' OR
                 created_at>DATE_SUB(NOW(),INTERVAL 60 SECOND))
             ORDER BY created_at DESC LIMIT 1",[$id])->fetch(PDO::FETCH_ASSOC);
        if ($recent) {
            $recovered=jm_mobile_recharge_recover($db,$recent);
            if ($recovered)
                return ['status'=>'completed','invoice'=>$recovered,'duplicate'=>true,'recovered'=>true];
            respond(409,['error'=>'RECENT_RECHARGE_CHECK_PANEL']);
        }
        jm_mobile_query($db,
            "INSERT INTO tbl_mobile_admin_recharge_requests
             (request_key,actor_id,customer_id,plan_id,router,status,created_at)
             VALUES (?,?,?,?,?,'pending',NOW())",
            [$key,$admin['id'],$id,$selectedPlan,$customer['routers']]);
        // Legacy Package::rechargeUser also changes PPPoE, invoices and billing.
        // It is not atomic with our request table: uncertain failures MUST be reviewed.
        $GLOBALS['admin']=$admin['actor'];
        // The reference lets us recover a saved invoice even if a later
        // optional notification hook fails. Never call rechargeUser twice.
        $reference='JM_APP_REQUEST_KEY='.$key;
        $invoice=null;
        try {
            $invoice=Package::rechargeUser((int)$id,(string)$customer['routers'],
                (int)$selectedPlan,'Admin Manual',$admin['username'],
                $reference.' Admin-confirmed manual recharge');
        } catch (Throwable $error) {
            error_log('JM mobile recharge post-start error: '.$error->getMessage());
        }
        if (!$invoice) {
            $invoice=jm_mobile_query($db,
                'SELECT invoice FROM tbl_transactions
                 WHERE user_id=? AND note LIKE ?
                 ORDER BY id DESC LIMIT 1',
                [$id,'%'.$reference.'%'])->fetchColumn();
        }
        if (!$invoice) respond(409,['error'=>'RECHARGE_CHECK_PANEL_BEFORE_RETRY']);
        jm_mobile_query($db,
            "UPDATE tbl_mobile_admin_recharge_requests
             SET status='completed',invoice=?,completed_at=NOW()
             WHERE request_key=?",[(string)$invoice,$key]);
        try {
            _log('Mobile admin '.$admin['username'].' recharged '.$customer['username'].
                ' ['.$invoice.']','Admin',$admin['id']);
        } catch (Throwable $error) {
            error_log('JM mobile recharge audit warning: '.$error->getMessage());
        }
        return ['status'=>'completed','invoice'=>(string)$invoice,'duplicate'=>false,
            'username'=>$customer['username'],'plan_id'=>(int)$selectedPlan];
    } finally {
        jm_mobile_query($db,'SELECT RELEASE_LOCK(?)',[$lock]);
    }
}
