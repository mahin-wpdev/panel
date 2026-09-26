-- Apply once before exposing the admin manual-recharge API.
-- Deliberately separate from existing phpNuxBill and bKash payment tables.
CREATE TABLE IF NOT EXISTS tbl_mobile_admin_recharge_requests (
  request_key CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
  actor_id INT NOT NULL,
  customer_id INT NOT NULL,
  plan_id INT NOT NULL,
  router VARCHAR(191) NOT NULL,
  status VARCHAR(20) NOT NULL,
  invoice VARCHAR(80) DEFAULT NULL,
  created_at DATETIME NOT NULL,
  completed_at DATETIME DEFAULT NULL,
  KEY idx_customer_created (customer_id, created_at),
  KEY idx_admin (actor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;