<?php
/** Stable public link: the APK always remains hosted and controlled by GitHub. */
header('Cache-Control: no-store, max-age=0');
header('Referrer-Policy: no-referrer');
try {
    require_once __DIR__ . '/system/mobile/github-release.php';
    $release = jmapp_latest_release();
    header('Location: ' . $release['github_url'], true, 302);
} catch (OutOfBoundsException $e) {
    http_response_code(404);
    exit('No public GitHub APK release.');
} catch (Throwable $e) {
    http_response_code(503);
    exit('GitHub download temporarily unavailable.');
}
