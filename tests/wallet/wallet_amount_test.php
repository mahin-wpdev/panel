<?php
declare(strict_types=1);
require __DIR__ . '/../../system/autoload/Balance.php';

foreach (['0', '-1', '-0.50', '1.001', 'abc', '1e3', '999999999'] as $invalid) {
    if (Balance::isValidAmount($invalid)) {
        throw new RuntimeException('Invalid wallet amount was accepted: ' . $invalid);
    }
}
foreach (['0.01', '1', '50.25', '99999999.99'] as $valid) {
    if (!Balance::isValidAmount($valid)) {
        throw new RuntimeException('Valid wallet amount was rejected: ' . $valid);
    }
}
echo "PASS: wallet transfer amounts must be positive and have at most two decimals\n";
