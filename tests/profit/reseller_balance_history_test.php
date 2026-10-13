<?php
declare(strict_types=1);

require __DIR__.'/../../system/autoload/ResellerBalanceHistory.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "SKIP: PDO SQLite unavailable\n");
    exit(0);
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE tbl_reseller_settlements (
    id INTEGER PRIMARY KEY, reseller_id INTEGER, amount NUMERIC, entry_type TEXT,
    note TEXT, reference TEXT, processed_by INTEGER, created_at TEXT, reversal_of INTEGER
);
CREATE TABLE tbl_profit_events (
    id INTEGER PRIMARY KEY, recharge_id INTEGER, owner_reseller_id INTEGER, customer_id INTEGER,
    reseller_income NUMERIC, profit_type TEXT, profit_value NUMERIC, recharge_amount NUMERIC,
    effective_on TEXT, created_at TEXT, status TEXT
);
CREATE TABLE tbl_reseller_earnings (
    id INTEGER PRIMARY KEY, recharge_id INTEGER, reseller_id INTEGER, customer_id INTEGER,
    profit_amount NUMERIC, profit_type TEXT, profit_value NUMERIC, recharge_amount NUMERIC,
    created_at TEXT, status TEXT
);
CREATE TABLE tbl_transactions (
    id INTEGER PRIMARY KEY, recharged_on TEXT, recharged_time TEXT, plan_name TEXT
);
CREATE TABLE tbl_customers (id INTEGER PRIMARY KEY, username TEXT);
INSERT INTO tbl_reseller_settlements VALUES
 (1,1,300,'payment','cash paid','cash',1,'2026-10-12 10:00:00',NULL),
 (2,1,-25,'add','manual correction','',1,'2026-10-12 09:00:00',NULL),
 (3,1,100,'debt_repayment','applied to debt','Recharge #104',NULL,'2026-10-13 11:00:00',NULL);
INSERT INTO tbl_customers VALUES (10,'prince'),(11,'shawon');
INSERT INTO tbl_transactions VALUES
 (103,'2026-10-11','08:10:00','Plan 500'),
 (104,'2026-10-13','10:30:00','Plan 500');
INSERT INTO tbl_profit_events VALUES
 (1,104,1,10,250,'percentage',50,500,'2026-10-13','2026-10-13 10:31:00','earned');
INSERT INTO tbl_reseller_earnings VALUES
 (1,1,1,10,300,'percentage',60,500,'2026-10-11 08:11:00','earned'),
 (2,104,1,10,250,'percentage',50,500,'2026-10-13 10:31:00','earned');");

$history = ResellerBalanceHistory::recent($db);
if (count($history) !== 5) {
    throw new RuntimeException('History should include settlements, event profits, and legacy earnings once each.');
}
if ($history[1]['history_id'] !== 'Recharge #104' || $history[1]['balance_change'] !== 250.0) {
    throw new RuntimeException('Recharge profit should appear as a positive balance change at recharge time.');
}
$changes = array_column($history, 'balance_change');
if (round(array_sum($changes), 2) !== 175.0) {
    throw new RuntimeException('Settlement balance changes should keep their sign and offset earned profit correctly.');
}
$ids = array_column($history, 'history_id');
if (count(array_filter($ids, static fn(string $value): bool => $value === 'Recharge #104')) !== 1) {
    throw new RuntimeException('A recharge mirrored in legacy earnings and profit events must appear only once.');
}
$payment = array_values(array_filter($history, static fn(array $entry): bool => $entry['entry_type'] === 'payment'))[0];
$credit = array_values(array_filter($history, static fn(array $entry): bool => $entry['entry_type'] === 'add'))[0];
if ($payment['balance_change'] !== -300.0 || $credit['balance_change'] !== 25.0) {
    throw new RuntimeException('Payouts and manual profit credits should display the correct wallet direction.');
}
if (count(ResellerBalanceHistory::recent($db, 2)) !== 2) {
    throw new RuntimeException('History limit should cap the merged result after sorting.');
}

echo "PASS: reseller balance history includes earnings and signed settlements exactly once\n";
