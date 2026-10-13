-- Append-only accounting snapshot per successful package recharge.
-- Existing transaction history is deliberately not backfilled here: owner
-- transfers make historic direct-income attribution ambiguous.
CREATE TABLE IF NOT EXISTS tbl_profit_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  recharge_id INT NOT NULL,
  customer_id INT NOT NULL,
  owner_reseller_id INT UNSIGNED NULL,
  actor_type VARCHAR(16) NOT NULL,
  method VARCHAR(191) NOT NULL DEFAULT '',
  recharge_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  base_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  gross_profit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  profit_type VARCHAR(16) NOT NULL DEFAULT 'direct',
  profit_value DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  direct_income DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  admin_share DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  reseller_income DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  status VARCHAR(20) NOT NULL DEFAULT 'earned',
  effective_on DATE NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_profit_event_recharge (recharge_id),
  KEY idx_profit_event_date_status (effective_on,status),
  KEY idx_profit_event_owner (owner_reseller_id,effective_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

ALTER TABLE tbl_reseller_settlements
  ADD COLUMN IF NOT EXISTS entry_type VARCHAR(24) NOT NULL DEFAULT 'payment',
  ADD COLUMN IF NOT EXISTS reversal_of BIGINT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS operation_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE tbl_reseller_settlements
  ADD UNIQUE KEY IF NOT EXISTS uq_reseller_settlement_reversal (reversal_of),
  ADD UNIQUE KEY IF NOT EXISTS uq_reseller_settlement_operation (operation_key);

CREATE TABLE IF NOT EXISTS tbl_reseller_debts (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reseller_id INT UNSIGNED NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  entry_type VARCHAR(24) NOT NULL,
  recharge_id INT NULL,
  note TEXT NULL,
  processed_by INT UNSIGNED NOT NULL DEFAULT 0,
  operation_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reseller_debt_operation (operation_key),
  KEY idx_reseller_debt_owner (reseller_id,created_at),
  KEY idx_reseller_debt_recharge (recharge_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
ALTER TABLE tbl_reseller_debts
  ADD COLUMN IF NOT EXISTS operation_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NULL;
ALTER TABLE tbl_reseller_debts
  ADD UNIQUE KEY IF NOT EXISTS uq_reseller_debt_operation (operation_key);
