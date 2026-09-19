<?php
declare(strict_types=1);
/** Mobile-specific authentication helpers; never redefine legacy Password class. */
function jm_mobile_token(): string { return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '='); }
function jm_mobile_query(PDO $db, string $sql, array $params=[]): PDOStatement {
    $query=$db->prepare($sql); $query->execute($params); return $query;
}
function jm_mobile_role(array $actor, string $type, ?array $reseller): ?string {
    if ($type==='customer') return $actor['status']==='Banned' ? null : 'customer';
    if (($actor['status'] ?? '') !== 'Active') return null;
    $staffRole=(string)$actor['user_type'];
    if ($staffRole==='SuperAdmin') return 'superadmin';
    if ($staffRole==='Admin') return 'admin';
    if ($staffRole==='Agent' && $reseller && $reseller['status']==='active') return 'reseller';
    return null; // fail closed for disabled reseller/unsupported staff roles
}
function jm_mobile_reseller(PDO $db, int $staffId): ?array {
    static $hasTable = null;
    if ($hasTable === null) {
        $hasTable = (bool) jm_mobile_query($db,
            'SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1',
            ['tbl_resellers'])->fetchColumn();
    }
    if (!$hasTable) return null;
    return jm_mobile_query($db,'SELECT id,status FROM tbl_resellers WHERE user_id=? LIMIT 1',
        [$staffId])->fetch(PDO::FETCH_ASSOC) ?: null;
}
function jm_mobile_resolve(PDO $db,string $username,string $password): ?array {
    $customer=jm_mobile_query($db,'SELECT id,username,fullname,password,status FROM tbl_customers WHERE username=? LIMIT 1',[$username])->fetch(PDO::FETCH_ASSOC);
    $staff=jm_mobile_query($db,'SELECT id,username,fullname,password,user_type,status FROM tbl_users WHERE username=? LIMIT 1',[$username])->fetch(PDO::FETCH_ASSOC);
    $matches=[];
    if ($customer && hash_equals((string)$customer['password'],$password)) {
        $role=jm_mobile_role($customer,'customer',null);
        if ($role) $matches[]=['type'=>'customer','actor'=>$customer,'role'=>$role,'reseller_id'=>null];
    }
    if ($staff && hash_equals((string)$staff['password'],sha1($password))) {
        $reseller=$staff['user_type']==='Agent' ? jm_mobile_reseller($db,(int)$staff['id']) : null;
        $role=jm_mobile_role($staff,'staff',$reseller);
        if ($role) $matches[]=['type'=>'staff','actor'=>$staff,'role'=>$role,'reseller_id'=>($role==='reseller'?(int)$reseller['id']:null)];
    }
    return count($matches)===1 ? $matches[0] : null;
}
function jm_mobile_public_actor(array $identity): array {
    $actor=$identity['actor'];
    return ['id'=>(int)$actor['id'],'username'=>$actor['username'],'name'=>$actor['fullname'],
            'role'=>$identity['role'],'reseller_id'=>$identity['reseller_id']];
}
