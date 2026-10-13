-- Freeze transaction ownership so moving a customer cannot rewrite sales history.
ALTER TABLE tbl_transactions
  ADD COLUMN IF NOT EXISTS owner_reseller_id INT UNSIGNED NOT NULL DEFAULT 0 AFTER user_id,
  ADD COLUMN IF NOT EXISTS owner_snapshot_saved TINYINT(1) NOT NULL DEFAULT 0 AFTER owner_reseller_id;

-- For older transactions, preserve the ownership visible at migration time.
-- Earlier ownership transfers cannot be reconstructed because no history existed.
UPDATE tbl_transactions t
LEFT JOIN tbl_profit_events pe ON pe.recharge_id=t.id
LEFT JOIN tbl_reseller_earnings e ON e.recharge_id=t.id
LEFT JOIN tbl_customers c ON c.id=t.user_id
SET t.owner_reseller_id=CASE
      WHEN pe.id IS NOT NULL THEN COALESCE(pe.owner_reseller_id,0)
      WHEN e.recharge_id IS NOT NULL THEN COALESCE(e.reseller_id,0)
      ELSE COALESCE(c.reseller_id,0)
    END,
    t.owner_snapshot_saved=1
WHERE t.owner_snapshot_saved=0;
