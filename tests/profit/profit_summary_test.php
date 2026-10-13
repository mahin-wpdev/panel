<?php
declare(strict_types=1);
require __DIR__.'/../../system/autoload/ProfitSummary.php';
require __DIR__.'/../../system/autoload/ResellerProfit.php';

foreach ([['Recharge - Cash','Admin',true],['Recharge - Cash','SuperAdmin',true],
          ['Admin Manual - admin','Agent',true],['Recharge - Cash','Agent',false]] as [$method,$role,$expected]) {
    if (ResellerProfit::isAdminActor($method,$role) !== $expected) {
        throw new RuntimeException('Admin recharge actor classification failed.');
    }
}

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "SKIP: PDO SQLite unavailable\n");
    exit(0);
}
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->exec("CREATE TABLE tbl_customers(id INTEGER PRIMARY KEY, reseller_id INTEGER NULL);
CREATE TABLE tbl_transactions(id INTEGER PRIMARY KEY, user_id INTEGER, price NUMERIC, method TEXT, recharged_on TEXT, admin_id INTEGER DEFAULT 0);
CREATE TABLE tbl_users(id INTEGER PRIMARY KEY, user_type TEXT);
CREATE TABLE tbl_reseller_earnings(recharge_id INTEGER, status TEXT, admin_profit NUMERIC, profit_amount NUMERIC);
CREATE TABLE tbl_profit_events(recharge_id INTEGER, status TEXT, effective_on TEXT,
  direct_income NUMERIC, admin_share NUMERIC, reseller_income NUMERIC);
INSERT INTO tbl_customers VALUES (1,NULL),(2,9);
INSERT INTO tbl_users VALUES (1,'Admin'),(2,'Agent');
INSERT INTO tbl_transactions VALUES (1,1,500,'Cash - Admin','2026-10-13',1),(2,2,500,'Cash - Agent','2026-10-13',2);
INSERT INTO tbl_reseller_earnings VALUES (2,'earned',200,300);");
$db->exec("INSERT INTO tbl_profit_events VALUES (2,'earned','2026-10-13',0,200,300)");
$own = ProfitSummary::period($db, '2026-10-01', '2026-10-31');
if ($own['admin'] !== 700.0 || $own['reseller'] !== 300.0) {
    throw new RuntimeException('Expected direct 500 + reseller admin share 200 = admin income 700.');
}

// Any Admin/SuperAdmin recharge of a reseller-owned customer belongs wholly to
// the ISP. Legacy commission rows are ignored even when their method is generic.
$db->exec("INSERT INTO tbl_transactions VALUES (3,2,500,'Recharge - Cash','2026-10-13',1);
INSERT INTO tbl_reseller_earnings VALUES (3,'earned',200,300);");
$own = ProfitSummary::period($db, '2026-10-01', '2026-10-31');
if ($own['admin'] !== 1200.0 || $own['reseller'] !== 300.0) {
    throw new RuntimeException('Admin recharge should add 500 to ISP income and exclude its legacy reseller split.');
}
if (ProfitSummary::periodStart('2026-10-13',10)!=='2026-10-10' ||
    ProfitSummary::periodStart('2026-03-05',31)!=='2026-02-28') {
    throw new RuntimeException('Reset-day periods must carry across months and clip short months.');
}

echo "PASS: admin/reseller attribution, split income, and reset-day periods\n";
