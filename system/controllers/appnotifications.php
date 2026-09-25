<?php
/** Admin-only direct FCM notifications for Arivo ISP Billing. */
_admin();

if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'], true)) {
    _alert(Lang::T('You do not have permission to access this page'), 'danger', 'dashboard');
}

$ui->assign('_title', 'App Push Notifications');
$ui->assign('_system_menu', 'message');
$ui->assign('_admin', $admin);

require_once $root_path . 'system/mobile/auth-core.php';
require_once $root_path . 'system/mobile/panel-app.php';
require_once $root_path . 'system/mobile/push.php';

$db = ORM::get_db();
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$action = $routes['1'] ?? 'send';
if ($action === '') $action = 'send';

switch ($action) {
    case 'send':
        $csrf = Csrf::generateAndStoreToken();
        $ui->assign('csrf_token', $csrf);

        $pushReady = jm_app_table($db, 'tbl_mobile_push_tokens') &&
            jm_app_table($db, 'tbl_mobile_app_notifications');
        $deviceCount = 0;
        $customerCount = 0;
        $staffCount = 0;
        if ($pushReady) {
            $deviceCount = (int)jm_mobile_query($db,
                "SELECT COUNT(*) FROM tbl_mobile_push_tokens WHERE enabled=1"
            )->fetchColumn();
            $customerCount = (int)jm_mobile_query($db,
                "SELECT COUNT(DISTINCT actor_id) FROM tbl_mobile_push_tokens
                 WHERE actor_type='customer' AND enabled=1"
            )->fetchColumn();
            $staffCount = (int)jm_mobile_query($db,
                "SELECT COUNT(DISTINCT actor_id) FROM tbl_mobile_push_tokens
                 WHERE actor_type='staff' AND enabled=1"
            )->fetchColumn();
        }
        $ui->assign('push_ready', $pushReady);
        $ui->assign('push_device_count', $deviceCount);
        $ui->assign('push_customer_count', $customerCount);
        $ui->assign('push_staff_count', $staffCount);

        $appUrl = APP_URL;
        $select2 = <<<JS
<script>
document.addEventListener("DOMContentLoaded", function() {
    var target = document.getElementById('pushTarget');
    var customerWrap = document.getElementById('customerTargetWrap');
    function syncTarget() {
        if (customerWrap) customerWrap.style.display =
            target && target.value === 'customer' ? '' : 'none';
    }
    if (target) {
        target.addEventListener('change', syncTarget);
        syncTarget();
    }
    $('#pushCustomer').select2({
        theme: "bootstrap",
        width: "100%",
        ajax: {
            url: function(params) {
                if (params.term !== undefined) {
                    return '{$appUrl}/?_route=autoload/customer_select2&s=' +
                        encodeURIComponent(params.term);
                }
                return '{$appUrl}/?_route=autoload/customer_select2';
            }
        }
    });
});
</script>
JS;
        $ui->assign('xfooter', $select2);
        $ui->display('admin/message/app-notifications.tpl');
        break;
    case 'send-post':
        if ($_app_stage === 'Demo') {
            r2(getUrl('appnotifications/send'), 'e',
                'You cannot perform this action in Demo mode');
        }
        if (!Csrf::check(_post('csrf_token'))) {
            r2(getUrl('appnotifications/send'), 'e',
                Lang::T('Invalid or Expired CSRF Token') . '.');
        }
        if (!jm_app_table($db, 'tbl_mobile_push_tokens') ||
            !jm_app_table($db, 'tbl_mobile_app_notifications')) {
            r2(getUrl('appnotifications/send'), 'e',
                'Push notification service is not configured.');
        }

        $target = trim((string)_post('target'));
        $title = trim(strip_tags((string)_post('title')));
        $message = trim(strip_tags((string)_post('message')));
        $customerId = (int)_post('id_customer');

        if (!in_array($target, ['all_customers', 'all_active', 'customer', 'staff'], true)) {
            r2(getUrl('appnotifications/send'), 'e', 'Invalid notification target.');
        }
        if ($title === '' || $message === '') {
            r2(getUrl('appnotifications/send'), 'e', 'Title and message are required.');
        }
        if (mb_strlen($title) > 120 || mb_strlen($message) > 1000) {
            r2(getUrl('appnotifications/send'), 'e',
                'Title or message is too long.');
        }

        set_time_limit(90);
        $sentDevices = 0;
        $recipientCount = 0;
        $failedRecipients = 0;
        $data = [
            'type' => 'panel_message',
            'source' => 'admin',
            'sent_at' => gmdate('c'),
        ];

        if ($target === 'staff') {
            $staffIds = jm_mobile_query($db,
                "SELECT DISTINCT actor_id FROM tbl_mobile_push_tokens
                 WHERE actor_type='staff' AND enabled=1
                 ORDER BY actor_id ASC LIMIT 100"
            )->fetchAll(PDO::FETCH_COLUMN);
            $recipientCount = count($staffIds);
            foreach ($staffIds as $staffId) {
                jm_push_store_alert($db, 'staff', (int)$staffId,
                    $title, $message, (int)$admin['id']);
            }
            $sentDevices = jm_push_send_staff($db, $title, $message, $data);
            if ($sentDevices === 0 && $recipientCount > 0) $failedRecipients = $recipientCount;
        } elseif ($target === 'customer') {
            if ($customerId < 1) {
                r2(getUrl('appnotifications/send'), 'e', 'Select a customer.');
            }
            $customer = jm_mobile_query($db,
                'SELECT id,username,fullname FROM tbl_customers WHERE id=? LIMIT 1',
                [$customerId])->fetch(PDO::FETCH_ASSOC);
            if (!$customer) {
                r2(getUrl('appnotifications/send'), 'e', 'Customer not found.');
            }
            $recipientCount = 1;
            $data['customer_id'] = (string)$customerId;
            jm_push_store_alert($db, 'customer', $customerId,
                $title, $message, (int)$admin['id']);
            $sentDevices = jm_push_send_actor(
                $db, 'customer', $customerId, $title, $message, $data
            );
            if ($sentDevices === 0) $failedRecipients = 1;
        } else {
            $customerWhere = $target === 'all_active' ? " AND c.status='Active'" : '';
            $ids = jm_mobile_query($db,
                "SELECT DISTINCT p.actor_id
                 FROM tbl_mobile_push_tokens p
                 INNER JOIN tbl_customers c ON c.id=p.actor_id
                 WHERE p.actor_type='customer' AND p.enabled=1" .
                 $customerWhere .
                " ORDER BY p.actor_id ASC LIMIT 5000"
            )->fetchAll(PDO::FETCH_COLUMN);
            $recipientCount = count($ids);
            foreach ($ids as $id) {
                jm_push_store_alert($db, 'customer', (int)$id,
                    $title, $message, (int)$admin['id']);
                $sent = jm_push_send_actor(
                    $db, 'customer', (int)$id, $title, $message, $data
                );
                $sentDevices += $sent;
                if ($sent === 0) $failedRecipients++;
            }
        }

        _log(
            'App push notification by ' . $admin['username'] .
            ' target=' . $target .
            ' recipients=' . $recipientCount .
            ' sent_devices=' . $sentDevices .
            ' failed_recipients=' . $failedRecipients .
            ' title=' . $title
        );

        if ($sentDevices < 1) {
            r2(getUrl('appnotifications/send'), 'e',
                'No notification was delivered. The selected users may not have a registered app device.');
        }
        $summary = 'Push sent to ' . $sentDevices . ' device(s)';
        if ($failedRecipients > 0) {
            $summary .= '; ' . $failedRecipients . ' recipient(s) had no successful device delivery';
        }
        r2(getUrl('appnotifications/send'), 's', $summary);
        break;

    default:
        r2(getUrl('appnotifications/send'));
}
