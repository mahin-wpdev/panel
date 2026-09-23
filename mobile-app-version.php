<?php
/** Public read-only mobile release manifest. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
try {
    require_once __DIR__ . '/system/vendor/autoload.php';
    require_once __DIR__ . '/init.php';
    $release = jmapp_release();
    $file = (string) ($release['filename'] ?? '');
    if (!$release || !preg_match('/^jm-broadband-[0-9]+-[a-f0-9]{16}\.apk$/', $file) ||
        !is_file(__DIR__ . '/mobile-app-releases/' . $file)) {
        http_response_code(404);
        echo json_encode(['error' => 'No mobile release published']);
        exit;
    }
    if (parse_url(APP_URL, PHP_URL_SCHEME) !== 'https')
        throw new RuntimeException('HTTPS is required for updates.');
    echo json_encode([
        'version' => $release['version'],
        'build_number' => (int) $release['build_number'],
        'required_update' => !empty($release['required_update']),
        'download_url' => jmapp_link(),
        'sha256' => $release['sha256'],
        'bytes' => (int) $release['bytes'],
        'notes' => $release['notes'] ?? '',
        'published_at' => $release['published_at'] ?? '',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(503);
    echo json_encode(['error' => 'Update service unavailable']);
}