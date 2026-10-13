<?php
declare(strict_types=1);
require __DIR__.'/../../system/autoload/Password.php';

$modern = Password::_crypt('Correct Horse Battery');
if (strlen($modern) !== 60 || !Password::_verify('Correct Horse Battery', $modern)) {
    throw new RuntimeException('Staff passwords must use a verifiable bcrypt hash.');
}
if (Password::_verify('wrong', $modern) ||
    !Password::_verify('legacy', sha1('legacy')) ||
    !Password::_needsRehash(sha1('legacy'))) {
    throw new RuntimeException('Wrong passwords must fail and legacy SHA-1 hashes must be upgraded.');
}
$customerHash = Password::customerHash('customer-secret');
if (!Password::customerVerify('customer-secret', $customerHash) || Password::customerVerify('wrong', $customerHash)) {
    throw new RuntimeException('Customer portal hashes must verify without plaintext storage.');
}
if (!Password::customerVerify('legacy-secret', '', 'legacy-secret')) {
    throw new RuntimeException('Legacy customer login must remain available for one-time migration.');
}
echo "PASS: modern staff/customer hashing and legacy credential upgrade\n";
