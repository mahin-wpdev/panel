<?php
/** Admin-only mobile ONU inventory and customer assignment actions. */
declare(strict_types=1);

function jm_mobile_onu_admin(PDO $db,array $session): array {
    $actor=jm_app_actor($db,$session);
    if (($actor['role']??'')!=='admin') respond(403,['error'=>'FORBIDDEN']);
    if (!jm_app_table($db,'tbl_onus') ||
        !jm_app_column($db,'tbl_onus','customer_id'))
        respond(503,['error'=>'ONU_INVENTORY_UNAVAILABLE']);
    return $actor;
}
function jm_mobile_onu_row(PDO $db,int $id): ?array {
    if ($id<1) return null;
    $row=jm_mobile_query($db,"SELECT o.id,o.olt_id,o.customer_id,o.status,
       o.rx_power,o.tx_power,o.last_seen,o.mac_address,o.pon_port,o.onu_id,
       o.updated_at,c.username,c.fullname,c.status AS customer_status,
       olt.name AS olt_name
       FROM tbl_onus o
       LEFT JOIN tbl_customers c ON c.id=o.customer_id
       LEFT JOIN tbl_olts olt ON olt.id=o.olt_id
       WHERE o.id=? LIMIT 1",[$id])->fetch(PDO::FETCH_ASSOC);
    return $row?:null;
}
function jm_mobile_onu_public(array $row): array {
    $customerId=$row['customer_id']===null?null:(int)$row['customer_id'];
    return [
      'id'=>(int)$row['id'],'olt_id'=>(int)$row['olt_id'],
      'olt_name'=>$row['olt_name'],'status'=>$row['status'],
      'rx_power'=>$row['rx_power'],'tx_power'=>$row['tx_power'],
      'last_seen'=>$row['last_seen'],'mac_address'=>$row['mac_address'],
      'pon_port'=>$row['pon_port'],'onu_id'=>$row['onu_id'],
      'updated_at'=>$row['updated_at'],'customer_id'=>$customerId,
      'customer_username'=>$row['username'],'customer_name'=>$row['fullname'],
      'customer_status'=>$row['customer_status'],
      'assigned'=>$customerId!==null,
      'can_remove_from_olt'=>$customerId===null && $row['status']==='OFFLINE'
    ];
}
function jm_mobile_onu_list(PDO $db,array $session,string $query='',string $filter='all'): array {
    jm_mobile_onu_admin($db,$session);
    $query=trim($query);
    if (mb_strlen($query)>100) respond(400,['error'=>'SEARCH_TOO_LONG']);
    if (!in_array($filter,['all','unassigned','assigned','online','offline'],true))
        respond(400,['error'=>'INVALID_ONU_FILTER']);
    $where=['1=1'];$params=[];
    if ($filter==='unassigned') $where[]='o.customer_id IS NULL';
    if ($filter==='assigned') $where[]='o.customer_id IS NOT NULL';
    if ($filter==='online') {$where[]="o.status='ONLINE'";}
    if ($filter==='offline') {$where[]="o.status='OFFLINE'";}
    if ($query!=='') {
        $like='%'.$query.'%';
        $where[]='(o.mac_address LIKE ? OR c.username LIKE ? OR c.fullname LIKE ? OR olt.name LIKE ?)';
        array_push($params,$like,$like,$like,$like);
    }
    $rows=jm_mobile_query($db,"SELECT o.id,o.olt_id,o.customer_id,o.status,
       o.rx_power,o.tx_power,o.last_seen,o.mac_address,o.pon_port,o.onu_id,
       o.updated_at,c.username,c.fullname,c.status AS customer_status,
       olt.name AS olt_name
       FROM tbl_onus o
       LEFT JOIN tbl_customers c ON c.id=o.customer_id
       LEFT JOIN tbl_olts olt ON olt.id=o.olt_id
       WHERE ".implode(' AND ',$where)."
       ORDER BY (o.customer_id IS NULL) DESC,o.updated_at DESC,o.id DESC LIMIT 150",
       $params)->fetchAll(PDO::FETCH_ASSOC);
    return ['available'=>true,'filter'=>$filter,
      'items'=>array_map('jm_mobile_onu_public',$rows),
      'note'=>'Synced ONU inventory. Assign/unassign changes only the customer mapping; Remove from OLT is a separate guarded action.'];
}
function jm_mobile_onu_assign(PDO $db,array $session,array $input): array {
    $admin=jm_mobile_onu_admin($db,$session);
    $onuId=(int)($input['onu_id']??0);
    $customerId=(int)($input['customer_id']??0);
    if ($onuId<1||$customerId<1) respond(400,['error'=>'INVALID_ONU_ASSIGNMENT']);
    $onu=jm_mobile_onu_row($db,$onuId);
    $customer=jm_mobile_query($db,
      'SELECT id,username,fullname,status FROM tbl_customers WHERE id=? LIMIT 1',
      [$customerId])->fetch(PDO::FETCH_ASSOC);
    if (!$onu||!$customer) respond(404,['error'=>'ONU_OR_CUSTOMER_NOT_FOUND']);
    if ($onu['status']==='REMOVED') respond(409,['error'=>'ONU_REMOVED']);
    if ($onu['customer_id']!==null && (int)$onu['customer_id']!==$customerId)
        respond(409,['error'=>'ONU_ALREADY_ASSIGNED']);
    jm_mobile_query($db,'UPDATE tbl_onus SET customer_id=?,updated_at=NOW() WHERE id=?',
      [$customerId,$onuId]);
    error_log('JM mobile ONU assigned onu='.$onuId.' customer='.$customerId.
      ' admin='.(int)$admin['id']);
    $fresh=jm_mobile_onu_row($db,$onuId);
    return ['assigned'=>true,'onu'=>jm_mobile_onu_public($fresh)];
}
function jm_mobile_onu_unassign(PDO $db,array $session,array $input): array {
    $admin=jm_mobile_onu_admin($db,$session);
    $onuId=(int)($input['onu_id']??0);
    $expected=(int)($input['expected_customer_id']??0);
    if ($onuId<1) respond(400,['error'=>'INVALID_ONU']);
    $onu=jm_mobile_onu_row($db,$onuId);
    if (!$onu) respond(404,['error'=>'ONU_NOT_FOUND']);
    if ($onu['customer_id']===null)
        return ['unassigned'=>true,'onu'=>jm_mobile_onu_public($onu)];
    if ($expected>0 && (int)$onu['customer_id']!==$expected)
        respond(409,['error'=>'ONU_ASSIGNMENT_CHANGED']);
    jm_mobile_query($db,'UPDATE tbl_onus SET customer_id=NULL,updated_at=NOW() WHERE id=?',
      [$onuId]);
    error_log('JM mobile ONU unassigned onu='.$onuId.' admin='.(int)$admin['id']);
    $fresh=jm_mobile_onu_row($db,$onuId);
    return ['unassigned'=>true,'onu'=>jm_mobile_onu_public($fresh)];
}
function jm_mobile_onu_remove(PDO $db,array $session,array $input): array {
    $admin=jm_mobile_onu_admin($db,$session);
    $onuId=(int)($input['onu_id']??0);
    $confirm=strtoupper(trim((string)($input['confirm_mac']??'')));
    if ($onuId<1||$confirm==='') respond(400,['error'=>'INVALID_ONU_REMOVE_REQUEST']);
    $onu=jm_mobile_onu_row($db,$onuId);
    if (!$onu) respond(404,['error'=>'ONU_NOT_FOUND']);
    if ($onu['customer_id']!==null || $onu['status']!=='OFFLINE')
        respond(409,['error'=>'ONU_MUST_BE_OFFLINE_AND_UNASSIGNED']);
    if (!hash_equals(strtoupper((string)$onu['mac_address']),$confirm))
        respond(409,['error'=>'ONU_IDENTITY_CHANGED']);
    $olt=jm_mobile_query($db,'SELECT * FROM tbl_olts WHERE id=? LIMIT 1',
      [(int)$onu['olt_id']])->fetch(PDO::FETCH_ASSOC);
    if (!$olt || ($olt['status']??'')==='Disabled')
        respond(409,['error'=>'OLT_NOT_AVAILABLE']);
    $lock=@fopen('/www/wwwroot/27.147.201.165/system/secure/olt-sync.lock','c');
    if (!$lock || !flock($lock,LOCK_EX|LOCK_NB)) {
        if ($lock) fclose($lock);
        respond(409,['error'=>'OLT_SYNC_RUNNING']);
    }
    $fresh=jm_mobile_onu_row($db,$onuId);
    if (!$fresh || $fresh['customer_id']!==null ||
        $fresh['status']!=='OFFLINE' ||
        !hash_equals(strtoupper((string)$fresh['mac_address']),$confirm) ||
        (int)$fresh['olt_id']!==(int)$olt['id']) {
        flock($lock,LOCK_UN);fclose($lock);
        respond(409,['error'=>'ONU_CHANGED_REFRESH_REQUIRED']);
    }
    try {
        require_once '/www/wwwroot/27.147.201.165/system/autoload/OltManager.php';
        require_once '/www/wwwroot/27.147.201.165/system/autoload/OltOnuRemoval.php';
        OltOnuRemoval::removeOffline($olt,$fresh);
        jm_mobile_query($db,"UPDATE tbl_onus SET status='REMOVED',updated_at=NOW() WHERE id=?",
          [$onuId]);
        if (jm_app_table($db,'tbl_onu_status_logs')) {
            jm_mobile_query($db,"INSERT INTO tbl_onu_status_logs
              (onu_id,previous_status,status,recorded_at)
              VALUES (?,'OFFLINE','REMOVED',NOW())",[$onuId]);
        }
        error_log('JM mobile ONU removed from OLT onu='.$onuId.
          ' admin='.(int)$admin['id']);
        return ['removed'=>true,'onu_id'=>$onuId];
    } finally {
        flock($lock,LOCK_UN);fclose($lock);
    }
}
