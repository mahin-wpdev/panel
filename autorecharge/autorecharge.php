<?php
/*
|--------------------------------------------------------------------------
| autorecharge.php
|--------------------------------------------------------------------------
|
| Central, shared automatic-recharge engine for PHPNuxBill.
|
| bkash.php and nogod.php both parse their own SMS formats into a common
| payment array, then call autoRechargeUser($payment) defined here.
|
| This file does NOT modify Package.php and does NOT reimplement
| Package::rechargeUser(). It only calls it.
|
| Expected $payment array shape:
|
| [
|     'gateway'  => 'bKash' | 'Nagad',
|     'username' => 'USER123' or '' (empty when no valid Ref),
|     'amount'   => 500,               // int, already normalized
|     'sender'   => '01776159990',     // raw sender phone as seen in SMS
|     'trxid'    => 'ABC123',
|     'raw_sms'  => '...',             // original SMS text, for logging
| ]
|
*/

if (!function_exists('autorecharge_log')) {

    /**
     * Append a line to a log file in the same directory as this script.
     */
    function autorecharge_log($filename, $message)
    {
        file_put_contents(
            __DIR__ . '/' . $filename,
            date('Y-m-d H:i:s') . ' ' . $message . "\n",
            FILE_APPEND
        );
    }
}

if (!function_exists('autorecharge_normalize_phone')) {

    /**
     * Extract the last 10 digits of a phone number.
     *
     * Bangladeshi mobile numbers are 10 digits after the leading 0 /
     * country code, e.g.:
     *   01776159990    -> 1776159990
     *   8801776159990  -> 1776159990
     *   +8801776159990 -> 1776159990
     *
     * Using the last 10 digits lets us match any of these representations
     * against whatever format is stored in tbl_customers.phonenumber,
     * without needing to know which format the DB uses.
     */
    function autorecharge_normalize_phone($phone)
    {
        $digits = preg_replace('/\D+/', '', (string) $phone);

        if (strlen($digits) < 10) {
            return $digits;
        }

        return substr($digits, -10);
    }
}

if (!function_exists('autorecharge_find_customer')) {

    /**
     * Find a customer by username (Ref) first; fall back to normalized
     * sender phone number when no valid username is provided.
     *
     * A Ref value of '', null, 'N/A', 'NA', or 'n/a' (case-insensitive,
     * trimmed) is treated as "no Ref".
     */
    function autorecharge_find_customer($username, $sender)
    {
        $username = trim((string) $username);

        $isValidUsername =
            $username !== '' &&
            !in_array(strtoupper($username), ['N/A', 'NA'], true);

        if ($isValidUsername) {
            $customer = ORM::for_table('tbl_customers')
                ->where('username', $username)
                ->find_one();

            if ($customer) {
                return $customer;
            }

            // A Ref was supplied but didn't match any customer.
            // Do NOT silently fall back to phone in this case for bKash-style
            // gateways where Ref is expected to be authoritative; the caller
            // decides whether phone fallback is appropriate.
            return null;
        }

        // No valid username -> match by normalized sender phone.
        $last10 = autorecharge_normalize_phone($sender);

        if ($last10 === '' || strlen($last10) < 10) {
            return null;
        }

        $customer = ORM::for_table('tbl_customers')
            ->where_raw(
                "RIGHT(REPLACE(REPLACE(REPLACE(phonenumber, '+', ''), ' ', ''), '-', ''), 10) = ?",
                [$last10]
            )
            ->find_one();

        return $customer;
    }
}

if (!function_exists('autorecharge_find_plan')) {

    /**
     * Find the single enabled PPPOE plan matching the payment amount.
     *
     * Returns:
     *   ['plan' => $plan, 'error' => null]                 on success
     *   ['plan' => null,  'error' => 'Plan not found...']   on no match
     *   ['plan' => null,  'error' => 'Multiple active...']  on ambiguous match
     */
    function autorecharge_find_plan($amount)
    {
        $plans = ORM::for_table('tbl_plans')
            ->where('price', $amount)
            ->where('type', 'PPPOE')
            ->where('enabled', 1)
            ->find_many();

        $count = count($plans);

        if ($count === 0) {
            return [
                'plan'  => null,
                'error' => 'Plan not found for amount: ' . $amount,
            ];
        }

        if ($count > 1) {
            return [
                'plan'  => null,
                'error' => 'Multiple active PPPOE plans found for amount: ' . $amount,
            ];
        }

        return [
            'plan'  => $plans[0],
            'error' => null,
        ];
    }
}

if (!function_exists('autorecharge_acquire_lock')) {

    /**
     * Acquire a MySQL named lock scoped to this TrxID, so two simultaneous
     * webhook deliveries for the same transaction cannot both pass the
     * duplicate check and both call Package::rechargeUser().
     *
     * Returns true if the lock was acquired, false otherwise.
     */
    function autorecharge_acquire_lock($trxid)
    {
        $lockName = 'autorecharge_' . $trxid;

        try {
            $result = ORM::for_table('tbl_appconfig')
                ->raw_query('SELECT GET_LOCK(?, 5) AS locked', [$lockName])
                ->find_one();

            return $result && (int) $result->locked === 1;
        } catch (Throwable $e) {
            // If GET_LOCK itself fails for any reason, fail "open" but log it -
            // the duplicate-TrxID check on tbl_transactions still provides a
            // baseline safety net even without the lock.
            autorecharge_log('autorecharge.log', 'LOCK ERROR: ' . $e->getMessage());
            return true;
        }
    }
}

if (!function_exists('autorecharge_release_lock')) {

    function autorecharge_release_lock($trxid)
    {
        $lockName = 'autorecharge_' . $trxid;

        try {
            ORM::for_table('tbl_appconfig')
                ->raw_query('SELECT RELEASE_LOCK(?) AS released', [$lockName])
                ->find_one();
        } catch (Throwable $e) {
            autorecharge_log('autorecharge.log', 'UNLOCK ERROR: ' . $e->getMessage());
        }
    }
}

if (!function_exists('autorecharge_resolve_router_name')) {

    /**
     * Determine the router name to use for Package::rechargeUser().
     *
     * Priority:
     *   1. tbl_appconfig.auto_payment_router_name, when set and non-empty.
     *   2. Auto-detect: if tbl_routers has exactly ONE row, use its name.
     *   3. Otherwise, error - ambiguous (0 or 2+ routers) and no config set.
     *
     * Returns ['router_name' => string, 'error' => null]
     *      or ['router_name' => null,  'error' => 'human readable message']
     */
    function autorecharge_resolve_router_name()
    {
        $router_config = ORM::for_table('tbl_appconfig')
            ->where('setting', 'auto_payment_router_name')
            ->find_one();

        if ($router_config && trim((string) $router_config->value) !== '') {
            return [
                'router_name' => trim((string) $router_config->value),
                'error'       => null,
            ];
        }

        // No explicit config - try to auto-detect from tbl_routers.
        $routers = ORM::for_table('tbl_routers')->find_many();
        $count   = count($routers);

        if ($count === 1) {
            autorecharge_log(
                'autorecharge.log',
                'ROUTER AUTO-DETECTED: ' . $routers[0]->name . ' (no auto_payment_router_name config set)'
            );

            return [
                'router_name' => $routers[0]->name,
                'error'       => null,
            ];
        }

        if ($count === 0) {
            return [
                'router_name' => null,
                'error'       => 'Router configuration missing (no routers found in tbl_routers)',
            ];
        }

        // 2+ routers and no explicit config - ambiguous, refuse to guess.
        $names = array_map(function ($r) {
            return $r->name;
        }, $routers);

        return [
            'router_name' => null,
            'error'       => 'Router configuration missing - multiple routers exist ('
                . implode(', ', $names)
                . '); set auto_payment_router_name in tbl_appconfig to one of these',
        ];
    }
}

if (!function_exists('autoRechargeUser')) {

    /**
     * Central recharge entry point used by both bkash.php and nogod.php.
     *
     * @param array $payment See file header for expected shape.
     * @return array JSON-serializable result:
     *   ['status' => 'success'|'error', 'gateway' => ..., 'message' => ..., ...]
     */
    function autoRechargeUser(array $payment)
    {
        $gateway  = trim((string) ($payment['gateway'] ?? ''));
        $username = trim((string) ($payment['username'] ?? ''));
        $amount   = (int) ($payment['amount'] ?? 0);
        $sender   = trim((string) ($payment['sender'] ?? ''));
        $trxid    = trim((string) ($payment['trxid'] ?? ''));

        autorecharge_log(
            'autorecharge.log',
            "START gateway={$gateway} username={$username} amount={$amount} sender={$sender} trxid={$trxid}"
        );

        // ---- Basic validation ----------------------------------------

        if ($gateway === '') {
            return ['status' => 'error', 'gateway' => $gateway, 'message' => 'Gateway missing'];
        }

        if ($amount <= 0) {
            autorecharge_log('autorecharge.log', "VALIDATION FAIL: invalid amount");
            return ['status' => 'error', 'gateway' => $gateway, 'message' => 'Invalid amount'];
        }

        if ($trxid === '') {
            autorecharge_log('autorecharge.log', "VALIDATION FAIL: missing trxid");
            return ['status' => 'error', 'gateway' => $gateway, 'message' => 'Missing TrxID'];
        }

        if ($username === '' && $sender === '') {
            autorecharge_log('autorecharge.log', "VALIDATION FAIL: no username and no sender");
            return ['status' => 'error', 'gateway' => $gateway, 'message' => 'Missing Ref/username and sender phone'];
        }

        // Acquire a per-TrxID lock so two concurrent webhook deliveries for
        // the same transaction cannot both slip past the duplicate check.
        $locked = autorecharge_acquire_lock($trxid);

        if (!$locked) {
            autorecharge_log('autorecharge.log', "LOCK BUSY for trxid={$trxid}, treating as duplicate-in-progress");
            return [
                'status'  => 'success',
                'gateway' => $gateway,
                'message' => 'Already processed',
                'trxid'   => $trxid,
            ];
        }

        try {

            // ---- Duplicate TrxID check ---------------------------------

            $duplicate = ORM::for_table('tbl_transactions')
                ->where_like('note', '%' . $trxid . '%')
                ->find_one();

            if ($duplicate) {
                autorecharge_log('autorecharge.log', "DUPLICATE trxid={$trxid}, skipping");

                return [
                    'status'  => 'success',
                    'gateway' => $gateway,
                    'message' => 'Already processed',
                    'trxid'   => $trxid,
                ];
            }

            // ---- Router configuration (explicit config, else auto-detect) --

            $routerResult = autorecharge_resolve_router_name();

            if ($routerResult['error']) {
                autorecharge_log('autorecharge.log', "ROUTER ERROR: " . $routerResult['error']);
                return ['status' => 'error', 'gateway' => $gateway, 'message' => $routerResult['error']];
            }

            $router_name = $routerResult['router_name'];

            // ---- Customer lookup ------------------------------------------

            $customer = autorecharge_find_customer($username, $sender);

            if (!$customer) {
                autorecharge_log(
                    'autorecharge.log',
                    "CUSTOMER NOT FOUND username={$username} sender={$sender}"
                );
                return ['status' => 'error', 'gateway' => $gateway, 'message' => 'User not found'];
            }

            $user_id = $customer->id;

            autorecharge_log('autorecharge.log', "CUSTOMER FOUND id={$user_id}");

            // ---- Plan lookup ------------------------------------------------

            $planResult = autorecharge_find_plan($amount);

            if ($planResult['error']) {
                autorecharge_log('autorecharge.log', "PLAN ERROR: " . $planResult['error']);
                return ['status' => 'error', 'gateway' => $gateway, 'message' => $planResult['error']];
            }

            $plan = $planResult['plan'];

            autorecharge_log(
                'autorecharge.log',
                "PLAN FOUND id={$plan->id} name={$plan->name_plan} price={$plan->price} router={$router_name}"
            );

            // ---- Build transaction note ------------------------------------

            $note = "Auto Payment\n" .
                    "Gateway: {$gateway}\n" .
                    "Sender: {$sender}\n" .
                    "TrxID: {$trxid}";

            // ---- Recharge ------------------------------------------------

            autorecharge_log('autorecharge.log', "CALLING Package::rechargeUser()");

            $result = Package::rechargeUser(
                $user_id,
                $router_name,
                $plan->id,
                $gateway . ' SMS',
                'SmsForwarder',
                $note
            );

            autorecharge_log('autorecharge.log', "RECHARGE RESULT: " . print_r($result, true));

            return [
                'status'   => 'success',
                'gateway'  => $gateway,
                'message'  => 'Recharge completed',
                'username' => $customer->username,
                'amount'   => $amount,
                'trxid'    => $trxid,
            ];

        } catch (Throwable $e) {

            autorecharge_log(
                'autorecharge.log',
                'ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
            );

            return [
                'status'  => 'error',
                'gateway' => $gateway,
                'message' => 'Internal error while processing recharge',
            ];

        } finally {

            autorecharge_release_lock($trxid);

        }
    }
}