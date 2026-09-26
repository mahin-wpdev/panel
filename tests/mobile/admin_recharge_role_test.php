<?php
declare(strict_types=1);
require __DIR__.'/../../system/mobile/admin-recharge.php';
foreach (['customer'=>false,'reseller'=>false,'sales'=>false,
          'unknown'=>false,'admin'=>true,'superadmin'=>false] as $role=>$expected) {
    if (jm_mobile_recharge_is_admin($role)!==$expected) {
        fwrite(STDERR,"Recharge role guard failed: $role\n");
        exit(1);
    }
}
echo "PASS: admin-only recharge role guard\n";$current=['status'=>'Active','plan_id'=>10,'routers'=>'R1',
    'type'=>'PPPOE','device'=>'Mikrotik','prepaid'=>'yes'];
$valid=['enabled'=>1,'routers'=>'R1','type'=>'PPPOE',
    'device'=>'Mikrotik','prepaid'=>'yes'];
if (!jm_mobile_recharge_plan_eligible($current,$valid)) exit(2);
foreach ([
    ['routers'=>'other'],['enabled'=>0],['type'=>'Hotspot'],
    ['device'=>'Other'],['prepaid'=>'no'],
] as $invalid) {
    if (jm_mobile_recharge_plan_eligible($current,array_replace($valid,$invalid))) exit(3);
}
echo "PASS: matching active package required; invalid router/type/device/prepaid rejected\n";
