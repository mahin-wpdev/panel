<?php
declare(strict_types=1);
require __DIR__.'/../../system/autoload/ResellerProfit.php';

$fallback = ResellerProfit::resolvePackageConfig(null, ['profit_percentage' => 60]);
$split = ResellerProfit::calculateSplit(500, $fallback);
if ($fallback['id'] !== null || $split['reseller_profit'] !== 300.0 || $split['admin_profit'] !== 200.0) {
    throw new RuntimeException('A reseller profile rate should apply when the plan has no package rule.');
}

$configured = ResellerProfit::resolvePackageConfig([
    'id' => 12,
    'base_cost' => 200,
    'profit_type' => 'percentage',
    'profit_value' => 50,
], ['profit_percentage' => 60]);
$split = ResellerProfit::calculateSplit(500, $configured);
if ($split['reseller_profit'] !== 150.0 || $split['admin_profit'] !== 150.0) {
    throw new RuntimeException('An explicit package rule should take precedence over the profile rate.');
}

echo "PASS: profile commission fallback and explicit package split\n";
