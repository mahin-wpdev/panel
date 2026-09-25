-- Isolated in-app support tickets; never modifies legacy payment tables.
CREATE TABLE IF NOT EXISTS tbl_mobile_support_tickets (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 customer_id INT NOT NULL,
 category VARCHAR(32) NOT NULL,
 subject VARCHAR(160) NOT NULL,
 description TEXT NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'open',
 assigned_admin_id INT DEFAULT NULL,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 KEY idx_customer_updated(customer_id,updated_at),
 KEY idx_status_updated(status,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS tbl_mobile_support_events (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 ticket_id BIGINT UNSIGNED NOT NULL,
 actor_type VARCHAR(16) NOT NULL,
 actor_id INT NOT NULL,
 event_type VARCHAR(24) NOT NULL,
 message TEXT NOT NULL,
 status VARCHAR(24) DEFAULT NULL,
 created_at DATETIME NOT NULL,
 KEY idx_ticket_event(ticket_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS tbl_mobile_support_notifications (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 ticket_id BIGINT UNSIGNED NOT NULL,
 event_id BIGINT UNSIGNED NOT NULL,
 recipient_type VARCHAR(16) NOT NULL,
 recipient_id INT NOT NULL,
 title VARCHAR(160) NOT NULL,
 read_at DATETIME DEFAULT NULL,
 created_at DATETIME NOT NULL,
 UNIQUE KEY uniq_recipient_event(event_id,recipient_type,recipient_id),
 KEY idx_recipient_read(recipient_type,recipient_id,read_at,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
