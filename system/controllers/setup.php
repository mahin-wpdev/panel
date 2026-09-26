<?php
_admin();
if (($admin['user_type'] ?? '') !== 'SuperAdmin') {
    _alert(Lang::T('You do not have permission to access this page'), 'danger', 'dashboard');
}

function jmSetupSetting(string $key, string $value): void
{
    $row = ORM::for_table('tbl_appconfig')->where('setting', $key)->find_one();
    if (!$row) {
        $row = ORM::for_table('tbl_appconfig')->create();
        $row->setting = $key;
    }
    $row->value = $value;
    $row->save();
}

if (($routes[1] ?? '') === 'save') {
    if (!Csrf::check(_post('csrf_token'))) {
        r2(getUrl('setup'), 'e', Lang::T('Invalid or Expired CSRF Token'));
    }
    $company = trim(_post('CompanyName'));
    $timezone = trim(_post('timezone', 'Asia/Dhaka'));
    $currency = trim(_post('currency_code', '৳'));
    $networkMode = trim(_post('network_mode', 'hybrid'));
    $publicHost = trim(_post('public_host'));
    $publicScheme = strtolower(trim(_post('public_scheme', 'https')));
    $publicPort = trim(_post('public_port', $publicScheme === 'https' ? '443' : '80'));
    $billingCycle = trim(_post('billing_cycle', 'monthly'));
    $expiryBehavior = trim(_post('customer_expiry_behavior', 'disable'));
    $invoicePrefix = trim(_post('invoice_prefix', 'INV'));
    $mobileRepo = trim(_post('mobile_repo', 'mahin-wpdev/jm-broadband-android'));
    $releaseChannel = trim(_post('mobile_release_channel', 'stable'));
    if ($company === '') r2(getUrl('setup'), 'e', 'Company name is required');
    if (!in_array($timezone, DateTimeZone::listIdentifiers(), true)) r2(getUrl('setup'), 'e', 'Invalid timezone');
    if (!in_array($publicScheme, ['http', 'https'], true)) r2(getUrl('setup'), 'e', 'Invalid public scheme');
    if ($publicHost !== '' && !filter_var($publicHost, FILTER_VALIDATE_IP) && !preg_match('/^(?=.{1,253}$)(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?\.)*[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?$/', $publicHost)) r2(getUrl('setup'), 'e', 'Invalid public host');
    if (!ctype_digit($publicPort) || (int)$publicPort < 1 || (int)$publicPort > 65535) r2(getUrl('setup'), 'e', 'Invalid public port');
    if (!in_array($billingCycle, ['monthly', '30days'], true)) r2(getUrl('setup'), 'e', 'Invalid billing cycle');
    if (!in_array($expiryBehavior, ['disable', 'redirect'], true)) r2(getUrl('setup'), 'e', 'Invalid expiry behavior');
    if ($invoicePrefix === '' || strlen($invoicePrefix) > 20 || !preg_match('/^[A-Za-z0-9_-]+$/', $invoicePrefix)) r2(getUrl('setup'), 'e', 'Invalid invoice prefix');
    if (!in_array($networkMode, ['mikrotik', 'radius', 'hybrid', 'demo'], true)) r2(getUrl('setup'), 'e', 'Invalid network mode');
    if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $mobileRepo)) r2(getUrl('setup'), 'e', 'Invalid mobile GitHub repository');
    if (!in_array($releaseChannel, ['stable', 'beta'], true)) r2(getUrl('setup'), 'e', 'Invalid mobile release channel');

    $settings = [
        'CompanyName' => $company,
        'phone' => trim(_post('phone')),
        'address' => trim(_post('address')),
        'currency_code' => $currency,
        'timezone' => $timezone,
        'public_host' => $publicHost,
        'public_scheme' => $publicScheme,
        'public_port' => $publicPort,
        'billing_cycle' => $billingCycle,
        'grace_period_days' => (string) max(0, (int) _post('grace_period_days', '0')),
        'customer_expiry_behavior' => $expiryBehavior,
        'language' => trim(_post('language', 'english')),
        'invoice_prefix' => $invoicePrefix,
        'network_mode' => $networkMode,
        'mobile_repo' => $mobileRepo,
        'mobile_release_channel' => $releaseChannel,
        'mobile_required_update_default' => _post('mobile_required_update_default') === '1' ? '1' : '0',
    ];
    foreach ($settings as $key => $value) jmSetupSetting($key, $value);

    if (!empty($_FILES['logo']['name']) && is_uploaded_file($_FILES['logo']['tmp_name'])) {
        $imageInfo = @getimagesize($_FILES['logo']['tmp_name']);
        if (!$imageInfo || !in_array($imageInfo['mime'] ?? '', ['image/png', 'image/jpeg'], true)) {
            r2(getUrl('setup'), 'e', 'Logo must be PNG or JPEG');
        }
        File::resizeCropImage($_FILES['logo']['tmp_name'], $UPLOAD_PATH . DIRECTORY_SEPARATOR . 'logo.png', 1078, 200, 100);
    }

    $user = ORM::for_table('tbl_users')->find_one((int) $admin['id']);
    if ($user) {
        $adminName = trim(_post('admin_name'));
        $adminUsername = trim(_post('admin_username'));
        $adminEmail = trim(_post('admin_email'));
        if ($adminName !== '') $user->fullname = $adminName;
        if ($adminUsername !== '') {
            if (!preg_match('/^[A-Za-z0-9_.@-]{3,45}$/', $adminUsername)) r2(getUrl('setup'), 'e', 'Invalid admin username');
            $user->username = $adminUsername;
        }
        if ($adminEmail !== '' && !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) r2(getUrl('setup'), 'e', 'Invalid recovery email');
        $user->email = $adminEmail;
        $user->phone = trim(_post('admin_phone'));
        $newPassword = (string) _post('admin_password');
        if ($newPassword !== '') {
            if (strlen($newPassword) < 10) r2(getUrl('setup'), 'e', 'Admin password must be at least 10 characters');
            $user->password = sha1($newPassword);
        }
        $user->save();
    }

    $mobileConfig = [
        'repo' => $mobileRepo,
        'channel' => $releaseChannel,
        'required_update_default' => _post('mobile_required_update_default') === '1',
    ];
    $mobileFile = $root_path . File::pathFixer('system/secure/mobile-release.json');
    $mobileDir = dirname($mobileFile);
    if (!is_dir($mobileDir) || !is_writable($mobileDir)) r2(getUrl('setup'), 'e', 'Secure configuration directory is not writable');
    $mobileJson = json_encode($mobileConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $mobileTemp = $mobileFile . '.tmp.' . bin2hex(random_bytes(4));
    if (file_put_contents($mobileTemp, $mobileJson, LOCK_EX) === false || !@chmod($mobileTemp, 0600) || !@rename($mobileTemp, $mobileFile)) {
        @unlink($mobileTemp);
        r2(getUrl('setup'), 'e', 'Failed to save mobile release configuration');
    }

    jmSetupSetting('setup_complete', '1');
    jmSetupSetting('setup_completed_at', date('Y-m-d H:i:s'));
    _log('[' . $admin['username'] . ']: completed first-run setup', $admin['user_type'], $admin['id']);
    if (_post('whatsapp_setup') === 'now') r2(getUrl('whatsapp'), 's', 'Setup saved. Connect WhatsApp now.');
    r2(getUrl('dashboard'), 's', 'Initial setup completed successfully');
}

if (!empty($config['setup_complete'])) {
    r2(getUrl('dashboard'));
}

$ui->assign('_title', 'First-run Setup');
$ui->assign('timezones', DateTimeZone::listIdentifiers());
$ui->assign('setup_admin', $admin);
$ui->assign('current_host', parse_url(APP_URL, PHP_URL_HOST) ?: '');
$ui->assign('current_scheme', parse_url(APP_URL, PHP_URL_SCHEME) ?: 'http');
$ui->assign('csrf_token', Csrf::generateAndStoreToken());
$ui->display('admin/setup/wizard.tpl');
