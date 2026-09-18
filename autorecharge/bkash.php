<?php
/* bKash Personal SMS Forwarder webhook. */

require_once '../init.php';
require_once __DIR__ . '/autorecharge.php';

header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $msg = trim((string) ($data['msg'] ?? ''));

    if (!$data || $msg === '') {
        throw new Exception('Invalid SMS payload');
    }

    /* Personal bKash format. "payment" is intentionally NOT optional here,
       so Merchant SMS is handled only by bkash_merchant.php. */
    $regex = '/You\s+have\s+received\s+Tk\s*([0-9,]+(?:\.[0-9]+)?)\s+from\s+([0-9Xx+]+)\.?\s*Ref\s+([A-Za-z0-9._-]+)\.?\s*Fee\s*Tk\s*[0-9,]+(?:\.[0-9]+)?\.?\s*Balance\s*Tk\s*[0-9,]+(?:\.[0-9]+)?\.?\s*TrxID\s*([A-Za-z0-9]+)/i';

    if (!preg_match($regex, $msg, $m)) {
        throw new Exception('Invalid bKash Personal SMS format');
    }

    $payment = [
        'gateway' => 'bKash',
        'username' => rtrim(trim($m[3]), '.'),
        'amount' => round((float) str_replace(',', '', $m[1]), 2),
        'sender' => trim($m[2]),
        'trxid' => trim($m[4]),
    ];

    $result = autoRechargeUser($payment);

    $level = (($result['status'] ?? '') === 'success') ? 'SUCCESS' : 'ERROR';
    autorecharge_log(
        'bkash-webhook.log',
        sprintf(
            '%s | TrxID=%s | Ref=%s | Amount=%s | Sender=%s | Message=%s',
            $level,
            $payment['trxid'],
            $payment['username'],
            $payment['amount'],
            $payment['sender'],
            $result['message'] ?? ''
        )
    );

    http_response_code(200);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    autorecharge_log('bkash-webhook.log', 'REJECTED: ' . $e->getMessage());

    http_response_code(200);
    echo json_encode([
        'status' => 'error',
        'gateway' => 'bKash',
        'message' => 'SMS could not be processed: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
