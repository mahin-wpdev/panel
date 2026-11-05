<?php
/*
|--------------------------------------------------------------------------
| nogod.php
|--------------------------------------------------------------------------
|
| Webhook endpoint for SmsForwarder (Nagad "Money Received" SMS).
|
| Point SmsForwarder's Nagad rule at:
|   https://yourdomain.com/path/to/webhook/nogod.php
|
| Supports THREE real-world Nagad SMS formats:
|
| Format 1 (no Ref at all):
|   Received Tk 500.00 from 01YYYYYYYYY. TrxID: P9K8L7M6N5.
|   Balance: Tk 1,730.50. Date: 26/08/2026.
|   -> customer looked up by sender phone number
|
| Format 2 (valid Ref):
|   Money Received. Amount: Tk 820.00 Sender: 01776159990 Ref: USER123
|   TxnID: 75ODNNGC Balance: Tk 822.45 15/07/2026 18:38
|   -> customer looked up by username (Ref)
|
| Format 3 (Ref present but "N/A"):
|   Money Received. Amount: Tk 820.00 Sender: 01776159990 Ref: N/A
|   TxnID: 75ODNNGC Balance: Tk 822.45 15/07/2026 18:38
|   -> "N/A" is NOT a username; customer looked up by sender phone number
*/

require_once "../init.php";
require_once __DIR__ . "/autorecharge.php";

header('Content-Type: application/json');

$input = file_get_contents("php://input");

file_put_contents(
    __DIR__ . '/nogod-debug.log',
    date('Y-m-d H:i:s') . "\nRAW:\n" . $input . "\n\n",
    FILE_APPEND
);

/**
 * Parse a Nagad SMS in either supported format.
 *
 * Returns an array with keys: amount, sender, ref, trxid, balance, date, time
 * (balance/date/time may be null when not present), or null if the message
 * doesn't match any known Nagad format.
 */
function nogod_parse_sms($msg)
{
    // ---- Format 2 / 3: "Money Received. Amount: Tk ... Sender: ... Ref: ... TxnID: ... Balance: Tk ... DD/MM/YYYY HH:MM"

    $regexWithRef = '/Money\s+Received\.?\s*'
        . 'Amount:?\s*Tk\s*([0-9,]+(?:\.[0-9]+)?)\s*'
        . 'Sender:?\s*([0-9]+)\s*'
        . 'Ref:?\s*([A-Za-z0-9\/]+)\s*'
        . '(?:TxnID|TrxID):?\s*([A-Za-z0-9]+)\s*'
        . 'Balance:?\s*Tk\s*([0-9,]+(?:\.[0-9]+)?)\s*'
        . '([0-9]{1,2}\/[0-9]{1,2}\/[0-9]{4})\s+([0-9]{1,2}:[0-9]{2})/i';

    if (preg_match($regexWithRef, $msg, $m)) {
        return [
            'amount'  => $m[1],
            'sender'  => trim($m[2]),
            'ref'     => trim($m[3]),
            'trxid'   => trim($m[4]),
            'balance' => $m[5],
            'date'    => $m[6],
            'time'    => $m[7],
        ];
    }

    // ---- Format 1: "Received Tk ... from ... TrxID: ... Balance: Tk ... Date: DD/MM/YYYY"

    $regexNoRef = '/Received\s+Tk\s*([0-9,]+(?:\.[0-9]+)?)\s+from\s+([0-9]+)\.?\s*'
        . 'TrxID:?\s*([A-Za-z0-9]+)\.?\s*'
        . '(?:Balance:?\s*Tk\s*([0-9,]+(?:\.[0-9]+)?)\.?)?\s*'
        . '(?:Date:?\s*([0-9]{1,2}\/[0-9]{1,2}\/[0-9]{4}))?/i';

    if (preg_match($regexNoRef, $msg, $m)) {
        return [
            'amount'  => $m[1],
            'sender'  => trim($m[2]),
            'ref'     => '',
            'trxid'   => trim($m[3]),
            'balance' => $m[4] ?? null,
            'date'    => $m[5] ?? null,
            'time'    => null,
        ];
    }

    return null;
}

try {

    $data = json_decode($input, true);

    if (!$data) {
        throw new Exception("Invalid JSON");
    }

    $msg = trim($data['msg'] ?? '');

    if ($msg === '') {
        throw new Exception("Empty SMS");
    }

    $parsed = nogod_parse_sms($msg);

    if (!$parsed) {
        throw new Exception("SMS format invalid");
    }

    $amount = (int) round((float) str_replace(',', '', $parsed['amount']));
    $sender = $parsed['sender'];
    $trxid  = $parsed['trxid'];

    // "N/A" / "NA" / empty Ref is NOT a username - treated as no Ref.
    $refRaw    = trim((string) $parsed['ref']);
    $isValidRef = $refRaw !== '' && !in_array(strtoupper($refRaw), ['N/A', 'NA'], true);
    $username   = $isValidRef ? $refRaw : '';

    $balance = $parsed['balance'] !== null ? str_replace(',', '', $parsed['balance']) : null;

    file_put_contents(
        __DIR__ . '/nogod-webhook.log',
        date('Y-m-d H:i:s') . " PARSED SUCCESS\n" .
        "REF: " . ($refRaw !== '' ? $refRaw : '(none)') . "\n" .
        "USERNAME_USED: " . ($username !== '' ? $username : '(fallback to phone)') . "\n" .
        "AMOUNT: {$amount}\n" .
        "SENDER: {$sender}\n" .
        "TRXID: {$trxid}\n" .
        "BALANCE: " . ($balance ?? '(n/a)') . "\n" .
        "DATE: " . ($parsed['date'] ?? '(n/a)') . "\n" .
        "TIME: " . ($parsed['time'] ?? '(n/a)') . "\n\n",
        FILE_APPEND
    );

    $payment = [
        'gateway'  => 'Nagad',
        'username' => $username,
        'amount'   => $amount,
        'sender'   => $sender,
        'trxid'    => $trxid,
        'raw_sms'  => $msg,
    ];

    $result = autoRechargeUser($payment);

    file_put_contents(
        __DIR__ . '/nogod-webhook.log',
        date('Y-m-d H:i:s') . " RESULT\n" . print_r($result, true) . "\n\n",
        FILE_APPEND
    );

    http_response_code(200);
    echo json_encode($result);

} catch (Throwable $e) {

    file_put_contents(
        __DIR__ . '/nogod-webhook.log',
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
        "gateway" => "Nagad",
        "message" => $e->getMessage(),
    ]);
}
