<?php

$root = dirname(__DIR__, 2);
$package = file_get_contents($root . '/system/autoload/Package.php');
$outbox = file_get_contents($root . '/system/autoload/RechargeOutbox.php');
$migration = file_get_contents($root . '/database/migrations/2026101404_recharge_provisioning_outbox.sql');

if ($package === false || $outbox === false || $migration === false) {
    throw new RuntimeException('Recharge outbox source files could not be read.');
}

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($migration, 'ADD COLUMN IF NOT EXISTS recharge_operation_key'), 'Migration must be repeat-safe.');
$assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS tbl_recharge_provisioning_outbox'), 'Migration must create the outbox.');
$assert(str_contains($package, 'RechargeOutbox::markProvisioned(ORM::get_db(), $operationKey)'), 'Successful provisioning must be persisted.');
$assert(str_contains($package, 'RechargeOutbox::complete($db, $operationKey'), 'Ledger commit must complete the same outbox entry.');
$assert(str_contains($package, 'self::provisionCustomer($c, $p, $router_name, $_app_stage, $date_exp . \' \' . $time);'), 'Normal recharge must call the provisioning driver.');
$assert(str_contains($package, 'if (!$skipProvisioning) self::provisionCustomer'), 'Ledger recovery must not call the router again.');
$assert(str_contains($outbox, "status='manual_review'"), 'Stale or failed operations must stop for review.');
$assert(str_contains($outbox, "status='provisioned'"), 'Recovery must select confirmed provisioning only.');

echo "Recharge provisioning outbox invariants passed\n";
