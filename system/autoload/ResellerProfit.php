<?php
/** Record the accounting split for a completed package recharge. */
class ResellerProfit
{
    public static function record($customer, $transaction, $plan)
    {
        $db = ORM::get_db();
        $ownsTransaction = !$db->inTransaction();
        try {
            if (empty($customer['id']) || empty($transaction['id'])) return false;
            if ($ownsTransaction) $db->beginTransaction();

            $resellerId = (int)($customer['reseller_id'] ?? 0);
            $event = $db->prepare('SELECT id FROM tbl_profit_events WHERE recharge_id=?');
            $event->execute([(int)$transaction['id']]);
            if ($event->fetchColumn()) { if ($ownsTransaction) $db->rollBack(); return false; }

            $method = (string)($transaction['method'] ?? '');
            global $admin;
            $adminActor = self::isAdminActor($method, $admin['user_type'] ?? '');
            $actorType = $adminActor ? 'admin' : ($resellerId > 0 ? 'reseller' : 'customer');
            if ($resellerId === 0) {
                if (!in_array($method, ['Customer - Balance', 'Recharge Balance - Administrator'], true)) {
                    $amount = round((float)$transaction['price'], 2);
                    $event = $db->prepare("INSERT INTO tbl_profit_events
                        (recharge_id,customer_id,owner_reseller_id,actor_type,method,recharge_amount,
                         base_cost,gross_profit,profit_type,profit_value,direct_income,admin_share,
                         reseller_income,status,effective_on,created_at)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'earned',?,NOW())");
                    $event->execute([(int)$transaction['id'], (int)$customer['id'], $resellerId ?: null,
                        $actorType, $method, $amount, $amount, $amount,
                        'direct', 0, $amount, 0, 0,
                        (string)($transaction['recharged_on'] ?? date('Y-m-d'))]);
                }
                if ($ownsTransaction) $db->commit();
                return true;
            }

            $lock = $db->prepare('SELECT id FROM tbl_resellers WHERE id=? FOR UPDATE');
            $lock->execute([$resellerId]);

            if (ORM::for_table('tbl_reseller_earnings')->where('recharge_id', $transaction['id'])->find_one()) {
                if ($ownsTransaction) $db->rollBack();
                return false;
            }
            $reseller = ORM::for_table('tbl_resellers')->where('id', $resellerId)->where('status', 'active')->find_one();
            if (!$reseller) {
                self::recordDirect($db,$customer,$transaction,$actorType,$method,$ownsTransaction);
                return true;
            }
            $rp = ORM::for_table('tbl_reseller_packages')->where('reseller_id', $resellerId)
                ->where('plan_id', $plan['id'])->find_one();
            if ($rp && empty($rp['status'])) {
                self::recordDirect($db,$customer,$transaction,$actorType,$method,$ownsTransaction);
                return true;
            }
            $rp = self::resolvePackageConfig($rp, $reseller);

            $selling = round((float)$transaction['price'], 2);
            $split = self::calculateSplit($selling, $rp);
            $base = $split['base_cost'];
            $gross = $split['gross_profit'];
            $type = $split['profit_type'];
            $value = $split['profit_value'];
            $resellerProfit = $split['reseller_profit'];
            $adminProfit = $split['admin_profit'];

            $e = ORM::for_table('tbl_reseller_earnings')->create();
            $e->reseller_id = $resellerId; $e->customer_id = $customer['id'];
            $e->recharge_id = $transaction['id']; $e->package_id = $plan['id'];
            $e->reseller_package_id = $rp['id']; $e->recharge_amount = $selling;
            $e->base_cost = $base; $e->gross_profit = $gross; $e->profit_type = $type;
            $e->profit_value = $value; $e->profit_percentage = $type === 'percentage' ? $value : 0;
            $e->profit_amount = $resellerProfit; $e->admin_profit = $adminProfit;
            $e->status = 'earned'; $e->created_at = date('Y-m-d H:i:s');
            $e->updated_at = date('Y-m-d H:i:s'); $e->save();

            $event = $db->prepare("INSERT INTO tbl_profit_events
                (recharge_id,customer_id,owner_reseller_id,actor_type,method,recharge_amount,
                 base_cost,gross_profit,profit_type,profit_value,direct_income,admin_share,
                 reseller_income,status,effective_on,created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,0,?,?,'earned',?,NOW())");
            $event->execute([(int)$transaction['id'], (int)$customer['id'], $resellerId,
                $actorType, $method, $selling, $base,
                $gross, $type, $value, $adminProfit, $resellerProfit,
                (string)($transaction['recharged_on'] ?? date('Y-m-d'))]);

            require_once __DIR__.'/ResellerDebt.php';
            ResellerDebt::recover($db, $resellerId, (int)$transaction['id'], $resellerProfit);
            if ($ownsTransaction) $db->commit();
            _log('Reseller earning #'.$e->id.' created for recharge #'.$transaction['id']);
            return true;
        } catch (Throwable $e) {
            if ($ownsTransaction && $db->inTransaction()) $db->rollBack();
            _log('Reseller earning skipped: '.$e->getMessage());
            if (!$ownsTransaction) throw $e;
            return false;
        }
    }

    public static function isAdminActor(string $method, string $userType): bool
    {
        return strncmp($method, 'Admin Manual - ', 15) === 0
            || in_array($userType, ['Admin', 'SuperAdmin'], true);
    }

    /** Use the reseller profile rate when a plan has no explicit package rule. */
    public static function resolvePackageConfig($packageRule, $reseller)
    {
        if ($packageRule) return $packageRule;
        return [
            'id' => null,
            'base_cost' => 0,
            'profit_type' => 'percentage',
            'profit_value' => max(0, min(100, (float)($reseller['profit_percentage'] ?? 0))),
        ];
    }

    public static function calculateSplit(float $selling, $packageRule): array
    {
        $base = round(max(0, (float)$packageRule['base_cost']), 2);
        $gross = round(max(0, $selling - $base), 2);
        $type = ($packageRule['profit_type'] ?? '') === 'fixed' ? 'fixed' : 'percentage';
        $value = max(0, min($type === 'percentage' ? 100 : $gross, (float)$packageRule['profit_value']));
        $resellerProfit = round($type === 'fixed' ? min($gross, $value) : $gross * $value / 100, 2);
        return [
            'base_cost' => $base,
            'gross_profit' => $gross,
            'profit_type' => $type,
            'profit_value' => $value,
            'reseller_profit' => $resellerProfit,
            'admin_profit' => round($gross - $resellerProfit, 2),
        ];
    }

    private static function recordDirect(PDO $db,$customer,$transaction,$actorType,$method,$ownsTransaction):void
    {
        $amount=round((float)$transaction['price'],2);
        $event=$db->prepare("INSERT INTO tbl_profit_events
            (recharge_id,customer_id,owner_reseller_id,actor_type,method,recharge_amount,
             base_cost,gross_profit,profit_type,profit_value,direct_income,admin_share,
             reseller_income,status,effective_on,created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'earned',?,NOW())");
        $event->execute([(int)$transaction['id'],(int)$customer['id'],(int)($customer['reseller_id']??0)?:null,
            $actorType,$method,$amount,$amount,$amount,'direct',0,$amount,0,0,
            (string)($transaction['recharged_on']??date('Y-m-d'))]);
        if($ownsTransaction)$db->commit();
    }
}
