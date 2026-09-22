-- Auto Recharge receipt ledger.
-- Idempotency depends on UNIQUE(gateway,trxid); run this before enabling webhooks.
CREATE TABLE IF NOT EXISTS `tbl_autorecharge_receipts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `gateway` VARCHAR(100) NOT NULL,
  `trxid` VARCHAR(100) NOT NULL,
  `amount` DECIMAL(15,2) NOT NULL,
  `customer_id` INT NULL,
  `transaction_id` VARCHAR(100) NULL,
  `status` VARCHAR(20) NOT NULL DEFAULT 'processing',
  `error_message` VARCHAR(255) NULL,
  `received_at` DATETIME NOT NULL,
  `completed_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_autorecharge_gateway_trxid` (`gateway`, `trxid`),
  KEY `idx_autorecharge_customer` (`customer_id`),
  KEY `idx_autorecharge_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Older installations may have a numeric transaction_id.
ALTER TABLE `tbl_autorecharge_receipts`
  MODIFY `transaction_id` VARCHAR(100) NULL;
