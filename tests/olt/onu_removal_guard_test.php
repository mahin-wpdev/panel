<?php
/** Safety-only guard tests: no real OLT connections or customer mutations. */
declare(strict_types=1);
final class OltManager {
    public static function validHost(string $host): bool { return $host === '192.0.2.1'; }
}
require __DIR__ . '/../../system/autoload/OltOnuRemoval.php';
$olt = ['id'=>7, 'vendor'=>'vsol', 'protocol'=>'telnet',
    'management_host'=>'192.0.2.1','port'=>23];
$onu = ['pon_port'=>'1','onu_id'=>'1','mac_address'=>'02:11:22:33:44:55',
    'status'=>'OFFLINE','customer_id'=>null,'olt_id'=>7];
$cases = [
    'online'=>[$olt,array_replace($onu,['status'=>'ONLINE'])],
    'assigned'=>[$olt,array_replace($onu,['customer_id'=>123])],
    'wrong_olt'=>[$olt,array_replace($onu,['olt_id'=>8])],
    'invalid_pon'=>[$olt,array_replace($onu,['pon_port'=>'9'])],
    'invalid_onu_id'=>[$olt,array_replace($onu,['onu_id'=>'65'])],
    'invalid_mac'=>[$olt,array_replace($onu,['mac_address'=>'not-a-mac'])],
    'wrong_vendor'=>[array_replace($olt,['vendor'=>'unknown']),$onu],
    'wrong_protocol'=>[array_replace($olt,['protocol'=>'ssh']),$onu],
    'invalid_host'=>[array_replace($olt,['management_host'=>'invalid']),$onu],
];
foreach ($cases as $name=>[$device,$record]) {
    try { OltOnuRemoval::removeOffline($device,$record); }
    catch (RuntimeException $e) { continue; }
    fwrite(STDERR, "Guard failed: $name\n"); exit(1);
}
echo 'PASS: '.count($cases)." offline ONU fail-closed guards; no network I/O\n";
