<?php
/** JM Broadband self-hosted Android release manager. */
register_menu('Mobile App', true, 'mobileApp', 'AFTER_SETTINGS', 'glyphicon glyphicon-phone', '', 'success', ['Admin', 'SuperAdmin']);

function jmapp_release(): array {
    $row = ORM::for_table('tbl_appconfig')->where('setting', 'jm_mobile_release')->find_one();
    $data = $row ? json_decode((string) $row['value'], true) : null;
    return is_array($data) ? $data : [];
}
function jmapp_link(): string {
    $release = jmapp_release();
    return $release && defined('APP_URL') ? rtrim(APP_URL, '/') . '/mobile-app-download.php' : '';
}
function jmapp_replace_placeholder(string $text): string {
    if (strpos($text, '[[app_download_link]]') === false) return $text;
    return str_replace('[[app_download_link]]', jmapp_link(), $text);
}
function jmapp_save(array $release): void {
    $row = ORM::for_table('tbl_appconfig')->where('setting', 'jm_mobile_release')->find_one();
    if (!$row) {
        $row = ORM::for_table('tbl_appconfig')->create();
        $row->setting = 'jm_mobile_release';
    }
    $row->value = json_encode($release, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if ($row->save() === false) throw new RuntimeException('Unable to save release settings.');
}
function mobileApp(): void {
    global $ui, $admin;
    _admin();
    if (!$admin || !in_array($admin['user_type'], ['Admin', 'SuperAdmin'], true)) {
        http_response_code(403);
        exit('Forbidden');
    }
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['jmapp_csrf'])) $_SESSION['jmapp_csrf'] = bin2hex(random_bytes(32));
    $release = jmapp_release();
    $error = '';
    $success = '';
    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $newFile = null;
        try {
            if (empty($_POST['csrf']) || !hash_equals($_SESSION['jmapp_csrf'], (string) $_POST['csrf']))
                throw new RuntimeException('Session expired; refresh and retry.');
            $notes = trim((string) ($_POST['notes'] ?? ''));
            if (strlen($notes) > 4000) throw new RuntimeException('Release notes exceed 4000 bytes.');
            $new = $release;
            $upload = $_FILES['apk'] ?? null;
            $hasFile = $upload && (int) $upload['error'] !== UPLOAD_ERR_NO_FILE;
            if ($hasFile) {
                if ((int) $upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name']))
                    throw new RuntimeException('Upload failed; inspect PHP/Nginx upload limits.');
                $size = (int) $upload['size'];
                if ($size < 10000 || $size > 250 * 1024 * 1024 ||
                    !preg_match('/\.apk$/i', (string) $upload['name']))
                    throw new RuntimeException('Upload a 10KB to 250MB .apk file.');
                $version = trim((string) ($_POST['version'] ?? ''));
                $build = filter_var($_POST['build_number'] ?? null, FILTER_VALIDATE_INT);
                if (!preg_match('/^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$/', $version) ||
                    $build === false || $build < 1)
                    throw new RuntimeException('Provide the actual APK version and positive build number.');
                if ($release && $build <= (int) $release['build_number'])
                    throw new RuntimeException('New build must exceed currently published build.');
                if (!class_exists('ZipArchive'))
                    throw new RuntimeException('PHP zip extension is required.');
                $zip = new ZipArchive();
                if ($zip->open($upload['tmp_name'], ZipArchive::CHECKCONS) !== true)
                    throw new RuntimeException('APK archive is invalid.');
                $valid = $zip->locateName('AndroidManifest.xml') !== false &&
                    $zip->locateName('classes.dex') !== false;
                $zip->close();
                if (!$valid) throw new RuntimeException('APK manifest or DEX is missing.');
                $dir = dirname(__DIR__, 2) . '/mobile-app-releases';
                if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir))
                    throw new RuntimeException('Cannot create release directory.');
                $filename = 'jm-broadband-' . $build . '-' . bin2hex(random_bytes(8)) . '.apk';
                $newFile = $dir . '/' . $filename;
                if (!move_uploaded_file($upload['tmp_name'], $newFile))
                    throw new RuntimeException('Cannot save APK; check web-user permissions.');
                chmod($newFile, 0644);
                $new = ['version' => $version, 'build_number' => $build,
                    'filename' => $filename, 'sha256' => hash_file('sha256', $newFile),
                    'bytes' => filesize($newFile), 'published_at' => gmdate('c')];
            } elseif (!$release) {
                throw new RuntimeException('Upload an APK before publishing the first release.');
            }
            $new['required_update'] = isset($_POST['required_update']);
            $new['notes'] = $notes;
            jmapp_save($new);
            $release = $new;
            $success = $hasFile ? 'New APK published.' : 'Release settings saved.';
        } catch (Throwable $e) {
            if ($newFile && is_file($newFile)) unlink($newFile);
            $error = $e->getMessage();
        }
    }
    $ui->assign('_title', 'Mobile App');
    $ui->assign('_system_menu', 'plugin/mobileApp');
    $ui->assign('jmapp_release', $release);
    $ui->assign('jmapp_link', jmapp_link());
    $ui->assign('jmapp_csrf', $_SESSION['jmapp_csrf']);
    $ui->assign('jmapp_error', $error);
    $ui->assign('jmapp_success', $success);
    $ui->display('mobileApp.tpl');
}