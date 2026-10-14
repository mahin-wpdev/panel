ALTER TABLE tbl_transactions
  ADD COLUMN IF NOT EXISTS recharge_operation_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL DEFAULT NULL;

CREATE TABLE IF NOT EXISTS tbl_recharge_provisioning_outbox (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  operation_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  customer_id INT UNSIGNED NOT NULL,
  plan_id INT UNSIGNED NOT NULL,
  router VARCHAR(64) NOT NULL,
  gateway VARCHAR(128) NOT NULL,
  channel VARCHAR(128) NOT NULL,
  note TEXT NOT NULL,
  tax_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  price_override DECIMAL(15,2) NULL DEFAULT NULL,
  invoice VARCHAR(25) NOT NULL,
  admin_id INT UNSIGNED NOT NULL DEFAULT 0,
  transaction_id INT UNSIGNED NULL DEFAULT NULL,
  status ENUM('provisioning','provisioned','completed','manual_review') NOT NULL DEFAULT 'provisioning',
  attempts INT UNSIGNED NOT NULL DEFAULT 1,
  last_error VARCHAR(1000) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_recharge_outbox_operation (operation_key),
  KEY idx_recharge_outbox_status_updated (status, updated_at),
  KEY idx_recharge_outbox_customer_status (customer_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
