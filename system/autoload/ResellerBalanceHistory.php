<?php

/** Build the admin-facing available-profit ledger from earnings and settlements. */
class ResellerBalanceHistory
{
    public static function recent(PDO $db, int $limit = 100): array
    {
        $limit = max(1, min(500, $limit));
        $history = $db->query("SELECT * FROM tbl_reseller_settlements
            ORDER BY created_at DESC, id DESC LIMIT $limit")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($history as &$entry) {
            $entry['history_id'] = '#'.$entry['id'];
            $entry['balance_change'] = round(0 - (float)$entry['amount'], 2);
            $entry['balance_change_abs'] = abs($entry['balance_change']);
            $entry['history_admin'] = !empty($entry['processed_by'])
                ? '#'.$entry['processed_by']
                : 'System';
        }
        unset($entry);

        $queries = [
            "SELECT pe.recharge_id,pe.owner_reseller_id reseller_id,pe.reseller_income profit_amount,
                    pe.profit_type,pe.profit_value,pe.recharge_amount,pe.effective_on,pe.created_at,
                    t.recharged_on,t.recharged_time,c.username,t.plan_name
             FROM tbl_profit_events pe
             LEFT JOIN tbl_transactions t ON t.id=pe.recharge_id
             LEFT JOIN tbl_customers c ON c.id=pe.customer_id
             WHERE pe.status='earned' AND pe.reseller_income>0
             ORDER BY pe.effective_on DESC,pe.id DESC LIMIT $limit",
            "SELECT e.recharge_id,e.reseller_id,e.profit_amount,e.profit_type,e.profit_value,
                    e.recharge_amount,e.created_at,t.recharged_on,t.recharged_time,c.username,t.plan_name
             FROM tbl_reseller_earnings e
             LEFT JOIN tbl_transactions t ON t.id=e.recharge_id
             LEFT JOIN tbl_customers c ON c.id=e.customer_id
             WHERE e.status='earned' AND e.profit_amount>0
               AND NOT EXISTS(SELECT 1 FROM tbl_profit_events pe WHERE pe.recharge_id=e.recharge_id)
             ORDER BY e.created_at DESC,e.id DESC LIMIT $limit",
        ];

        foreach ($queries as $query) {
            foreach ($db->query($query)->fetchAll(PDO::FETCH_ASSOC) as $profit) {
                $date = trim((string)($profit['recharged_on'] ?? ''));
                $time = trim((string)($profit['recharged_time'] ?? ''));
                if ($date === '') {
                    $date = substr((string)($profit['effective_on'] ?? $profit['created_at'] ?? date('Y-m-d')), 0, 10);
                }
                if ($time === '') {
                    $time = '00:00:00';
                }

                $rate = ($profit['profit_type'] ?? 'percentage') === 'fixed'
                    ? 'fixed Tk '.number_format((float)$profit['profit_value'], 2)
                    : number_format((float)$profit['profit_value'], 2).'% share';
                $amount = round((float)$profit['profit_amount'], 2);
                $history[] = [
                    'id' => 'recharge-'.$profit['recharge_id'],
                    'history_id' => 'Recharge #'.$profit['recharge_id'],
                    'created_at' => $date.' '.$time,
                    'reseller_id' => (int)$profit['reseller_id'],
                    'entry_type' => 'profit_earned',
                    'balance_change' => $amount,
                    'balance_change_abs' => abs($amount),
                    'note' => 'Reseller profit share earned ('.$rate.')'.(!empty($profit['username']) ? ' for '.$profit['username'] : ''),
                    'reference' => 'Recharge #'.$profit['recharge_id'].(!empty($profit['plan_name']) ? ' — '.$profit['plan_name'] : ''),
                    'processed_by' => null,
                    'history_admin' => 'System',
                    'reversal_of' => null,
                ];
            }
        }

        usort($history, static function (array $left, array $right): int {
            $date = strcmp((string)$right['created_at'], (string)$left['created_at']);
            return $date !== 0 ? $date : strcmp((string)$right['history_id'], (string)$left['history_id']);
        });

        return array_slice($history, 0, $limit);
    }
}
