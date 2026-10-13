<?php
declare(strict_types=1);
require __DIR__.'/../../system/autoload/ResellerAccounting.php';

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
INSERT INTO tbl_users VALUES (1,'Admin'),(2,'Agent');
INSERT INTO tbl_customers VALUES (1,9);
INSERT INTO tbl_transactions VALUES
 (101,1,500,'Recharge - Cash','2026-10-13',1),
 (102,1,500,'Recharge - Cash','2026-10-13',2),
 (103,1,100,'Admin Manual - admin','2026-10-13',1);
INSERT INTO tbl_reseller_earnings VALUES
 (101,9,'earned','2026-10-13 10:00:00',400),
 (102,9,'earned','2026-10-13 10:00:00',100),
 (103,9,'earned','2026-10-13 10:00:00',50);
INSERT INTO tbl_profit_events VALUES (102,9,50,500,'earned','2026-10-13');");

$earned = ResellerAccounting::earned($db, 9);
$sales = ResellerAccounting::sales($db, 9, '2026-10-01', '2026-10-31');
if ($earned !== 50.0) {
    throw new RuntimeException('Only the immutable reseller event should count; Admin and Admin Manual commissions must be excluded.');
}
if ($sales !== 500.0) {
    throw new RuntimeException('Reseller sales should count the snapshot once and exclude Admin recharges.');
}
echo "PASS: reseller earned profit and sales exclude Admin-origin recharges\n";
