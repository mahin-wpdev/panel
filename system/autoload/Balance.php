<?php
/**
 *  PHP Mikrotik Billing (https://github.com/hotspotbilling/phpnuxbill/)
 *  by https://t.me/ibnux
 **/
/**
 *  This script is for managing user balance
 **/

class Balance
{

    public static function isValidAmount($amount): bool
    {
        $text = trim((string)$amount);
        return preg_match('/^[0-9]{1,8}(?:\.[0-9]{1,2})?$/D', $text) === 1
            && is_finite((float)$text) && (float)$text > 0;
    }

    /** Serialize all wallet purchases for one customer across every entry point. */
    public static function purchase($id_customer, $amount, callable $purchase)
    {
        $id = (int)$id_customer;
        $amount = round((float)$amount, 2);
        if ($id < 1 || !is_finite($amount) || $amount <= 0) {
            return false;
        }

        $db = ORM::get_db();
        $lockName = 'jm_wallet_' . $id;
        $lock = $db->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) {
            return false;
        }

        try {
            $q = $db->prepare('SELECT balance FROM tbl_customers WHERE id=?');
            $q->execute([$id]);
            $balance = $q->fetchColumn();
            if ($balance === false || (float)$balance + 0.0001 < $amount) {
                return false;
            }

            $result = $purchase();
            if (!$result) {
                return false;
            }

            // Conditional arithmetic avoids stale ORM read/modify/write debits.
            $debit = $db->prepare('UPDATE tbl_customers SET balance=balance-? WHERE id=? AND balance>=?');
            $debit->execute([$amount, $id, $amount]);
            if ($debit->rowCount() !== 1) {
                throw new RuntimeException('Recharge completed but wallet debit failed; reconcile this recharge before retrying.');
            }
            return $result;
        } finally {
            $release = $db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    public static function plus($id_customer, $amount)
    {
        if (!self::isValidAmount($amount)) return false;
        $q = ORM::get_db()->prepare('UPDATE tbl_customers SET balance=COALESCE(balance,0)+? WHERE id=?');
        $q->execute([(float)$amount, (int)$id_customer]);
        return $q->rowCount() === 1;
    }

    public static function transfer($id_customer, $phoneTarget, $amount)
    {
        $senderId = (int)$id_customer;
        $amountText = trim((string)$amount);
        if ($senderId < 1 || !self::isValidAmount($amountText)) return false;
        $amount = round((float)$amountText, 2);
        if ($amount <= 0 || trim((string)$phoneTarget) === '') return false;

        $db = ORM::get_db();
        $targetLookup = $db->prepare('SELECT id FROM tbl_customers WHERE username=?');
        $targetLookup->execute([(string)$phoneTarget]);
        $targetId = (int)$targetLookup->fetchColumn();
        if ($targetId < 1 || $targetId === $senderId) return false;
        $ids = [$senderId, $targetId];
        sort($ids, SORT_NUMERIC);
        $locks = [];
        foreach ($ids as $id) {
            $name = 'jm_wallet_' . $id;
            $q = $db->prepare('SELECT GET_LOCK(?, 0)');
            $q->execute([$name]);
            if ((int)$q->fetchColumn() !== 1) {
                foreach (array_reverse($locks) as $held) {
                    $release = $db->prepare('SELECT RELEASE_LOCK(?)');
                    $release->execute([$held]);
                }
                return false;
            }
            $locks[] = $name;
        }
        try {
            $db->beginTransaction();
            $rows = $db->prepare('SELECT id,balance,username FROM tbl_customers WHERE id IN (?,?) ORDER BY id FOR UPDATE');
            $rows->execute($ids);
            $balances = [];
            $usernames = [];
            foreach ($rows->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $balances[(int)$row['id']] = (float)$row['balance'];
                $usernames[(int)$row['id']] = (string)$row['username'];
            }
            if (!isset($balances[$senderId], $balances[$targetId]) || $balances[$senderId] + 0.0001 < $amount) {
                $db->rollBack();
                return false;
            }
            $debit = $db->prepare('UPDATE tbl_customers SET balance=balance-? WHERE id=? AND balance>=?');
            $debit->execute([$amount, $senderId, $amount]);
            if ($debit->rowCount() !== 1) throw new RuntimeException('Wallet transfer debit failed.');
            $credit = $db->prepare('UPDATE tbl_customers SET balance=COALESCE(balance,0)+? WHERE id=?');
            $credit->execute([$amount, $targetId]);
            if ($credit->rowCount() !== 1) throw new RuntimeException('Wallet transfer recipient disappeared.');

            $now = date('Y-m-d H:i:s');
            $ledger = $db->prepare('INSERT INTO tbl_payment_gateway
                (username,gateway,plan_id,plan_name,routers_id,routers,price,payment_method,
                 payment_channel,created_date,paid_date,expired_date,pg_url_payment,status)
                VALUES (?,?,0,\'Send Balance\',0,\'balance\',?,\'Customer\',\'Balance\',?,?,?,\'balance\',2)');
            $ledger->execute([$usernames[$senderId], $usernames[$targetId], $amount, $now, $now, $now]);
            $ledger = $db->prepare('INSERT INTO tbl_payment_gateway
                (username,gateway,plan_id,plan_name,routers_id,routers,price,payment_method,
                 payment_channel,created_date,paid_date,expired_date,pg_url_payment,status)
                VALUES (?,?,0,\'Receive Balance\',0,\'balance\',?,\'Customer\',\'Balance\',?,?,?,\'balance\',2)');
            $ledger->execute([$usernames[$targetId], $usernames[$senderId], $amount, $now, $now, $now]);
            $db->commit();
            return true;
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        } finally {
            foreach (array_reverse($locks) as $held) {
                $release = $db->prepare('SELECT RELEASE_LOCK(?)');
                $release->execute([$held]);
            }
        }
    }

    public static function min($id_customer, $amount)
    {
        $amount = round((float)$amount, 2);
        if (!is_finite($amount) || $amount <= 0) return false;
        $db = ORM::get_db();
        $lockName = 'jm_wallet_' . (int)$id_customer;
        $lock = $db->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) return false;
        try {
            $q = $db->prepare('UPDATE tbl_customers SET balance=balance-? WHERE id=? AND balance>=?');
            $q->execute([$amount, (int)$id_customer, $amount]);
            return $q->rowCount() === 1;
        } finally {
            $release = $db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    public static function plusByPhone($phone_customer, $amount)
    {
        if (!self::isValidAmount($amount)) return false;
        $q = ORM::get_db()->prepare('UPDATE tbl_customers SET balance=COALESCE(balance,0)+? WHERE username=?');
        $q->execute([(float)$amount, (string)$phone_customer]);
        return $q->rowCount() === 1;
    }

    public static function minByPhone($phone_customer, $amount)
    {
        $amount = round((float)$amount, 2);
        if (!is_finite($amount) || $amount <= 0) return false;
        $db = ORM::get_db();
        $lookup = $db->prepare('SELECT id FROM tbl_customers WHERE username=?');
        $lookup->execute([(string)$phone_customer]);
        $id = (int)$lookup->fetchColumn();
        if ($id < 1) return false;
        $lockName = 'jm_wallet_' . $id;
        $lock = $db->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        if ((int)$lock->fetchColumn() !== 1) return false;
        try {
            $q = $db->prepare('UPDATE tbl_customers SET balance=balance-? WHERE id=? AND balance>=?');
            $q->execute([$amount, $id, $amount]);
            return $q->rowCount() === 1;
        } finally {
            $release = $db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }
}
