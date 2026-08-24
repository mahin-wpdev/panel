<?php
/*
|--------------------------------------------------------------------------
| bkash.php
|--------------------------------------------------------------------------
|
| Webhook endpoint for SmsForwarder (bKash "Cash In / Received" SMS).
|
| Point SmsForwarder's bKash rule at:
|   https://yourdomain.com/path/to/webhook/bkash.php
|
| Expected bKash SMS format:
|
|   You have received Tk 500.00 from 01XXXXXXXXX. Ref USER123.
|   Fee Tk 0.00. Balance Tk 1,730.50. TrxID P9K8L7M6N5
|
| The Ref value is treated as the PHPNuxBill username.
*/

require_once "../init.php";
require_once __DIR__ . "/autorecharge.php";

header('Content-Type: application/json');

$input = file_get_contents("php://input");

file_put_contents(
    __DIR__ . '/bkash-debug.log',
    date('Y-m-d H:i:s') . "\nRAW:\n" . $input . "\n\n",
    FILE_APPEND
);

try {

    $data = json_decode($input, true);

    if (!$data) {
        throw new Exception("Invalid JSON");
    }

    $msg = trim($data['msg'] ?? '');

    if ($msg === '') {
        throw new Exception("Empty SMS");
    }

    /*
    |----------------------------------------------------------------------
    | Parse bKash SMS
    |----------------------------------------------------------------------
    |
    | Supports:
    |   - decimal amounts (500.00)
    |   - comma-separated amounts/balances (1,730.50)
    |   - flexible whitespace
    |   - case-insensitive matching
    */

    $regex = '/You\s+have\s+received\s+Tk\s*([0-9,]+(?:\.[0-9]+)?)\s+from\s+([0-9]+)\.?\s*Ref\s+([A-Za-z0-9_-]+)\.?\s*Fee\s*Tk\s*[0-9,]+(?:\.[0-9]+)?\.?\s*Balance\s*Tk\s*[0-9,]+(?:\.[0-9]+)?\.?\s*TrxID\s*([A-Za-z0-9]+)/i';

    if (!preg_match($regex, $msg, $match)) {
        throw new Exception("SMS format invalid");
    }

    $rawAmount = str_replace(',', '', $match[1]);
    $amount    = (int) round((float) $rawAmount); // Tk are whole-taka plan prices
    $sender    = trim($match[2]);
    $username  = trim($match[3]);
    $trxid     = trim($match[4]);

    file_put_contents(
        __DIR__ . '/bkash-webhook.log',
        date('Y-m-d H:i:s') . " PARSED SUCCESS\n" .
        "USERNAME: {$username}\n" .
        "AMOUNT: {$amount}\n" .
        "SENDER: {$sender}\n" .
        "TRXID: {$trxid}\n\n",
        FILE_APPEND
    );

    $payment = [
        'gateway'  => 'bKash',
        'username' => $username,
        'amount'   => $amount,
        'sender'   => $sender,
        'trxid'    => $trxid,
        'raw_sms'  => $msg,
    ];

    $result = autoRechargeUser($payment);

    file_put_contents(
        __DIR__ . '/bkash-webhook.log',
        date('Y-m-d H:i:s') . " RESULT\n" . print_r($result, true) . "\n\n",
        FILE_APPEND
    );

    http_response_code(200);
    echo json_encode($result);

} catch (Throwable $e) {

    file_put_contents(
        __DIR__ . '/bkash-webhook.log',
        date('Y-m-d H:i:s') . " ERROR\n" .
        "MESSAGE: " . $e->getMessage() . "\n" .
        "FILE: " . $e->getFile() . "\n" .
        "LINE: " . $e->getLine() . "\n\n",
        FILE_APPEND
    );

    // Return HTTP 200 so SmsForwarder does not retry endlessly.
    http_response_code(200);

    echo json_encode([
        "status"  => "error",
        "gateway" => "bKash",
        // Deliberately generic: no file paths / stack traces in the response.
        "message" => $e->getMessage(),
    ]);
}
