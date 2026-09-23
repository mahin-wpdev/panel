<?php
/** Stable download link used by [[app_download_link]]. */
header('Cache-Control: no-store, max-age=0');
try {
    require_once __DIR__ . '/system/vendor/autoload.php';
    require_once __DIR__ . '/init.php';
    $release = jmapp_release();
    $file = (string) ($release['filename'] ?? '');
    if (!$release || !preg_match('/^jm-broadband-[0-9]+-[a-f0-9]{16}\.apk$/', $file) ||
        !is_file(__DIR__ . '/mobile-app-releases/' . $file)) {
        http_response_code(404);
        exit('No mobile app is currently published.');
    }
    if (parse_url(APP_URL, PHP_URL_SCHEME) !== 'https')
        throw new RuntimeException('HTTPS is required.');
    header('Location: ' . rtrim(APP_URL, '/') . '/mobile-app-releases/' . rawurlencode($file), true, 302);
    exit;
} catch (Throwable $e) {
    http_response_code(503);
    exit('App download is temporarily unavailable.');
}