-- Keep tax separate from service revenue while making the billed total auditable.
ALTER TABLE `tbl_transactions`
  ADD COLUMN IF NOT EXISTS `tax_amount` DECIMAL(15,2) NOT NULL DEFAULT 0.00 AFTER `price`;
