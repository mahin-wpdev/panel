<?php
// Internal bridge used by PHPNuxBill's existing GET-based WhatsApp hook.
// It accepts requests only from this server and keeps the Baileys API key
// outside the public web root.
declare(strict_types=1);

if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403);
    exit('Forbidden');
}

$to = preg_replace('/\D+/', '', (string)($_GET['number'] ?? ''));
$message = trim((string)($_GET['text'] ?? ''));
if (strlen($to) < 8 || $message === '') {
    http_response_code(400);
    exit('Invalid recipient or message');
}

$keyFile = '/www/wwwroot/27.147.201.165/.phpnuxbill-whatsapp-api-key';
$apiKey = is_readable($keyFile) ? trim((string)file_get_contents($keyFile)) : '';
if ($apiKey === '' || !function_exists('curl_init')) {
    http_response_code(503);
    exit('WhatsApp service unavailable');
}

$curl = curl_init('http://10.10.10.8/api/send-message');
curl_setopt_array($curl, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-Key: ' . $apiKey],
    CURLOPT_POSTFIELDS => json_encode(['to' => $to, 'message' => $message], JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 3,
    CURLOPT_TIMEOUT => 10,
]);
$response = curl_exec($curl);
$status = (int)curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
curl_close($curl);

http_response_code($status >= 200 && $status < 300 ? 200 : 502);
echo $response ?: 'WhatsApp request failed';
