<?php
/** Firebase Cloud Messaging for authenticated JM Broadband mobile devices. */
declare(strict_types=1);

function jm_push_ready(PDO $db): bool {
    return jm_app_table($db,'tbl_mobile_push_tokens');
}
function jm_push_actor(PDO $db,array $session): array {
    $actor=jm_app_actor($db,$session);
    if (!in_array($actor['role'],['customer','admin','reseller'],true))
        respond(403,['error'=>'FORBIDDEN']);
    if (!jm_push_ready($db)) respond(503,['error'=>'PUSH_NOT_CONFIGURED']);
    return $actor;
}
function jm_push_type(array $actor): string {
    return $actor['role']==='admin' ? 'staff' : $actor['role'];
}
function jm_push_register(PDO $db,array $session,array $input): array {
    $actor=jm_push_actor($db,$session);
    $token=trim((string)($input['token']??''));
    if (strlen($token)<40 || strlen($token)>255 ||
        !preg_match('/^[A-Za-z0-9_:\-]+$/D',$token))
        respond(400,['error'=>'INVALID_PUSH_TOKEN']);
    $platform=(string)($input['platform']??'android');
    if (!in_array($platform,['android','ios'],true))
        respond(400,['error'=>'INVALID_PLATFORM']);
    $version=substr(trim((string)($input['app_version']??'')),0,32);
    $label=substr(trim((string)($input['device_label']??'')),0,120);
    $type=jm_push_type($actor);
    jm_mobile_query($db,"INSERT INTO tbl_mobile_push_tokens
      (actor_type,actor_id,token,platform,app_version,device_label,
       enabled,created_at,updated_at,last_seen)
      VALUES (?,?,?,?,?,?,1,NOW(),NOW(),NOW())
      ON DUPLICATE KEY UPDATE actor_type=VALUES(actor_type),
       actor_id=VALUES(actor_id),platform=VALUES(platform),
       app_version=VALUES(app_version),device_label=VALUES(device_label),
       enabled=1,updated_at=NOW(),last_seen=NOW()",
      [$type,$actor['id'],$token,$platform,$version?:null,$label?:null]);
    return ['registered'=>true];
}
function jm_push_unregister(PDO $db,array $session,array $input): array {
    $actor=jm_push_actor($db,$session);
    $token=trim((string)($input['token']??''));
    if ($token!=='') {
        jm_mobile_query($db,"UPDATE tbl_mobile_push_tokens
          SET enabled=0,updated_at=NOW() WHERE token=?
          AND actor_type=? AND actor_id=?",
          [$token,jm_push_type($actor),$actor['id']]);
    }
    return ['unregistered'=>true];
}
function jm_push_send_token(PDO $db,string $token,string $title,string $body,
                            array $data=[]): bool {
    $stringData=[];
    foreach ($data as $key=>$value) $stringData[(string)$key]=(string)$value;
    $payload=json_encode([
      'token'=>$token,'title'=>$title,'body'=>$body,'data'=>$stringData
    ],JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) return false;
    $ch=curl_init('http://127.0.0.1:31337/send');
    curl_setopt_array($ch,[
      CURLOPT_POST=>true,CURLOPT_RETURNTRANSFER=>true,
      CURLOPT_CONNECTTIMEOUT=>2,CURLOPT_TIMEOUT=>15,
      CURLOPT_HTTPHEADER=>['Content-Type: application/json'],
      CURLOPT_POSTFIELDS=>$payload
    ]);
    $response=curl_exec($ch);
    $code=(int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json=is_string($response)?json_decode($response,true):null;
    if ($code>=200 && $code<300 && is_array($json) &&
        ($json['ok']??false)===true) return true;
    if (is_array($json) && ($json['invalidToken']??false)===true) {
        jm_mobile_query($db,"UPDATE tbl_mobile_push_tokens
          SET enabled=0,updated_at=NOW() WHERE token=?",[$token]);
    }
    $status=is_array($json)?(string)($json['status']??'BRIDGE_ERROR'):'BRIDGE_ERROR';
    error_log('JM FCM bridge failed HTTP '.$code.' status='.$status);
    return false;
}
function jm_push_send_actor(PDO $db,string $actorType,int $actorId,
                            string $title,string $body,array $data=[]): int {
    if (!jm_push_ready($db)) return 0;
    $tokens=jm_mobile_query($db,"SELECT token FROM tbl_mobile_push_tokens
      WHERE actor_type=? AND actor_id=? AND enabled=1
      ORDER BY last_seen DESC LIMIT 8",
      [$actorType,$actorId])->fetchAll(PDO::FETCH_COLUMN);
    $sent=0;
    foreach ($tokens as $token)
        if (jm_push_send_token($db,(string)$token,$title,$body,$data)) $sent++;
    return $sent;
}
function jm_push_send_staff(PDO $db,string $title,string $body,
                            array $data=[]): int {
    if (!jm_push_ready($db)) return 0;
    $tokens=jm_mobile_query($db,"SELECT DISTINCT token
      FROM tbl_mobile_push_tokens WHERE actor_type='staff' AND enabled=1
      ORDER BY last_seen DESC LIMIT 50")->fetchAll(PDO::FETCH_COLUMN);
    $sent=0;
    foreach ($tokens as $token)
        if (jm_push_send_token($db,(string)$token,$title,$body,$data)) $sent++;
    return $sent;
}
