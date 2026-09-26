<?php
require dirname(__DIR__, 2) . '/system/autoload/MikrotikProvisioner.php';

$cfg = [
    'host' => '192.0.2.1',
    'api_port' => 8728,
    'mode' => 'hybrid',
    'wan_interface' => 'ether1',
    'lan_interface' => 'bridge',
    'radius_server' => '10.10.10.7',
    'panel_server' => '10.10.10.7',
    'panel_api_user' => 'jm-panel-api',
    'panel_api_password' => 'fixed-api-secret',
    'radius_secret' => 'fixed-radius-secret',
    'pppoe_profile' => 'jm-panel-radius',
    'pppoe_pool' => 'pppoe-pool',
    'interim_update' => '5m',
];
$script = MikrotikProvisioner::buildScript($cfg);
$rollback = MikrotikProvisioner::rollbackScript($cfg);

foreach ([
    '/system backup save',
    '/export file=',
    '/radius add',
    '/radius incoming set accept=yes port=3799',
    '/ppp aaa set use-radius=yes accounting=yes',
    'JM-PANEL-MANAGED-API',
    'JM-PANEL-MANAGED-COA',
    'on-error=',
    'fixed-api-secret',
    'fixed-radius-secret',
] as $needle) {
    if (strpos($script, $needle) === false) {
        throw new RuntimeException('Missing provisioning command: ' . $needle);
    }
}
foreach (['/radius remove', '/user remove', '/ip firewall filter remove'] as $needle) {
    if (strpos($rollback, $needle) === false) {
        throw new RuntimeException('Missing rollback command: ' . $needle);
    }
}
try {
    MikrotikProvisioner::normalize(array_merge($cfg, ['wan_interface' => 'bridge']));
    throw new RuntimeException('Unsafe same WAN/LAN accepted');
} catch (InvalidArgumentException $expected) {
}
echo "MikroTik provisioning preview and rollback contract passed.\n";
