<?php
_admin();
if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'], true)) {
    _alert(Lang::T('You do not have permission to access this page'), 'danger', 'dashboard');
}
$ui->assign('_title', 'MikroTik Onboarding');
$ui->assign('_system_menu', 'network');
$action = $routes[1] ?? '';

if (in_array($action, ['preview', 'apply'], true)) {
    if (!Csrf::check(_post('csrf_token'))) {
        r2(getUrl('mikrotik-onboarding'), 'e', Lang::T('Invalid or Expired CSRF Token'));
    }
    try {
        if ($action === 'preview') {
            $config = MikrotikProvisioner::normalize($_POST);
            $probe = null;
            if ($config['setup_user'] !== '' && $config['setup_password'] !== '') {
                $probe = MikrotikProvisioner::probe($config);
            }
            $_SESSION['jm_mikrotik_onboarding'] = ['created' => time(), 'config' => $config];
            $ui->assign('preview', MikrotikProvisioner::buildScript($config));
            $ui->assign('rollback', MikrotikProvisioner::rollbackScript($config));
            $ui->assign('probe', $probe);
            $ui->assign('form', $config);
        } else {
            $saved = $_SESSION['jm_mikrotik_onboarding'] ?? null;
            if (!is_array($saved) || time() - (int)($saved['created'] ?? 0) > 600) {
                throw new RuntimeException('Preview expired. Generate a new preview before applying.');
            }
            $config = $saved['config'];
            $result = MikrotikProvisioner::apply($config);
            $routerName = trim(_post('router_name', $result['probe']['identity'] ?: 'MikroTik'));
            if ($routerName === '' || strtolower($routerName) === 'radius') throw new RuntimeException('Invalid router name.');

            $address = $config['host'] . ':' . $config['api_port'];
            $router = ORM::for_table('tbl_routers')->where('ip_address', $address)->find_one();
            if (!$router) $router = ORM::for_table('tbl_routers')->create();
            $router->name = $routerName;
            $router->ip_address = $address;
            $router->username = $config['panel_api_user'];
            $router->password = $config['panel_api_password'];
            $router->description = 'Provisioned by JM Panel | RouterOS ' . ($result['probe']['version'] ?? '');
            $router->enabled = 1;
            $router->save();

            $nas = ORM::for_table('nas', 'radius')->where('nasname', $config['host'])->find_one();
            if (!$nas) $nas = ORM::for_table('nas', 'radius')->create();
            $nas->nasname = $config['host'];
            $nas->shortname = $routerName;
            $nas->type = 'mikrotik';
            $nas->secret = $config['radius_secret'];
            $nas->description = 'Provisioned by JM Panel';
            $nas->routers = $routerName;
            $nas->save();

            unset($_SESSION['jm_mikrotik_onboarding']);
            _log('[' . $admin['username'] . ']: provisioned MikroTik ' . $routerName, $admin['user_type'], $admin['id']);
            r2(getUrl('routers'), 's', 'MikroTik configuration applied and router registered.');
        }
    } catch (Throwable $e) {
        $ui->assign('onboarding_error', $e->getMessage());
        if ($action === 'apply') unset($_SESSION['jm_mikrotik_onboarding']);
    }
}

$ui->assign('csrf_token', Csrf::generateAndStoreToken());
$ui->display('admin/mikrotik-onboarding/index.tpl');
