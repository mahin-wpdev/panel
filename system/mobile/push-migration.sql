CREATE TABLE IF NOT EXISTS tbl_mobile_push_tokens (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 actor_type VARCHAR(16) NOT NULL,
 actor_id INT NOT NULL,
 token VARCHAR(255) NOT NULL,
 platform VARCHAR(16) NOT NULL DEFAULT 'android',
 app_version VARCHAR(32) DEFAULT NULL,
 device_label VARCHAR(120) DEFAULT NULL,
 enabled TINYINT(1) NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 last_seen DATETIME NOT NULL,
 UNIQUE KEY uniq_push_token(token),
 KEY idx_push_actor(actor_type,actor_id,enabled),
 KEY idx_push_seen(last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
