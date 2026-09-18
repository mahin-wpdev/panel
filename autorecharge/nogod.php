<?php
/* Nagad SMS Forwarder webhook. Filename kept as nogod.php for compatibility. */

require_once '../init.php';
require_once __DIR__ . '/autorecharge.php';

header('Content-Type: application/json');

function nogod_parse_sms($msg)
{
    $withRef = '/Money\s+Received\.?\s*Amount:?\s*Tk\s*([0-9,]+(?:\.[0-9]+)?)\s*Sender:?\s*([0-9Xx+]+)\s*Ref:?\s*([A-Za-z0-9._\/-]+)\s*(?:TxnID|TrxID):?\s*([A-Za-z0-9]+)/i';

    if (preg_match($withRef, $msg, $m)) {
        return [
            'amount' => $m[1],
            'sender' => $m[2],
            'ref' => $m[3],
            'trxid' => $m[4],
        ];
    }

    $withoutRef = '/Received\s+Tk\s*([0-9,]+(?:\.[0-9]+)?)\s+from\s+([0-9Xx+]+)\.?\s*TrxID:?\s*([A-Za-z0-9]+)/i';

    if (preg_match($withoutRef, $msg, $m)) {
        return [
            'amount' => $m[1],
            'sender' => $m[2],
            'ref' => '',
            'trxid' => $m[3],
        ];
    }

    return null;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $msg = trim((string) ($data['msg'] ?? ''));

    if (!$data || $msg === '') {
        throw new Exception('Invalid SMS payload');
    }

    $parsed = nogod_parse_sms($msg);
    if (!$parsed) {
        throw new Exception('Invalid Nagad SMS format');
    }

    $ref = trim($parsed['ref']);
    if (in_array(strtoupper($ref), ['N/A', 'NA'], true)) {
        $ref = '';
    }

    $payment = [
        'gateway' => 'Nagad',
        'username' => $ref,
        'amount' => round((float) str_replace(',', '', $parsed['amount']), 2),
        'sender' => $parsed['sender'],
        'trxid' => $parsed['trxid'],
    ];

    $result = autoRechargeUser($payment);

    $level = (($result['status'] ?? '') === 'success') ? 'SUCCESS' : 'ERROR';
    autorecharge_log(
        'nogod-webhook.log',
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
    autorecharge_log('nogod-webhook.log', 'REJECTED: ' . $e->getMessage());

    http_response_code(200);
    echo json_encode([
        'status' => 'error',
        'gateway' => 'Nagad',
        'message' => 'SMS could not be processed: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
