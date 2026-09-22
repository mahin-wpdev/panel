-- Additive migration: run on staging first and back up the Panel DB.
-- Peak rates are averages over consecutive RADIUS accounting updates, NOT 1-second peaks.
CREATE TABLE IF NOT EXISTS tbl_mobile_radius_speed_state (
  acctuniqueid VARCHAR(64) NOT NULL PRIMARY KEY,
  customer_id INT NOT NULL,
  recorded_at BIGINT UNSIGNED NOT NULL,
  input_octets BIGINT UNSIGNED NOT NULL,
  output_octets BIGINT UNSIGNED NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tbl_mobile_radius_speed_peaks (
  customer_id INT NOT NULL PRIMARY KEY,
  download_bps BIGINT UNSIGNED NOT NULL DEFAULT 0,
  download_at_ms BIGINT UNSIGNED NULL,
  upload_bps BIGINT UNSIGNED NOT NULL DEFAULT 0,
  upload_at_ms BIGINT UNSIGNED NULL,
  last_recorded_ms BIGINT UNSIGNED NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;