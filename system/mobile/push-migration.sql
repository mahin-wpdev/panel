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

CREATE TABLE IF NOT EXISTS tbl_mobile_app_notifications (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 recipient_type VARCHAR(16) NOT NULL,
 recipient_id INT NOT NULL,
 title VARCHAR(160) NOT NULL,
 body TEXT NOT NULL,
 sent_by_admin_id INT DEFAULT NULL,
 created_at DATETIME NOT NULL,
 read_at DATETIME DEFAULT NULL,
 KEY idx_app_alert_recipient(recipient_type,recipient_id,read_at,id),
 KEY idx_app_alert_created(created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
