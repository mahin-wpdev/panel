<?php
declare(strict_types=1);
require __DIR__.'/../../system/mobile/admin-recharge.php';
foreach (['customer'=>false,'reseller'=>false,'sales'=>false,
          'unknown'=>false,'admin'=>true,'superadmin'=>false] as $role=>$expected) {
    if (jm_mobile_recharge_is_admin($role)!==$expected) {
        fwrite(STDERR,"Recharge role guard failed: $role\n");
        exit(1);
    }
}
echo "PASS: admin-only recharge role guard\n";