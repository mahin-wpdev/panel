-- JM Broadband Mobile: revoke sessions on account-security changes.
--
-- Run once, only after tbl_mobile_auth_sessions has been created.
-- Select the intended database before executing this file.
-- Does not modify existing reseller auto-assignment triggers.
-- This file is NOT executed automatically by the Mobile API.

DELIMITER $$

DROP TRIGGER IF EXISTS trg_mobile_customer_revoke$$
CREATE TRIGGER trg_mobile_customer_revoke
AFTER UPDATE ON tbl_customers
FOR EACH ROW
BEGIN
    IF OLD.status <> NEW.status
       OR OLD.password <> NEW.password
       OR NOT (OLD.reseller_id <=> NEW.reseller_id)
    THEN
        UPDATE tbl_mobile_auth_sessions
        SET revoked_at = NOW()
        WHERE actor_type = 'customer'
          AND actor_id = NEW.id
          AND revoked_at IS NULL;
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_mobile_staff_revoke$$
CREATE TRIGGER trg_mobile_staff_revoke
AFTER UPDATE ON tbl_users
FOR EACH ROW
BEGIN
    IF OLD.status <> NEW.status
       OR OLD.password <> NEW.password
       OR OLD.user_type <> NEW.user_type
    THEN
        UPDATE tbl_mobile_auth_sessions
        SET revoked_at = NOW()
        WHERE actor_type = 'staff'
          AND actor_id = NEW.id
          AND revoked_at IS NULL;
    END IF;
END$$

DROP TRIGGER IF EXISTS trg_mobile_reseller_revoke$$
CREATE TRIGGER trg_mobile_reseller_revoke
AFTER UPDATE ON tbl_resellers
FOR EACH ROW
BEGIN
    IF OLD.status <> NEW.status
       OR OLD.user_id <> NEW.user_id
    THEN
        UPDATE tbl_mobile_auth_sessions
        SET revoked_at = NOW()
        WHERE actor_type = 'staff'
          AND actor_id IN (OLD.user_id, NEW.user_id)
          AND revoked_at IS NULL;
    END IF;
END$$

DELIMITER ;
