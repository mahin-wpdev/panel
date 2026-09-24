<?php
/** Public read-only GitHub release manifest; no phpNuxBill admin/session required. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
try {
    require_once __DIR__ . '/system/mobile/github-release.php';
    if (!defined('APP_URL')) {
        // phpNuxBill also supports a shared config.php one directory above /panel.
        // Load configuration only: do not initialize billing, sessions or the database.
        $configFile = is_file(__DIR__ . '/config.php')
            ? __DIR__ . '/config.php' : dirname(__DIR__) . '/config.php';
        if (!is_file($configFile)) throw new RuntimeException('Panel config missing.');
        require_once $configFile;
    }
    if (!defined('APP_URL') || parse_url(APP_URL, PHP_URL_SCHEME) !== 'https') {
        throw new RuntimeException('HTTPS panel URL required.');
    }
    $release = jmapp_latest_release();
    unset($release['github_url']);
    echo json_encode($release, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (OutOfBoundsException $e) {
    http_response_code(404);
    echo json_encode(['error' => 'No GitHub release published']);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['error' => 'GitHub update service unavailable']);
}
