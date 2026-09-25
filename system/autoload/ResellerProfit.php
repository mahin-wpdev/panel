<?php
/** Immutable reseller-profit ledger written only after a core recharge succeeds. */
class ResellerProfit
{
    public static function record($customer, $transaction, $plan)
    {
        try {
            if (empty($customer['reseller_id']) || empty($transaction['id'])) return false;
            if (ORM::for_table('tbl_reseller_earnings')->where('recharge_id', $transaction['id'])->find_one()) return false;
            $reseller = ORM::for_table('tbl_resellers')->where('id', $customer['reseller_id'])->where('status', 'active')->find_one();
            if (!$reseller) return false;
            $rp = ORM::for_table('tbl_reseller_packages')->where('reseller_id', $reseller['id'])->where('plan_id', $plan['id'])->where('status', 1)->find_one();
            if (!$rp) return false;
            $selling = (float)$transaction['price'];
            $base = max(0, (float)$rp['base_cost']);
            $gross = max(0, $selling - $base);
            $type = $rp['profit_type'] === 'fixed' ? 'fixed' : 'percentage';
            $value = max(0, (float)$rp['profit_value']);
            $resellerProfit = $type === 'fixed' ? min($gross, $value) : min($gross, $gross * min(100, $value) / 100);
            $e = ORM::for_table('tbl_reseller_earnings')->create();
            $e->reseller_id = $reseller['id']; $e->customer_id = $customer['id']; $e->recharge_id = $transaction['id'];
            $e->package_id = $plan['id']; $e->reseller_package_id = $rp['id']; $e->recharge_amount = $selling;
            $e->base_cost = $base; $e->gross_profit = $gross; $e->profit_type = $type; $e->profit_value = $value;
            $e->profit_percentage = $type === 'percentage' ? $value : 0; $e->profit_amount = $resellerProfit;
            $e->admin_profit = $gross - $resellerProfit; $e->status = 'earned'; $e->created_at = date('Y-m-d H:i:s'); $e->updated_at = date('Y-m-d H:i:s'); $e->save();
            _log('Reseller earning #'.$e->id.' created for recharge #'.$transaction['id']); return true;
        } catch (Throwable $e) { _log('Reseller earning skipped: '.$e->getMessage()); return false; }
    }
}
