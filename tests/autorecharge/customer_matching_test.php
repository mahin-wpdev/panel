<?php

require_once __DIR__ . '/../../autorecharge/autorecharge.php';

final class AutoRechargeTestCustomer
{
    public $id;
    public $username;
    public $pppoe_username;
    public $phonenumber;

    public function __construct($id, $username, $pppoeUsername, $phone)
    {
        $this->id = $id;
        $this->username = $username;
        $this->pppoe_username = $pppoeUsername;
        $this->phonenumber = $phone;
    }
}

final class AutoRechargeTestQuery
{
    private $sql = '';
    private $params = [];

    public function where_raw($sql, $params)
    {
        $this->sql = $sql;
        $this->params = $params;
        return $this;
    }

    public function find_many()
    {
        $matches = [];
        foreach (ORM::$customers as $customer) {
            if (strpos($this->sql, 'LOWER(username)') !== false) {
                $name = strtolower((string) $customer->username);
                $pppoe = strtolower((string) $customer->pppoe_username);
                if ($name === $this->params[0] || $pppoe === $this->params[1]) {
                    $matches[] = $customer;
                }
                continue;
            }

            $phone = preg_replace('/\D+/', '', (string) $customer->phonenumber);
            if (strlen($phone) >= 10 && substr($phone, -10) === $this->params[0]) {
                $matches[] = $customer;
            }
        }
        return $matches;
    }
}

final class ORM
{
    public static $customers = [];

    public static function for_table($table)
    {
        if ($table !== 'tbl_customers') {
            throw new RuntimeException('Unexpected table: ' . $table);
        }
        return new AutoRechargeTestQuery();
    }
}

function check($condition, $message)
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$first = new AutoRechargeTestCustomer(1, 'first', 'pppoe.first', '+880 (171) 234-5678');
$second = new AutoRechargeTestCustomer(2, 'second', 'pppoe.second', '01812345678');
ORM::$customers = [$first, $second];

check(autorecharge_find_customer('PPPOE.FIRST', '') === $first, 'case-insensitive PPPoE username should match');
check(autorecharge_find_customer('display-name', '01712345678') === $first, 'unknown reference should fall back to one normalized sender match');
check(autorecharge_find_customer('', '+880 1812-345678') === $second, 'missing reference should fall back to phone');

$duplicatePhone = new AutoRechargeTestCustomer(3, 'third', 'pppoe.third', '01712345678');
ORM::$customers = [$first, $duplicatePhone];
check(autorecharge_find_customer('display-name', '01712345678') === null, 'ambiguous phone match must not choose a customer');

$duplicateReference = new AutoRechargeTestCustomer(4, 'shared', 'other', '01912345678');
$otherReference = new AutoRechargeTestCustomer(5, 'other', 'shared', '01612345678');
ORM::$customers = [$duplicateReference, $otherReference];
check(autorecharge_find_customer('shared', '01912345678') === null, 'ambiguous reference must not fall back to a different account');

$receipt = (object) ['status' => 'error', 'transaction_id' => null, 'customer_id' => 1, 'error_message' => 'SAFE_RETRY: No eligible package'];
check(autorecharge_receipt_can_retry($receipt), 'preflight failure should be safely retryable');
$receipt->error_message = 'Package call failed';
check(!autorecharge_receipt_can_retry($receipt), 'unknown failure after package attempt must require review');
$receipt->error_message = 'SAFE_RETRY: plan missing';
$receipt->transaction_id = 'INV-1';
check(!autorecharge_receipt_can_retry($receipt), 'receipt with a transaction ID must never be retried');

echo "PASS: reference/PPPoE/phone matching, ambiguity safety, and retry boundaries\n";
