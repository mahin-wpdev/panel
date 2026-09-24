<?php
/** Place beside panel/init.php. Separate from legacy system/api.php. */
declare(strict_types=1);
ini_set('display_errors','0');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
function respond(int $status,array $json): never {
    http_response_code($status);
    echo json_encode($json,JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function body(): array {
    if (!str_starts_with(strtolower((string)($_SERVER['CONTENT_TYPE']??'')),'application/json')) respond(415,['error'=>'JSON_REQUIRED']);
    $raw=file_get_contents('php://input',false,null,0,8193);
    if ($raw===false || strlen($raw)>8192) respond(413,['error'=>'REQUEST_TOO_LARGE']);
    $data=json_decode($raw,true);
    if (!is_array($data) || array_is_list($data)) respond(400,['error'=>'INVALID_JSON']);
    return $data;
}
function bearer(): string {
    $value=(string)($_SERVER['HTTP_AUTHORIZATION']??$_SERVER['REDIRECT_HTTP_AUTHORIZATION']??'');
    if (!$value && function_exists('getallheaders')) foreach(getallheaders() as $key=>$header) if (strcasecmp($key,'Authorization')===0) $value=(string)$header;
    return preg_match('/^Bearer ([A-Za-z0-9_-]{43})$/',trim($value),$m)?$m[1]:'';
}
if (PHP_SAPI!=='cli' && (($_SERVER['HTTPS']??'')!=='on') && (int)($_SERVER['SERVER_PORT']??0)!==443) respond(403,['error'=>'HTTPS_REQUIRED']);
$isApi=true;
try {
    require __DIR__.'/init.php';
    require_once __DIR__.'/system/mobile/auth-core.php';
    $db=ORM::get_db();
    $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $action=(string)($_GET['action']??'');
    $method=(string)($_SERVER['REQUEST_METHOD']??'GET');
    if ($action==='server-info' && $method==='GET') {
        respond(200,['success'=>true,'data'=>['api_version'=>'1.0','authentication'=>'unified',
            'company_name'=>(string)($config['CompanyName']??'ISP Panel')]]);
    }
    if ($action==='login' && $method==='POST') {
        $input=body();$username=trim((string)($input['username']??''));$password=(string)($input['password']??'');
        if (!$username || !$password || strlen($username)>100 || strlen($password)>256) respond(400,['error'=>'INVALID_CREDENTIALS']);
        $ip=(string)($_SERVER['REMOTE_ADDR']??'unknown');
        $attemptKey=hash('sha256',strtolower($username).'|'.$ip);
        $db->beginTransaction();
        $attempt=jm_mobile_query($db,'SELECT * FROM tbl_mobile_auth_attempts WHERE attempt_key=? FOR UPDATE',[$attemptKey])->fetch(PDO::FETCH_ASSOC);
        if ($attempt && $attempt['blocked_until'] && strtotime($attempt['blocked_until'])>time()) {
            $db->commit();respond(429,['error'=>'TOO_MANY_ATTEMPTS']);
        }
        $identity=jm_mobile_resolve($db,$username,$password);
        if (!$identity) {
            $count=$attempt && strtotime($attempt['window_start'])>time()-900 ? (int)$attempt['attempts']+1:1;
            jm_mobile_query($db,'INSERT INTO tbl_mobile_auth_attempts(attempt_key,attempts,window_start,blocked_until)
                VALUES(?,?,NOW(),?) ON DUPLICATE KEY UPDATE attempts=VALUES(attempts),window_start=IF(VALUES(attempts)=1,NOW(),window_start),blocked_until=VALUES(blocked_until)',
                [$attemptKey,$count,$count>=5?date('Y-m-d H:i:s',time()+900):null]);
            $db->commit();respond(401,['error'=>'INVALID_CREDENTIALS']);
        }
        jm_mobile_query($db,'DELETE FROM tbl_mobile_auth_attempts WHERE attempt_key=?',[$attemptKey]);
        $access=jm_mobile_token();$refresh=jm_mobile_token();
        jm_mobile_query($db,'INSERT INTO tbl_mobile_auth_sessions(actor_type,actor_id,access_hash,refresh_hash,access_expires_at,refresh_expires_at)
            VALUES(?,?,?,?,DATE_ADD(NOW(),INTERVAL 15 MINUTE),DATE_ADD(NOW(),INTERVAL 30 DAY))',
            [$identity['type'],(int)$identity['actor']['id'],hash('sha256',$access),hash('sha256',$refresh)]);
        $db->commit();
        respond(200,['success'=>true,'data'=>['access_token'=>$access,'refresh_token'=>$refresh,'expires_in'=>900,'user'=>jm_mobile_public_actor($identity)]]);
    }
    if ($action==='refresh' && $method==='POST') {
        $input=body();$token=(string)($input['refresh_token']??'');
        if (!preg_match('/^[A-Za-z0-9_-]{43}$/',$token)) respond(401,['error'=>'INVALID_SESSION']);
        $db->beginTransaction();
        $session=jm_mobile_query($db,'SELECT * FROM tbl_mobile_auth_sessions WHERE refresh_hash=? AND revoked_at IS NULL AND refresh_expires_at>NOW() FOR UPDATE',[hash('sha256',$token)])->fetch(PDO::FETCH_ASSOC);
        if (!$session) {$db->commit();respond(401,['error'=>'INVALID_SESSION']);}
        if (!jm_mobile_session_allowed($db, $session)) {
            $db->commit();
            respond(403,['error'=>'ACCOUNT_DISABLED']);
        }
        $access=jm_mobile_token();$refresh=jm_mobile_token();
        jm_mobile_query($db,'UPDATE tbl_mobile_auth_sessions SET access_hash=?,refresh_hash=?,access_expires_at=DATE_ADD(NOW(),INTERVAL 15 MINUTE),last_seen_at=NOW() WHERE id=?',
          [hash('sha256',$access),hash('sha256',$refresh),(int)$session['id']]);
        $db->commit();respond(200,['success'=>true,'data'=>['access_token'=>$access,'refresh_token'=>$refresh,'expires_in'=>900]]);
    }
    $token=bearer();if (!$token) respond(401,['error'=>'LOGIN_REQUIRED']);
    $session=jm_mobile_query($db,'SELECT * FROM tbl_mobile_auth_sessions WHERE access_hash=? AND revoked_at IS NULL AND access_expires_at>NOW() LIMIT 1',[hash('sha256',$token)])->fetch(PDO::FETCH_ASSOC);
    if (!$session) respond(401,['error'=>'INVALID_SESSION']);
    if (!jm_mobile_session_allowed($db, $session)) {
        respond(403,['error'=>'ACCOUNT_DISABLED']);
    }
    if ($action==='customer-dashboard' && $method==='GET') {
        if ($session['actor_type'] !== 'customer') respond(403,['error'=>'FORBIDDEN']);
        require_once __DIR__.'/system/mobile/customer-dashboard.php';
        $dashboard=jm_mobile_customer_dashboard($db,(int)$session['actor_id']);
        if (!$dashboard) respond(403,['error'=>'ACCOUNT_DISABLED']);
        respond(200,['success'=>true,'data'=>$dashboard]);
    }
    if ($action==='live-traffic' && $method==='GET') {
        if ($session['actor_type'] !== 'customer') respond(403,['error'=>'FORBIDDEN']);
        require_once __DIR__.'/system/mobile/live-traffic.php';
        require_once __DIR__.'/system/mobile/radius-peak.php';
        $live = jm_live_snapshot_cached($db,$session);
        $live['server_peak'] = jm_radius_peak_for_customer($db,(int)$session['actor_id']);
        respond(200,['success'=>true,'data'=>$live]);
    }
    if ($action==='mobile-data' && $method==='GET') {
        require_once __DIR__.'/system/mobile/panel-app.php';
        $section=(string)($_GET['section']??'home');
        respond(200,['success'=>true,'data'=>jm_mobile_app_data($db,$session,$section)]);
    }
    if ($action==='admin-recharge-search' && $method==='GET') {
        require_once __DIR__.'/system/mobile/panel-app.php';
        require_once __DIR__.'/system/mobile/admin-recharge.php';
        respond(200,['success'=>true,'data'=>jm_mobile_recharge_search($db,$session,(string)($_GET['q']??''))]);
    }
    if ($action==='admin-recharge-options' && $method==='GET') {
        require_once __DIR__.'/system/mobile/panel-app.php';
        require_once __DIR__.'/system/mobile/admin-recharge.php';
        respond(200,['success'=>true,'data'=>jm_mobile_recharge_options($db,$session,(int)($_GET['customer_id']??0))]);
    }
    if ($action==='admin-customer-profile' && $method==='GET') {
        require_once __DIR__.'/system/mobile/panel-app.php';
        require_once __DIR__.'/system/mobile/admin-recharge.php';
        require_once __DIR__.'/system/mobile/admin-operations.php';
        respond(200,['success'=>true,'data'=>jm_mobile_admin_profile($db,$session,(int)($_GET['customer_id']??0))]);
    }
    if ($action==='admin-expiry' && $method==='GET') {
        require_once __DIR__.'/system/mobile/panel-app.php';
        require_once __DIR__.'/system/mobile/admin-recharge.php';
        require_once __DIR__.'/system/mobile/admin-operations.php';
        respond(200,['success'=>true,'data'=>jm_mobile_admin_expiry($db,$session,(string)($_GET['window']??'today'))]);
    }
    if ($action==='admin-recharge-preview' && $method==='GET') {
        require_once __DIR__.'/system/mobile/panel-app.php';
        require_once __DIR__.'/system/mobile/admin-recharge.php';
        respond(200,['success'=>true,'data'=>jm_mobile_recharge_preview($db,$session,
            (int)($_GET['customer_id']??0),(int)($_GET['plan_id']??0))]);
    }
    if ($action==='admin-recharge' && $method==='POST') {
        require_once __DIR__.'/system/mobile/panel-app.php';
        require_once __DIR__.'/system/mobile/admin-recharge.php';
        respond(200,['success'=>true,'data'=>jm_mobile_recharge_submit($db,$session,body())]);
    }
    if ($action==='logout' && $method==='POST') {
        jm_mobile_query($db,'UPDATE tbl_mobile_auth_sessions SET revoked_at=NOW() WHERE id=?',[(int)$session['id']]);
        respond(200,['success'=>true]);
    }
    if ($action==='me' && $method==='GET') {
        $id=(int)$session['actor_id'];
        if ($session['actor_type']==='customer') {
            $actor=jm_mobile_query($db,'SELECT id,username,fullname,status FROM tbl_customers WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
            if (!$actor || $actor['status']!=='Active') respond(403,['error'=>'ACCOUNT_DISABLED']);
            $role='customer';$reseller=null;
        } else {
            $actor=jm_mobile_query($db,'SELECT id,username,fullname,user_type,status FROM tbl_users WHERE id=?',[$id])->fetch(PDO::FETCH_ASSOC);
            $reseller=$actor && $actor['user_type']==='Agent' ? jm_mobile_reseller($db,$id) : null;
            $role=$actor?jm_mobile_role($actor,'staff',$reseller ?: null):null;
            if (!$role) respond(403,['error'=>'ACCOUNT_DISABLED']);
        }
        respond(200,['success'=>true,'data'=>jm_mobile_public_actor(['actor'=>$actor,'role'=>$role,
            'reseller_id'=>$role==='reseller'?(int)$reseller['id']:null])]);
    }
    respond(404,['error'=>'UNKNOWN_ENDPOINT']);
} catch (Throwable $error) {
    if (isset($db) && $db instanceof PDO && $db->inTransaction()) $db->rollBack();
    error_log('JM mobile foundation error: '.$error->getMessage());
    respond(500,['error'=>'SERVICE_UNAVAILABLE']);
}
