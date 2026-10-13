<?php
declare(strict_types=1);
require __DIR__.'/../../system/autoload/ResellerAccounting.php';
require __DIR__.'/../../system/autoload/ResellerDebt.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "SKIP: PDO SQLite unavailable\n");
    exit(0);
}
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE tbl_transactions(id INTEGER PRIMARY KEY,user_id INTEGER,price NUMERIC,method TEXT,recharged_on TEXT,admin_id INTEGER DEFAULT 0);
CREATE TABLE tbl_users(id INTEGER PRIMARY KEY,user_type TEXT);
CREATE TABLE tbl_customers(id INTEGER PRIMARY KEY,reseller_id INTEGER);
CREATE TABLE tbl_reseller_earnings(recharge_id INTEGER,reseller_id INTEGER,status TEXT,created_at TEXT,profit_amount NUMERIC);
CREATE TABLE tbl_profit_events(recharge_id INTEGER,owner_reseller_id INTEGER,reseller_income NUMERIC,recharge_amount NUMERIC,status TEXT,effective_on TEXT);
CREATE TABLE tbl_reseller_settlements(id INTEGER PRIMARY KEY,reseller_id INTEGER,amount NUMERIC,entry_type TEXT);
CREATE TABLE tbl_reseller_debts(id INTEGER PRIMARY KEY,reseller_id INTEGER,amount NUMERIC,entry_type TEXT,recharge_id INTEGER);
INSERT INTO tbl_users VALUES (1,'Admin'),(2,'Agent');
INSERT INTO tbl_customers VALUES (1,9);
INSERT INTO tbl_transactions VALUES
 (101,1,500,'Recharge - Cash','2026-10-13',1),
 (102,1,500,'Recharge - Cash','2026-10-13',2),
 (103,1,100,'Admin Manual - admin','2026-10-13',1),
 (104,1,200,'Admin Manual - admin','2026-10-13',1);
INSERT INTO tbl_reseller_earnings VALUES
 (101,9,'earned','2026-10-13 10:00:00',400),
 (102,9,'earned','2026-10-13 10:00:00',100),
 (103,9,'earned','2026-10-13 10:00:00',50);
INSERT INTO tbl_profit_events VALUES
 (102,9,50,500,'earned','2026-10-13'),
 (104,9,0,200,'earned','2026-10-13');
INSERT INTO tbl_reseller_settlements VALUES (1,9,20,'debt_repayment');
INSERT INTO tbl_reseller_debts VALUES (1,9,200,'debt',NULL),(2,9,-20,'recovery',103);");

$earned = ResellerAccounting::earned($db, 9);
$sales = ResellerAccounting::sales($db, 9, '2026-10-01', '2026-10-31');
if ($earned !== 500.0) {
    throw new RuntimeException('Admin, Admin Manual, and reseller-originated commissions should all count once.');
}
if ($sales !== 1300.0) {
    throw new RuntimeException('Reseller sales should count every recharge once, including zero-commission recharges.');
}
$settled = ResellerAccounting::settled($db, 9);
$debt = ResellerDebt::summary($db, 9);
if ($settled !== 20.0 || $debt['recovered'] !== 20.0 || $debt['outstanding'] !== 180.0) {
    throw new RuntimeException('Admin-originated recharge profit must participate in debt recovery and settlement totals.');
}
echo "PASS: reseller profit, sales, and debt recovery include all recharge actors\n";
