<?php
declare(strict_types=1);
require __DIR__ . '/../../system/mobile/auth-core.php';
$cases = [
    [['status'=>'Active'], 'customer', null, 'customer'],
    [['status'=>'Banned'], 'customer', null, null],
    [['user_type'=>'SuperAdmin','status'=>'Active'], 'staff', null, 'superadmin'],
    [['user_type'=>'Admin','status'=>'Active'], 'staff', ['id'=>4,'status'=>'active'], 'admin'],
    [['user_type'=>'Agent','status'=>'Active'], 'staff', ['id'=>2,'status'=>'active'], 'reseller'],
    [['user_type'=>'Agent','status'=>'Active'], 'staff', ['id'=>2,'status'=>'suspended'], null],
    [['user_type'=>'Agent','status'=>'Active'], 'staff', null, null],
    [['user_type'=>'Sales','status'=>'Active'], 'staff', null, null],
];
foreach ($cases as $i=>[$actor,$type,$reseller,$expected]) {
    $actual=jm_mobile_role($actor,$type,$reseller);
    if ($actual!==$expected) {
        fwrite(STDERR,"Case $i failed: expected " . var_export($expected,true) . ' got ' . var_export($actual,true) . "\n");
        exit(1);
    }
}
echo 'PASS: ' . count($cases) . " mobile role cases\n";
