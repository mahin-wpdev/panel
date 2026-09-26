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
const peakCollector = read('system/mobile/collect-radius-peaks.php');
assert.match(peakCollector, /PHP_SAPI\s*!==\s*'cli'/);
assert.match(peakCollector, /GET_LOCK\('jm_radius_speed_peaks'/);
assert.match(peakCollector, /\$_SERVER\['SERVER_PORT'\]\s*\?\?=/);
assert.match(read('mobile-api.php'), /\['server_peak'\]/);
assert.match(read('mobile-api.php'), /jm_live_snapshot_cached\(\$db,\$session\)/,
  'live traffic must retain the production per-customer rate-limit cache');
assert.match(read('system/mobile/live-traffic.php'), /function jm_live_snapshot_cached\(/);
assert.match(read('system/mobile/live-traffic.php'), /flock\(\$file, LOCK_EX \| LOCK_NB\)/);
assert.match(read('system/mobile/customer-dashboard.php'), /'traffic_peak'\s*=>/);
assert.match(read('system/mobile/panel-app.php'), /'traffic_peak'\s*=>/);
assert.match(read('system/mobile/traffic-peaks-migration.sql'), /CREATE TABLE IF NOT EXISTS tbl_mobile_radius_speed_peaks/);
for (const entrypoint of ['index.php', 'update.php']) {
  const source = read(entrypoint);
  const start = source.indexOf('session_start();');
  const cookie = source.indexOf('session_set_cookie_params([');
  assert(cookie >= 0 && cookie < start, entrypoint + ' must harden PHP session cookies before session_start');
  assert.match(source, /'httponly'\s*=>\s*true/);
  assert.match(source, /'samesite'\s*=>\s*'Lax'/);
  assert.match(source, /ini_set\('session\.use_strict_mode',\s*'1'\)/);
  assert.match(source, /ini_set\('session\.use_only_cookies',\s*'1'\)/);
}
const setup = read('system/controllers/setup.php');
assert.match(setup, /in_array\(\$publicScheme, \['http', 'https'\], true\)/);
assert.match(setup, /ctype_digit\(\$publicPort\)/);
assert.match(setup, /FILTER_VALIDATE_IP/);
assert.match(setup, /JSON_THROW_ON_ERROR/);
assert.match(setup, /\.tmp\.['"]?\s*\.\s*bin2hex\(random_bytes\(4\)\)/);
const whatsappController = read('system/controllers/whatsapp.php');
assert.match(whatsappController, /\(\(\$result\['json'\]\['success'\] \?\? false\) === true\)/);
console.log('PASS: installer, webhook auth, receipt idempotency, logs, Nginx, ONU, setup, WhatsApp, session cookies, and server-owned peak invariants');
