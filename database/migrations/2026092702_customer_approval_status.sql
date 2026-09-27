ALTER TABLE tbl_customers
  MODIFY approval_status VARCHAR(16) NOT NULL DEFAULT 'approved';

UPDATE tbl_customers
SET approval_status = LOWER(TRIM(approval_status));

ALTER TABLE tbl_customers
  MODIFY approval_status ENUM('pending','approved','rejected')
  NOT NULL DEFAULT 'approved';
