/* Release invariants; read-only and safe to run against a checkout. */
const fs = require('node:fs');
const path = require('node:path');
const { execFileSync } = require('node:child_process');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const read = (relative) => fs.readFileSync(path.join(root, relative), 'utf8');
const files = execFileSync('git', ['ls-files', '-z'], { cwd: root })
  .toString('utf8').split('\0').filter(Boolean);
const installer = ['index.php', 'step2.php', 'step3.php',
  'step4.php', 'step5.php', 'update.php'];
for (const name of installer) {
  const source = read('install/' + name);
  assert.match(source.slice(0, 150), /require_once __DIR__ .*_release_guard\.php/,
    name + ' must have the first-line installer guard');
}
const guard = read('install/_release_guard.php');
assert.match(guard, /dirname\(__DIR__\)/);
assert.match(guard, /dirname\(__DIR__, 2\)/);
assert.match(guard, /is_file\(/);
assert.match(guard, /http_response_code\(404\)/);
assert.match(read('install/step4.php'), /file_get_contents\('radius\.sql'\)/,
  'legacy destructive installer must remain guarded until redesigned');
assert(!files.some(f => /(?:^|\/)autorecharge\/.*\.log$/i.test(f)),
  'payment/SMS logs must not be tracked');
assert(!files.includes('radius_backup.php'),
  'unprotected legacy RADIUS endpoint must not ship');
assert.match(read('.gitignore'), /autorecharge\/\*\.log/);
const nginx = read('deploy/security/nginx-panel-deny.conf');
assert.match(nginx, /\/panel\/install\//);
assert.match(nginx, /\blocation\b[\s\S]*log\|sql/);
assert.match(read('system/autoload/OltManager.php'), /Press any key to continue/);
assert.match(read('system/autoload/OltOnuRemoval.php'), /Press any key to continue/);
const autorecharge = read('autorecharge/autorecharge.php');
assert.match(autorecharge, /auto_payment_sms_secret/);
assert.match(autorecharge, /hash_equals\(/);
for (const webhook of ['bkash.php', 'bkash_merchant.php', 'nogod.php']) {
  assert.match(read('autorecharge/' + webhook), /autorecharge_require_webhook_secret\(/,
    webhook + ' must fail closed behind the configured webhook secret');
}
const receiptMigration = read('autorecharge/migration.sql');
assert.match(receiptMigration, /CREATE TABLE IF NOT EXISTS\s+`tbl_autorecharge_receipts`/i);
assert.match(receiptMigration, /UNIQUE KEY[\s\S]*gateway[\s\S]*trxid/i,
  'receipt ledger must enforce gateway + transaction id idempotency');
console.log('PASS: installer, webhook auth, receipt idempotency, logs, Nginx and ONU safety invariants');
