-- Store customer portal credentials separately from the cleartext network
-- password required by the current PPP/RADIUS device integrations.
ALTER TABLE `tbl_customers`
  ADD COLUMN `portal_password_hash` VARCHAR(255) NULL DEFAULT NULL AFTER `password`;
