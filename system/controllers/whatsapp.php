<?php
_admin();
if (!in_array($admin['user_type'], ['SuperAdmin', 'Admin'], true)) {
    _alert(Lang::T('You do not have permission to access this page'), 'danger', 'dashboard');
}

function jmBundledWhatsappRequest(string $method, string $path, ?array $payload = null): array
{
    $base = rtrim((string) getenv('WHATSAPP_INTERNAL_URL'), '/');
    $keyFile = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'secure' . DIRECTORY_SEPARATOR . 'whatsapp-api-key';
    $key = is_readable($keyFile) ? trim((string) file_get_contents($keyFile)) : '';
    if ($base === '' || $key === '') {
        return ['status' => 503, 'body' => '', 'json' => ['success' => false, 'error' => 'Bundled WhatsApp service is not configured']];
    }
    $ch = curl_init($base . $path);
    $headers = ['X-API-Key: ' . $key, 'Accept: application/json'];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CUSTOMREQUEST => $method,
    ];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_HTTPHEADER] = $headers;
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
    curl_setopt_array($ch, $options);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    $json = is_string($body) ? json_decode($body, true) : null;
    if ($error !== '') {
        $json = ['success' => false, 'error' => $error];
    }
    return ['status' => $status ?: 503, 'body' => is_string($body) ? $body : '', 'json' => is_array($json) ? $json : []];
}

$action = $routes[1] ?? '';
if ($action === 'qr') {
    $result = jmBundledWhatsappRequest('GET', '/api/qr?format=png');
    if ($result['status'] === 200 && $result['body'] !== '') {
        header('Content-Type: image/png');
        header('Cache-Control: no-store, max-age=0');
        echo $result['body'];
        exit;
    }
    http_response_code($result['status']);
    header('Content-Type: text/plain; charset=utf-8');
    echo $result['json']['error'] ?? 'QR is not ready';
    exit;
}

if (in_array($action, ['test', 'logout', 'reconnect'], true)) {
    if (!Csrf::check(_post('csrf_token'))) {
        r2(getUrl('whatsapp'), 'e', Lang::T('Invalid or Expired CSRF Token'));
    }
    if ($action === 'test') {
        $phone = trim(_post('phone'));
        $message = trim(_post('message', 'JM Broadband WhatsApp test'));
        if ($phone === '' || $message === '') {
            r2(getUrl('whatsapp'), 'e', 'Phone and message are required');
        }
        $result = jmBundledWhatsappRequest('POST', '/api/send-message', ['to' => Lang::phoneFormat($phone), 'message' => $message]);
        $ok = $result['status'] >= 200 && $result['status'] < 300 && (($result['json']['success'] ?? false) === true);
        r2(getUrl('whatsapp'), $ok ? 's' : 'e', $ok ? 'Test message accepted by WhatsApp service' : ($result['json']['error'] ?? 'WhatsApp test failed'));
    }
    $endpoint = $action === 'logout' ? '/api/logout' : '/api/reconnect';
    $result = jmBundledWhatsappRequest('POST', $endpoint, []);
    $ok = $result['status'] >= 200 && $result['status'] < 300 && (($result['json']['success'] ?? false) === true);
    r2(getUrl('whatsapp'), $ok ? 's' : 'e', $ok ? ucfirst($action) . ' requested successfully' : ($result['json']['error'] ?? 'WhatsApp action failed'));
}

$statusResponse = jmBundledWhatsappRequest('GET', '/api/status');
$messagesResponse = jmBundledWhatsappRequest('GET', '/api/messages?limit=25');
$status = $statusResponse['json']['data'] ?? [
    'status' => 'unavailable',
    'connected' => false,
    'lastError' => $statusResponse['json']['error'] ?? 'Service unavailable',
];
$messages = $messagesResponse['json']['data']['messages'] ?? [];
$counts = $messagesResponse['json']['data']['counts'] ?? [];
$ui->assign('_title', 'WhatsApp');
$ui->assign('wa_status', $status);
$ui->assign('wa_messages', $messages);
$ui->assign('wa_counts', $counts);
$ui->assign('csrf_token', Csrf::generateAndStoreToken());
$ui->display('admin/whatsapp/dashboard.tpl');
