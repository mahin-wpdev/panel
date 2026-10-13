<?php
/*
 * Shared SMS-forwarder recharge engine.
 * Gateway-agnostic: new gateways do not need to be added to this file.
 * No wallet is used.
 */

if (!function_exists('autorecharge_log')) {

function autorecharge_log($file, $message)
{
    @file_put_contents(
        __DIR__ . '/' . $file,
        date('Y-m-d H:i:s') . ' ' . $message . "\n",
        FILE_APPEND | LOCK_EX
    );
}


function autorecharge_gateway($gateway)
{
    /*
     * Gateway names are supplied by trusted webhook/parser files.
     * Keep the engine gateway-agnostic so adding Rocket/Upay/etc. does not
     * require editing this shared file. Remove control characters only.
     */
    $gateway = trim((string) $gateway);
    $gateway = preg_replace('/[\x00-\x1F\x7F]+/u', '', $gateway);
    $gateway = preg_replace('/\s+/u', ' ', $gateway);

    return trim((string) $gateway);
}

function autorecharge_require_webhook_secret(array $data)
{
    $config = ORM::for_table('tbl_appconfig')
        ->where('setting', 'auto_payment_sms_secret')
        ->find_one();
    $expected = $config ? trim((string) $config->value) : '';
    $provided = trim((string) (
        $_SERVER['HTTP_X_WEBHOOK_SECRET']
        ?? $_SERVER['HTTP_X_API_KEY']
        ?? ($data['secret'] ?? ($_GET['secret'] ?? ''))
    ));

    if ($expected !== '' && $provided !== '' && hash_equals($expected, $provided)) {
        return;
    }

    http_response_code(403);
    echo json_encode([
        'status' => 'error',
        'message' => 'Webhook authentication failed',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}


function autorecharge_phone($phone)
{
    $digits = preg_replace('/\D+/', '', (string) $phone);
    return strlen($digits) >= 10 ? substr($digits, -10) : $digits;
}

function autorecharge_username($username)
{
    /* Keep internal dots (e.g. shahin.liku), remove sentence punctuation. */
    return strtolower(trim((string) $username, " \t\n\r\0\x0B.,;:"));
}

function autorecharge_find_customer($username, $sender)
{
    $username = autorecharge_username($username);

    if ($username !== '' && !in_array(strtoupper($username), ['N/A', 'NA'], true)) {
        $customers = ORM::for_table('tbl_customers')
            ->where_raw(
                '(LOWER(username) = ? OR LOWER(pppoe_username) = ?)',
                [$username, $username]
            )
            ->find_many();

        if (count($customers) === 1) {
            return $customers[0];
        }

        /* A matching reference is authoritative; never guess between users. */
        if (count($customers) > 1) {
            return null;
        }
    }

    /*
     * Some payment references contain a display name or a typo instead of
     * the account username. Use the payer phone only when it identifies one
     * customer, and refuse ambiguous matches rather than charging at random.
     */
    $last10 = autorecharge_phone($sender);
    if (strlen($last10) < 10) {
        return null;
    }

    $customers = ORM::for_table('tbl_customers')
        ->where_raw(
            "RIGHT(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(phonenumber,'+',''),' ',''),'-',''),'(',''),')',''),'.',''),10)=?",
            [$last10]
        )
        ->find_many();

    return count($customers) === 1 ? $customers[0] : null;
}

function autorecharge_find_plan($amount, $customer)
{
    $amount = round((float) $amount, 2);
    $minimumPrice = max(0, $amount - 50);

    /* If the payer already has an active PPPOE plan, a small overpayment is
     * treated as renewal of that same plan, even when another plan happens
     * to have the exact received amount as its price. */
    $current = autorecharge_find_current_plan_candidate($amount, $customer);
    if (!empty($current['error'])) {
        return ['plan' => null, 'error' => $current['error']];
    }
    if (!empty($current['candidate'])) {
        return autorecharge_select_plan_candidate([$current['candidate']], $amount);
    }

    /* With no current-plan match, exact price wins before general tolerance. */
    $exact = autorecharge_find_plan_candidates($amount, $customer, true);
    if (count($exact) > 0) {
        return autorecharge_select_plan_candidate($exact, $amount);
    }

    /* For a small overpayment, choose the closest eligible package below it. */
    $tolerant = autorecharge_find_plan_candidates($minimumPrice, $customer, false, $amount);
    if (count($tolerant) === 0) {
        return [
            'plan' => null,
            'error' => 'No eligible PPPOE package matches this amount or the Tk 50 overpayment range',
        ];
    }

    $highestPrice = max(array_column($tolerant, 'price'));
    $nearest = array_values(array_filter($tolerant, function ($candidate) use ($highestPrice) {
        return (float) $candidate['price'] === (float) $highestPrice;
    }));

    return autorecharge_select_plan_candidate($nearest, $amount);
}

function autorecharge_find_current_plan_candidate($amount, $customer)
{
    $activePlans = ORM::for_table('tbl_user_recharges')
        ->table_alias('r')
        ->select('p.*')
        ->join('tbl_plans', ['p.id', '=', 'r.plan_id'], 'p')
        ->where('r.customer_id', $customer->id())
        ->where('r.status', 'on')
        ->where('p.type', 'PPPOE')
        ->where('p.enabled', 1)
        ->find_many();

    if (count($activePlans) > 1) {
        return ['candidate' => null, 'error' => 'Multiple active PPPOE packages found for this customer'];
    }
    if (count($activePlans) === 0) {
        return ['candidate' => null, 'error' => null];
    }

    $plan = $activePlans[0];
    $price = (float) $plan->price;
    if (!empty($customer->reseller_id)) {
        $resellerPrices = ORM::for_table('tbl_reseller_packages')
            ->where('reseller_id', $customer->reseller_id)
            ->where('plan_id', $plan->id)
            ->where('status', 1)
            ->find_many();
        if (count($resellerPrices) > 1) {
            return ['candidate' => null, 'error' => 'Multiple reseller prices found for this customer package'];
        }
        if (count($resellerPrices) === 1) {
            $price = (float) $resellerPrices[0]->customer_price;
        }
    }

    if ($price > 0 && $amount >= $price && $amount <= $price + 50) {
        return [
            'candidate' => ['plan' => $plan, 'price' => $price],
            'error' => null,
        ];
    }

    return ['candidate' => null, 'error' => null];
}

function autorecharge_select_plan_candidate($candidates, $amount)
{
    if (count($candidates) !== 1) {
        return [
            'plan' => null,
            'error' => 'Multiple eligible PPPOE packages match this amount',
        ];
    }

    return [
        'plan' => $candidates[0]['plan'],
        'package_price' => (float) $candidates[0]['price'],
        'amount_received' => round((float) $amount, 2),
        'overpayment' => round((float) $amount - (float) $candidates[0]['price'], 2),
        'error' => null,
    ];
}

function autorecharge_find_plan_candidates($minimumPrice, $customer, $exact, $maximumPrice = null)
{
    $plans = [];

    /* Reseller customer pricing takes precedence over global package pricing. */
    if (!empty($customer->reseller_id)) {
        $query = ORM::for_table('tbl_reseller_packages')
            ->table_alias('rp')
            ->select('p.*')
            ->select('rp.customer_price', 'customer_price')
            ->join('tbl_plans', ['p.id', '=', 'rp.plan_id'], 'p')
            ->where('rp.reseller_id', $customer->reseller_id)
            ->where('rp.status', 1)
            ->where('p.type', 'PPPOE')
            ->where('p.enabled', 1);

        if ($exact) {
            $query->where('rp.customer_price', $minimumPrice);
        } else {
            $query->where_gte('rp.customer_price', $minimumPrice)
                ->where_lte('rp.customer_price', $maximumPrice);
        }

        foreach ($query->find_many() as $plan) {
            $plans[] = [
                'plan' => $plan,
                'price' => (float) $plan->customer_price,
            ];
        }
        if (count($plans) > 0) {
            return $plans;
        }
    }

    $query = ORM::for_table('tbl_plans')
        ->where('type', 'PPPOE')
        ->where('enabled', 1);

    if ($exact) {
        $query->where('price', $minimumPrice);
    } else {
        $query->where_gte('price', $minimumPrice)
            ->where_lte('price', $maximumPrice);
    }

    foreach ($query->find_many() as $plan) {
        $plans[] = ['plan' => $plan, 'price' => (float) $plan->price];
    }

    return $plans;
}

function autorecharge_router()
{
    $config = ORM::for_table('tbl_appconfig')
        ->where('setting', 'auto_payment_router_name')
        ->find_one();

    if ($config && trim((string) $config->value) !== '') {
        return ['name' => trim((string) $config->value), 'error' => null];
    }

    $routers = ORM::for_table('tbl_routers')
        ->where('enabled', 1)
        ->find_many();

    return count($routers) === 1
        ? ['name' => $routers[0]->name, 'error' => null]
        : [
            'name' => null,
            'error' => 'Configure auto_payment_router_name; exactly one enabled router is required.',
        ];
}

function autorecharge_lock($key)
{
    $lockName = 'autorecharge_' . hash('sha256', $key);
    $row = ORM::for_table('tbl_appconfig')
        ->raw_query('SELECT GET_LOCK(?,5) AS locked', [$lockName])
        ->find_one();

    return $row && (int) $row->locked === 1;
}

function autorecharge_unlock($key)
{
    $lockName = 'autorecharge_' . hash('sha256', $key);

    try {
        ORM::for_table('tbl_appconfig')
            ->raw_query('SELECT RELEASE_LOCK(?) AS unlocked', [$lockName])
            ->find_one();
    } catch (Throwable $e) {
        autorecharge_log('autorecharge.log', 'LOCK_RELEASE_ERROR message=' . $e->getMessage());
    }
}

function autorecharge_receipt($gateway, $trxid, $amount)
{
    try {
        $row = ORM::for_table('tbl_autorecharge_receipts')->create();
        $row->gateway = $gateway;
        $row->trxid = $trxid;
        $row->amount = $amount;
        $row->status = 'processing';
        $row->received_at = date('Y-m-d H:i:s');
        $row->save();

        return ['new' => true, 'row' => $row];
    } catch (Throwable $insertError) {
        $row = ORM::for_table('tbl_autorecharge_receipts')
            ->where('gateway', $gateway)
            ->where('trxid', $trxid)
            ->find_one();

        /* Do not hide unrelated DB/schema errors as a duplicate. */
        if (!$row) {
            throw $insertError;
        }

        /* Explicitly marked preflight errors are safe to retry: Package was
         * never called, so no recharge transaction could have been created. */
        if (autorecharge_receipt_can_retry($row)) {
            $row->amount = $amount;
            $row->customer_id = null;
            $row->status = 'processing';
            $row->error_message = null;
            $row->received_at = date('Y-m-d H:i:s');
            $row->completed_at = null;
            $row->save();

            return ['new' => true, 'row' => $row];
        }

        return ['new' => false, 'row' => $row];
    }
}

function autorecharge_receipt_can_retry($row)
{
    if (!$row || $row->status !== 'error' || !empty($row->transaction_id)) {
        return false;
    }

    if (strpos((string) $row->error_message, 'SAFE_RETRY:') === 0) {
        return true;
    }

    /* Legacy customer-not-found receipts predate SAFE_RETRY markers. */
    return empty($row->customer_id) && $row->error_message === 'Customer not found';
}

/* Preserve the production bKash claim shared with the separate JTEX payment
 * service. This file is installed only on the production host; local/staging
 * environments without that integration continue to use the Panel ledger. */
function autorecharge_reserve_external_payment($gateway, $trxid)
{
    if ($gateway !== 'bKash') {
        return true;
    }

    $claimsFile = '/www/server/jtex-payments/shared-claims.php';
    if (!is_file($claimsFile)) {
        return true;
    }

    require_once $claimsFile;
    if (!function_exists('jtex_payment_reserve')) {
        throw new Exception('Shared payment claim service is unavailable');
    }

    return jtex_payment_reserve($trxid, 'panel');
}

/*
 * Save a successful receipt. Clean installs should use VARCHAR for
 * transaction_id (see migration.sql). For old installs where it is still INT,
 * fall back to the numeric suffix of values such as INV-116 so the webhook
 * does not fail after PHPNuxBill has already completed the recharge.
 */
function autorecharge_save_success_receipt($row, $transactionId)
{
    $row->status = 'success';
    $row->transaction_id = (string) $transactionId;
    $row->error_message = null;
    $row->completed_at = date('Y-m-d H:i:s');

    try {
        $row->save();
        return;
    } catch (Throwable $firstError) {
        $message = $firstError->getMessage();

        if (
            stripos($message, 'transaction_id') !== false &&
            preg_match('/([0-9]+)$/', (string) $transactionId, $m)
        ) {
            $row->transaction_id = (int) $m[1];
            $row->save();

            autorecharge_log(
                'autorecharge.log',
                'SCHEMA_WARNING transaction_id is numeric; stored legacy numeric suffix=' .
                $m[1] . ' original=' . $transactionId .
                ' | Run migration.sql to change transaction_id to VARCHAR(100).'
            );
            return;
        }

        throw $firstError;
    }
}

function autorecharge_mark_error($receiptRow, $message)
{
    if (!$receiptRow) {
        return;
    }

    try {
        $receiptRow->status = 'error';
        $receiptRow->error_message = substr((string) $message, 0, 255);
        $receiptRow->completed_at = date('Y-m-d H:i:s');
        $receiptRow->save();
    } catch (Throwable $ledgerError) {
        /* Never allow receipt logging failure to hide the original error. */
        autorecharge_log(
            'autorecharge.log',
            'LEDGER_ERROR while_marking_error original=' . $message .
            ' ledger=' . $ledgerError->getMessage()
        );
    }
}

function autoRechargeUser(array $payment)
{
    $gateway = autorecharge_gateway($payment['gateway'] ?? '');
    $username = autorecharge_username($payment['username'] ?? '');
    $amount = round((float) ($payment['amount'] ?? 0), 2);
    $sender = trim((string) ($payment['sender'] ?? ''));
    $trxid = strtoupper(trim((string) ($payment['trxid'] ?? '')));

    if (
        $gateway === '' ||
        $amount <= 0 ||
        $trxid === '' ||
        ($username === '' && $sender === '')
    ) {
        autorecharge_log(
            'autorecharge.log',
            "ERROR gateway={$gateway} reference={$username} amount={$amount} trxid={$trxid} message=Invalid payment details"
        );

        return [
            'status' => 'error',
            'gateway' => $gateway,
            'trxid' => $trxid,
            'message' => 'Invalid payment details',
        ];
    }

    $lockKey = $gateway . '_' . $trxid;

    if (!autorecharge_lock($lockKey)) {
        return [
            'status' => 'success',
            'gateway' => $gateway,
            'trxid' => $trxid,
            'message' => 'Already processing',
        ];
    }

    $receipt = null;
    $rechargeCompleted = false;
    $retrySafe = true;
    $transactionId = null;

    try {
        $receipt = autorecharge_receipt($gateway, $trxid, $amount);

        if (!$receipt['new']) {
            $existing = $receipt['row'];
            $isSuccess = $existing && $existing->status === 'success';

            return [
                'status' => $isSuccess ? 'success' : 'error',
                'gateway' => $gateway,
                'trxid' => $trxid,
                'message' => $isSuccess
                    ? 'Already processed'
                    : 'A previous processing attempt requires administrator review',
            ];
        }

        $customer = autorecharge_find_customer($username, $sender);
        if (!$customer) {
            throw new Exception('Customer not found');
        }

        $receipt['row']->customer_id = $customer->id();
        $receipt['row']->save();

        $router = autorecharge_router();
        if ($router['error']) {
            throw new Exception($router['error']);
        }

        $found = autorecharge_find_plan($amount, $customer);
        if ($found['error']) {
            throw new Exception($found['error']);
        }

        $note = "Auto Payment\n" .
            "Gateway: {$gateway}\n" .
            "Sender: {$sender}\n" .
            "TrxID: {$trxid}";

        /* Do not let a bKash reference already claimed by the external JTEX
         * payment flow reach Package::rechargeUser(). */
        if ($gateway === 'bKash') {
            $retrySafe = false;
            if (!autorecharge_reserve_external_payment($gateway, $trxid)) {
                throw new Exception('Transaction reserved for another payment system');
            }
        }

        $retrySafe = false;
        $transactionId = Package::rechargeUser(
            $customer->id(),
            $router['name'],
            $found['plan']->id(),
            $gateway . ' SMS',
            'SmsForwarder',
            $note
        );

        if (!$transactionId) {
            throw new Exception('PHPNuxBill recharge did not return a transaction ID');
        }

        /* From this point onward the actual recharge has completed. */
        $rechargeCompleted = true;

        try {
            autorecharge_save_success_receipt($receipt['row'], $transactionId);
        } catch (Throwable $ledgerError) {
            /* Critical: do not report recharge as failed after it already ran. */
            autorecharge_log(
                'autorecharge.log',
                "LEDGER_WARNING gateway={$gateway} customer={$customer->id()} amount={$amount} " .
                "trxid={$trxid} transaction={$transactionId} message=" . $ledgerError->getMessage()
            );
        }

        autorecharge_log(
            'autorecharge.log',
            "SUCCESS gateway={$gateway} customer={$customer->id()} amount={$amount} " .
            "trxid={$trxid} transaction={$transactionId}"
        );

        return [
            'status' => 'success',
            'gateway' => $gateway,
            'message' => 'Recharge completed',
            'username' => $customer->username,
            'amount' => $amount,
            'trxid' => $trxid,
            'transaction_id' => $transactionId,
        ];
    } catch (Throwable $e) {
        /* If PHPNuxBill already recharged, never convert it into a retryable failure. */
        if ($rechargeCompleted) {
            autorecharge_log(
                'autorecharge.log',
                "POST_RECHARGE_WARNING gateway={$gateway} reference={$username} amount={$amount} " .
                "trxid={$trxid} transaction={$transactionId} message=" . $e->getMessage()
            );

            return [
                'status' => 'success',
                'gateway' => $gateway,
                'message' => 'Recharge completed; receipt logging needs administrator review',
                'amount' => $amount,
                'trxid' => $trxid,
                'transaction_id' => $transactionId,
            ];
        }

        if (is_array($receipt) && isset($receipt['row'])) {
            $message = $retrySafe ? 'SAFE_RETRY: ' . $e->getMessage() : $e->getMessage();
            autorecharge_mark_error($receipt['row'], $message);
        }

        autorecharge_log(
            'autorecharge.log',
            "ERROR gateway={$gateway} reference={$username} amount={$amount} " .
            "trxid={$trxid} message=" . $e->getMessage()
        );

        return [
            'status' => 'error',
            'gateway' => $gateway,
            'message' => $e->getMessage(),
            'trxid' => $trxid,
        ];
    } finally {
        autorecharge_unlock($lockKey);
    }
}

}
