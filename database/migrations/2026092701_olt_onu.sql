CREATE TABLE IF NOT EXISTS tbl_olts (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(128) NOT NULL,
  vendor VARCHAR(64) NOT NULL,
  model VARCHAR(128) DEFAULT NULL,
  management_host VARCHAR(255) NOT NULL,
  management_vlan VARCHAR(32) DEFAULT NULL,
  protocol ENUM('snmp','ssh','telnet','api') NOT NULL,
  port INT NOT NULL,
  username VARCHAR(128) DEFAULT NULL,
  encrypted_password TEXT DEFAULT NULL,
  location VARCHAR(255) DEFAULT NULL,
  area VARCHAR(128) DEFAULT NULL,
  status ENUM('Online','Offline','Unknown','Disabled') NOT NULL DEFAULT 'Unknown',
  last_sync_at DATETIME DEFAULT NULL,
  last_sync_status VARCHAR(32) DEFAULT NULL,
  last_sync_error VARCHAR(255) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_olt_host_port (management_host, port),
  KEY idx_olt_status (status),
  KEY idx_olt_area (area)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_olt_pon_ports (
  id INT NOT NULL AUTO_INCREMENT,
  olt_id INT NOT NULL,  slot_number VARCHAR(32) DEFAULT NULL,
  port_number VARCHAR(32) NOT NULL,
  name VARCHAR(64) DEFAULT NULL,
  status VARCHAR(32) DEFAULT 'UNKNOWN',
  description VARCHAR(255) DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_olt_pon (olt_id, slot_number, port_number),
  KEY idx_pon_olt (olt_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_onus (
  id INT NOT NULL AUTO_INCREMENT,
  olt_id INT NOT NULL,
  pon_port_id INT DEFAULT NULL,
  customer_id INT DEFAULT NULL,
  slot_number VARCHAR(32) DEFAULT NULL,
  pon_port VARCHAR(32) DEFAULT NULL,
  onu_id VARCHAR(64) DEFAULT NULL,
  serial_number VARCHAR(128) DEFAULT NULL,
  mac_address VARCHAR(32) DEFAULT NULL,
  status VARCHAR(32) NOT NULL DEFAULT 'UNKNOWN',
  rx_power DECIMAL(8,2) DEFAULT NULL,
  tx_power DECIMAL(8,2) DEFAULT NULL,
  distance DECIMAL(10,2) DEFAULT NULL,
  los_status VARCHAR(32) DEFAULT NULL,
  last_seen DATETIME DEFAULT NULL,
  last_online_at DATETIME DEFAULT NULL,  last_offline_at DATETIME DEFAULT NULL,
  conflict_flag TINYINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_onu_serial (serial_number),
  KEY idx_onu_olt (olt_id),
  KEY idx_onu_pon (pon_port_id),
  KEY idx_onu_customer (customer_id),
  KEY idx_onu_status (status),
  KEY idx_onu_mac (mac_address),
  KEY idx_onu_seen (last_seen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_olt_sync_logs (
  id BIGINT NOT NULL AUTO_INCREMENT,
  olt_id INT NOT NULL,
  started_at DATETIME NOT NULL,
  finished_at DATETIME DEFAULT NULL,
  status VARCHAR(32) NOT NULL,
  onu_found INT NOT NULL DEFAULT 0,
  onu_updated INT NOT NULL DEFAULT 0,
  new_onu INT NOT NULL DEFAULT 0,
  moved_onu INT NOT NULL DEFAULT 0,
  error_message VARCHAR(255) DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_olt_sync (olt_id, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS tbl_onu_status_logs (
  id BIGINT NOT NULL AUTO_INCREMENT,
  onu_id INT NOT NULL,
  previous_status VARCHAR(32) DEFAULT NULL,
  status VARCHAR(32) NOT NULL,
  recorded_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_onu_status_history (onu_id, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS tbl_onu_power_logs (
  id BIGINT NOT NULL AUTO_INCREMENT,
  onu_id INT NOT NULL,
  rx_power DECIMAL(8,2) DEFAULT NULL,
  tx_power DECIMAL(8,2) DEFAULT NULL,
  recorded_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_onu_power_history (onu_id, recorded_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
