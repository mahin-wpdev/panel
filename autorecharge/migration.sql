-- Auto Recharge receipt schema fix
-- Required to preserve PHPNuxBill transaction references such as INV-110.

ALTER TABLE `tbl_autorecharge_receipts`
    MODIFY `transaction_id` VARCHAR(100) NULL;
