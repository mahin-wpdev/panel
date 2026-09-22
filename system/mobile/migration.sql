-- Run on STAGING after backup; additive foundation tables only.
CREATE TABLE IF NOT EXISTS tbl_mobile_auth_sessions (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  actor_type ENUM('customer','staff') NOT NULL,
  actor_id INT NOT NULL,
  access_hash CHAR(64) NOT NULL UNIQUE,
  refresh_hash CHAR(64) NOT NULL UNIQUE,
  access_expires_at DATETIME NOT NULL,
  refresh_expires_at DATETIME NOT NULL,
  revoked_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at DATETIME NULL,
  KEY idx_actor(actor_type, actor_id), KEY idx_expiry(refresh_expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS tbl_mobile_auth_attempts (
  attempt_key CHAR(64) NOT NULL PRIMARY KEY,
  attempts INT NOT NULL DEFAULT 0,
  window_start DATETIME NOT NULL,
  blocked_until DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
