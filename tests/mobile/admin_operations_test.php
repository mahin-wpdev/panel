<?php
declare(strict_types=1);
class User {
    public static function getBills($id): array {
        if ($id!==7) throw new RuntimeException('Unexpected customer');
        return [['Equipment Bill'=>50,'Installment'=>20],70];
    }
    public static function getAttribute($name,$id) {
        if ($name!=='Invoice' || $id!==7) throw new RuntimeException('Unexpected attribute');
        return '450';
    }
}
require __DIR__.'/../../system/mobile/admin-recharge.php';
require __DIR__.'/../../system/mobile/admin-operations.php';
$a=jm_mobile_recharge_calculate_preview(7,
    ['price'=>'500','validity_unit'=>'Days']);
if ($a['expected_recorded_amount_bdt']!=='570.00'
    || $a['additional_bills_bdt']!=='70.00') exit(1);
$b=jm_mobile_recharge_calculate_preview(7,
    ['price'=>'500','validity_unit'=>'Period']);
if ($b['expected_recorded_amount_bdt']!=='520.00'
    || $b['period_invoice_override_bdt']!=='450.00') exit(2);
echo "PASS: preview matches package/bills and Period invoice override\n";
