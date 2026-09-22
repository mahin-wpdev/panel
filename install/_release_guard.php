<?php
declare(strict_types=1);

/**
 * Refuse installer access when this PHPNuxBill checkout has been configured.
 * This file must be the first include in EVERY installer entry point, even
 * before the legacy installer writes config.php or executes destructive SQL.
 */
$existingConfigs = [
    dirname(__DIR__) . '/config.php',
    dirname(__DIR__, 2) . '/config.php',
];

foreach ($existingConfigs as $configuredPath) {
    if (is_file($configuredPath)) {
        http_response_code(404);
        header('Cache-Control: no-store, private');
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Installer disabled on a configured installation.';
        exit;
    }
}
