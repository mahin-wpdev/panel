<?php
// Compatibility bridge for legacy wa_url configurations.
// New installations use Message::sendWhatsapp() -> WHATSAPP_INTERNAL_URL directly.
declare(strict_types=1);

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Forbidden');
}

$to = preg_replace('/\D+/', '', (string) ($_GET['number'] ?? ''));
$message = trim((string) ($_GET['text'] ?? ''));
if (strlen($to) < 8 || $message === '') {
    http_response_code(400);
    exit('Invalid recipient or message');
}

$keyFile = __DIR__ . '/system/secure/whatsapp-api-key';
$apiKey = is_readable($keyFile) ? trim((string) file_get_contents($keyFile)) : '';
$baseUrl = rtrim((string) getenv('WHATSAPP_INTERNAL_URL'), '/');
if ($apiKey === '' || $baseUrl === '' || !function_exists('curl_init')) {
    http_response_code(503);
    exit('Bundled WhatsApp service unavailable');
}

$curl = curl_init($baseUrl . '/api/send-message');
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-Key: ' . $apiKey],
    CURLOPT_POSTFIELDS => json_encode(['to' => $to, 'message' => $message], JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 15,
]);
$response = curl_exec($curl);
$status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
$error = curl_error($curl);
curl_close($curl);

if ($response === false || $error !== '') {
    http_response_code(502);
    exit('WhatsApp request failed');
}
http_response_code($status >= 200 && $status < 300 ? 200 : 502);
header('Content-Type: application/json; charset=utf-8');
echo $response;
