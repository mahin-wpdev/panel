<?php

/** Durable handoff between network provisioning and the accounting ledger. */
class RechargeOutbox
{
    public static function begin(PDO $db, array $operation): void
    {
        $key = (string)($operation['operation_key'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/D', $key)) {
            throw new InvalidArgumentException('Invalid recharge operation key.');
        }

        $prior = self::find($db, $key);
        if ($prior) {
            if ((int)$prior['customer_id'] !== (int)$operation['customer_id'] ||
                (string)$prior['invoice'] !== (string)$operation['invoice']) {
                throw new RuntimeException('Recharge operation key is already bound to another request.');
            }
            if ($prior['status'] !== 'provisioning') {
                throw new RuntimeException('Recharge operation is already in reconciliation.');
            }
            return;
        }

        $q = $db->prepare("INSERT INTO tbl_recharge_provisioning_outbox
            (operation_key,customer_id,plan_id,router,gateway,channel,note,tax_amount,
             price_override,invoice,admin_id,status,attempts,last_error,created_at,updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,'provisioning',1,'',NOW(),NOW())");
        $q->execute([
            $key, (int)$operation['customer_id'], (int)$operation['plan_id'],
            (string)$operation['router'], (string)$operation['gateway'],
            (string)$operation['channel'], (string)$operation['note'],
            round((float)$operation['tax_amount'], 2), $operation['price_override'],
            (string)$operation['invoice'], (int)$operation['admin_id'],
        ]);
    }

    public static function find(PDO $db, string $key): ?array
    {
        $q = $db->prepare('SELECT * FROM tbl_recharge_provisioning_outbox WHERE operation_key=? LIMIT 1');
        $q->execute([$key]);
        $row = $q->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function markProvisioned(PDO $db, string $key): void
    {
        $q = $db->prepare("UPDATE tbl_recharge_provisioning_outbox
            SET status='provisioned',updated_at=NOW(),last_error=''
            WHERE operation_key=? AND status='provisioning'");
        $q->execute([$key]);
        if ($q->rowCount() !== 1) {
            throw new RuntimeException('Could not record successful network provisioning.');
        }
    }

    /** Call inside the same transaction that writes the transaction/profit ledger. */
    public static function complete(PDO $db, string $key, int $transactionId, string $invoice): void
    {
        $q = $db->prepare("UPDATE tbl_recharge_provisioning_outbox
            SET status='completed',transaction_id=?,invoice=?,updated_at=NOW(),last_error=''
            WHERE operation_key=? AND status='provisioned'");
        $q->execute([$transactionId, $invoice, $key]);
        if ($q->rowCount() !== 1) {
            throw new RuntimeException('Could not complete the recharge provisioning outbox entry.');
        }
    }

    public static function manualReview(PDO $db, string $key, Throwable $error): void
    {
        $message = mb_substr($error->getMessage(), 0, 1000);
        $q = $db->prepare("UPDATE tbl_recharge_provisioning_outbox
            SET status='manual_review',attempts=attempts+1,last_error=?,updated_at=NOW()
            WHERE operation_key=? AND status<>'completed'");
        $q->execute([$message, $key]);
        _log('Recharge operation '.$key.' requires manual reconciliation: '.$message);
    }

    public static function hasOutstandingCustomer(PDO $db, int $customerId): bool
    {
        $q = $db->prepare("SELECT COUNT(*) FROM tbl_recharge_provisioning_outbox
            WHERE customer_id=? AND status IN ('provisioning','provisioned','manual_review')");
        $q->execute([$customerId]);
        return (int)$q->fetchColumn() > 0;
    }

    /** Finalize only known-provisioned rows; uncertain router outcomes are never replayed blindly. */
    public static function processProvisioned(int $limit = 10, ?int $customerId = null): int
    {
        $db = ORM::get_db();
        $stale = $db->prepare("UPDATE tbl_recharge_provisioning_outbox
            SET status='manual_review',last_error='Provisioning outcome is unknown after worker interruption.',updated_at=NOW()
            WHERE status='provisioning' AND updated_at < DATE_SUB(NOW(),INTERVAL 30 MINUTE)");
        $stale->execute();

        $limit = max(1, min(100, $limit));
        $sql = "SELECT * FROM tbl_recharge_provisioning_outbox WHERE status='provisioned'";
        $params = [];
        if ($customerId !== null) { $sql .= ' AND customer_id=?'; $params[] = $customerId; }
        $sql .= ' ORDER BY created_at,id LIMIT '.$limit;
        $q = $db->prepare($sql);
        $q->execute($params);
        $rows = $q->fetchAll(PDO::FETCH_ASSOC);
        $processed = 0;

        foreach ($rows as $row) {
            $priorAdmin = $GLOBALS['admin'] ?? null;
            try {
                if ((int)$row['admin_id'] > 0) {
                    $actor = ORM::for_table('tbl_users')->where('id', (int)$row['admin_id'])->find_one();
                    if (!$actor || $actor['status'] !== 'Active') {
                        throw new RuntimeException('Original admin actor is unavailable.');
                    }
                    $GLOBALS['admin'] = $actor->as_array();
                } else {
                    $GLOBALS['admin'] = null;
                }

                $invoice = Package::replayProvisionedOperation($row);
                if (!$invoice) throw new RuntimeException('Ledger replay returned no invoice.');
                $processed++;
            } catch (Throwable $error) {
                self::manualReview($db, (string)$row['operation_key'], $error);
            } finally {
                $GLOBALS['admin'] = $priorAdmin;
            }
        }
        return $processed;
    }
}
