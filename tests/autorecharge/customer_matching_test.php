<?php

require_once __DIR__ . '/../../autorecharge/autorecharge.php';

final class AutoRechargeTestCustomer
{
    public $id;
    public $username;
    public $pppoe_username;
    public $phonenumber;
    public $reseller_id;

    public function __construct($id, $username, $pppoeUsername, $phone)
    {
        $this->id = $id;
        $this->username = $username;
        $this->pppoe_username = $pppoeUsername;
        $this->phonenumber = $phone;
    }

    public function id() { return $this->id; }
}

final class AutoRechargeTestQuery
{
    private $table;
    private $sql = '';
    private $params = [];
    private $filters = [];
    private $hasJoin = false;

    public function __construct($table)
    {
        $this->table = $table;
    }

    public function table_alias($alias) { return $this; }
    public function select($column, $alias = null) { return $this; }
    public function join($table, $conditions, $alias = null) { $this->hasJoin = true; return $this; }

    public function where($column, $value)
    {
        $this->filters[] = [$column, '=', $value];
        return $this;
    }

    public function where_gte($column, $value)
    {
        $this->filters[] = [$column, '>=', $value];
        return $this;
    }

    public function where_lte($column, $value)
    {
        $this->filters[] = [$column, '<=', $value];
        return $this;
    }

    public function where_raw($sql, $params)
    {
        $this->sql = $sql;
        $this->params = $params;
        return $this;
    }

    public function find_many()
    {
        if ($this->table === 'tbl_plans' || $this->table === 'tbl_reseller_packages' || $this->table === 'tbl_user_recharges') {
            $matches = [];
            $rows = $this->table === 'tbl_plans' ? ORM::$plans
                : ($this->table === 'tbl_reseller_packages' ? ORM::$resellerPackages : ORM::$currentRecharges);
            foreach ($rows as $row) {
                $plan = $row;
                if ($this->table === 'tbl_user_recharges') {
                    if (empty($row->plan_id) || !isset(ORM::$plans[$row->plan_id])) continue;
                    $plan = clone ORM::$plans[$row->plan_id];
                } elseif ($this->table === 'tbl_reseller_packages' && $this->hasJoin) {
                    if (empty($row->plan_id) || !isset(ORM::$plans[$row->plan_id])) continue;
                    $plan = clone ORM::$plans[$row->plan_id];
                    $plan->customer_price = $row->customer_price;
                    $plan->reseller_id = $row->reseller_id;
                    $plan->status = $row->status;
                } elseif ($this->table === 'tbl_reseller_packages') {
                    $plan = $row;
                }
                $matched = true;
                foreach ($this->filters as [$column, $operator, $value]) {
                    $field = substr($column, strpos($column, '.') !== false ? strpos($column, '.') + 1 : 0);
                    $actual = (str_starts_with($column, 'rp.') || str_starts_with($column, 'r.')
                        || ($this->table === 'tbl_reseller_packages' && !$this->hasJoin))
                        ? ($row->$field ?? null) : ($plan->$field ?? null);
                    if ($operator === '=' && (string) $actual !== (string) $value) $matched = false;
                    if ($operator === '>=' && (float) $actual < (float) $value) $matched = false;
                    if ($operator === '<=' && (float) $actual > (float) $value) $matched = false;
                }
                if ($matched) $matches[] = $plan;
            }
            return $matches;
        }

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
    public static $plans = [];
    public static $resellerPackages = [];
    public static $currentRecharges = [];

    public static function for_table($table)
    {
        if (!in_array($table, ['tbl_customers', 'tbl_plans', 'tbl_reseller_packages', 'tbl_user_recharges'], true)) {
            throw new RuntimeException('Unexpected table: ' . $table);
        }
        return new AutoRechargeTestQuery($table);
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

$basicCustomer = new AutoRechargeTestCustomer(70, 'basic', 'basic.pppoe', '01812345678');
$basicPlan = (object) ['id' => 10, 'price' => 300, 'type' => 'PPPOE', 'enabled' => 1];
$higherPlan = (object) ['id' => 11, 'price' => 350, 'type' => 'PPPOE', 'enabled' => 1];
ORM::$plans = [10 => $basicPlan, 11 => $higherPlan];
$selected = autorecharge_find_plan(300, $basicCustomer);
check($selected['plan']->id === 10 && $selected['overpayment'] === 0.0, 'exact package price must win');
$selected = autorecharge_find_plan(349.99, $basicCustomer);
check($selected['plan']->id === 10 && $selected['overpayment'] === 49.99, 'payment up to Tk 50 above a package must use closest lower price');
$selected = autorecharge_find_plan(350, $basicCustomer);
check($selected['plan']->id === 11 && $selected['overpayment'] === 0.0, 'higher exact package must not be misclassified as overpayment');
check(autorecharge_find_plan(400.01, $basicCustomer)['error'] !== null, 'amount more than Tk 50 above every package must be rejected');
ORM::$plans[12] = (object) ['id' => 12, 'price' => 300, 'type' => 'PPPOE', 'enabled' => 1];
check(autorecharge_find_plan(320, $basicCustomer)['error'] !== null, 'ambiguous nearest package price must require review');

ORM::$plans = [10 => $basicPlan, 11 => $higherPlan];
ORM::$currentRecharges = [(object) ['customer_id' => 77, 'plan_id' => 10, 'status' => 'on']];
$runningBasicPlan = new AutoRechargeTestCustomer(77, 'running', 'running.pppoe', '01712345678');
$selected = autorecharge_find_plan(350, $runningBasicPlan);
check($selected['plan']->id === 10 && $selected['overpayment'] === 50.0, 'current Tk 300 package must win through Tk 350 even when a Tk 350 package exists');
$note = autorecharge_payment_note('bKash', '01712345678', 'TRX-50', 350, $selected['package_price'], $selected['overpayment']);
check(strpos($note, 'Amount received: Tk 350.00') !== false, 'receipt note must retain the full amount received');
check(strpos($note, 'Package price: Tk 300.00') !== false && strpos($note, 'Overpayment (receipt only): Tk 50.00') !== false, 'receipt note must show package amount and surplus without balance credit');
ORM::$currentRecharges[] = (object) ['customer_id' => 77, 'plan_id' => 11, 'status' => 'on'];
check(autorecharge_find_plan(320, $runningBasicPlan)['error'] !== null, 'multiple active PPPOE packages must require review');

$resellerCustomer = new AutoRechargeTestCustomer(78, 'reseller', 'reseller.pppoe', '01812345678');
$resellerCustomer->reseller_id = 9;
ORM::$plans = [10 => $basicPlan, 11 => $higherPlan];
ORM::$currentRecharges = [];
ORM::$resellerPackages = [(object) ['reseller_id' => 9, 'plan_id' => 10, 'customer_price' => 280, 'status' => 1]];
$selected = autorecharge_find_plan(320, $resellerCustomer);
check($selected['plan']->id === 10 && $selected['package_price'] === 280.0, 'reseller customer price must be used for tolerance matching');

echo "PASS: reference matching, retry boundaries, current-plan priority, exact and tolerant package selection, reseller pricing, and receipt-only overpayment\n";
