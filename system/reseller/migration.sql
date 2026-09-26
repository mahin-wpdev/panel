-- Reseller management schema.
-- Safe to run repeatedly on MariaDB 10.6+ / 11.x.
ALTER TABLE tbl_customers
  ADD COLUMN IF NOT EXISTS reseller_id INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS approval_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'approved';

ALTER TABLE tbl_customers
  ADD INDEX IF NOT EXISTS idx_customers_reseller (reseller_id),
  ADD INDEX IF NOT EXISTS idx_customers_approval (approval_status);

CREATE TABLE IF NOT EXISTS tbl_resellers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  reseller_code VARCHAR(64) NOT NULL,
  customer_prefix VARCHAR(64) NOT NULL DEFAULT '',
  name VARCHAR(120) NOT NULL,
  mobile VARCHAR(32) NOT NULL DEFAULT '',
  email VARCHAR(128) NOT NULL DEFAULT '',
  profit_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  status ENUM('active','suspended','disabled') NOT NULL DEFAULT 'active',
  auto_approve_customers TINYINT(1) NOT NULL DEFAULT 0,
  allow_sms TINYINT(1) NOT NULL DEFAULT 1,
  allow_whatsapp TINYINT(1) NOT NULL DEFAULT 1,
  allow_email TINYINT(1) NOT NULL DEFAULT 1,
  notes TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reseller_user (user_id),
  UNIQUE KEY uq_reseller_code (reseller_code),
  KEY idx_reseller_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tbl_reseller_packages (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reseller_id INT UNSIGNED NOT NULL,
  plan_id INT NOT NULL,
  customer_price DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  base_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  profit_type ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
  profit_value DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  status TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reseller_plan (reseller_id, plan_id),
  KEY idx_reseller_package_status (reseller_id, status),
  KEY idx_reseller_package_plan (plan_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tbl_reseller_earnings (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reseller_id INT UNSIGNED NOT NULL,
  customer_id INT NOT NULL,
  recharge_id INT NOT NULL,
  package_id INT NOT NULL,
  reseller_package_id BIGINT UNSIGNED NULL,
  recharge_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  base_cost DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  gross_profit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  profit_type ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
  profit_value DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  profit_percentage DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  profit_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  admin_profit DECIMAL(15,2) NOT NULL DEFAULT 0.00,
  status VARCHAR(20) NOT NULL DEFAULT 'earned',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reseller_earning_recharge (recharge_id),
  KEY idx_reseller_earning_reseller (reseller_id, status, created_at),
  KEY idx_reseller_earning_customer (customer_id),
  KEY idx_reseller_earning_package (package_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tbl_reseller_settlements (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reseller_id INT UNSIGNED NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  payment_method VARCHAR(64) NOT NULL DEFAULT 'Cash',
  reference VARCHAR(191) NOT NULL DEFAULT '',
  note TEXT NULL,
  processed_by INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reseller_settlement_reseller (reseller_id, created_at),
  KEY idx_reseller_settlement_admin (processed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tbl_reseller_activity_logs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  reseller_id INT UNSIGNED NULL,
  user_id INT UNSIGNED NULL,
  action VARCHAR(120) NOT NULL DEFAULT '',
  details TEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_reseller_activity_reseller (reseller_id, created_at),
  KEY idx_reseller_activity_user (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
