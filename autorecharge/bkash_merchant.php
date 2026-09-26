<?php
/* bKash Merchant SMS Forwarder webhook. */

require_once '../init.php';
require_once __DIR__ . '/autorecharge.php';

header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    autorecharge_require_webhook_secret(is_array($data) ? $data : []);
    $msg = trim((string) ($data['msg'] ?? ''));

    if (!$data || $msg === '') {
        throw new Exception('Invalid SMS payload');
    }

    /*
     * Example:
     * You have received payment Tk 500.00 from 0157XXXX173. Ref jmbroadband.
     * Fee Tk 0.00. Balance Tk 504.00. TrxID DIJ3MXAHCJ at 19/09/2026 00:57
     */
    $regex = '/You\s+have\s+received\s+payment\s+Tk\s*([0-9,]+(?:\.[0-9]+)?)\s+from\s+([0-9Xx+]+)\.?\s*Ref\s+([A-Za-z0-9._-]+)\.?\s*Fee\s*Tk\s*[0-9,]+(?:\.[0-9]+)?\.?\s*Balance\s*Tk\s*[0-9,]+(?:\.[0-9]+)?\.?\s*TrxID\s*([A-Za-z0-9]+)/i';

    if (!preg_match($regex, $msg, $m)) {
        throw new Exception('Invalid bKash Merchant SMS format');
    }

    $payment = [
        'gateway' => 'bKash Merchant',
        'username' => rtrim(trim($m[3]), '.'),
        'amount' => round((float) str_replace(',', '', $m[1]), 2),
        'sender' => trim($m[2]),
        'trxid' => trim($m[4]),
    ];

    $result = autoRechargeUser($payment);

    $level = (($result['status'] ?? '') === 'success') ? 'SUCCESS' : 'ERROR';
    autorecharge_log(
        'bkash-merchant-webhook.log',
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
    autorecharge_log('bkash-merchant-webhook.log', 'REJECTED: ' . $e->getMessage());

    http_response_code(200);
    echo json_encode([
        'status' => 'error',
        'gateway' => 'bKash Merchant',
        'message' => 'SMS could not be processed: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
