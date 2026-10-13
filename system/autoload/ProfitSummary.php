<?php
/** Panel-owned income: direct sales + ISP share of reseller-customer sales. */
class ProfitSummary
{
    public static function period(PDO $db, ?string $from = null, ?string $through = null): array
    {
        $adminShareWhere = "e.status = 'earned'
            AND NOT EXISTS (SELECT 1 FROM tbl_profit_events pe WHERE pe.recharge_id=e.recharge_id)";
        $legacyParams = [];
        if ($from !== null) { $adminShareWhere .= ' AND t.recharged_on >= ?'; $legacyParams[] = $from; }
        if ($through !== null) { $adminShareWhere .= ' AND t.recharged_on <= ?'; $legacyParams[] = $through; }
        $legacy = $db->prepare("SELECT COALESCE(SUM(e.admin_profit),0) admin_profit,
            COALESCE(SUM(e.profit_amount),0) reseller_profit
            FROM tbl_reseller_earnings e JOIN tbl_transactions t ON t.id=e.recharge_id
            LEFT JOIN tbl_users au ON au.id=t.admin_id
            WHERE ".$adminShareWhere);
        $legacy->execute($legacyParams);
        $legacyRow = $legacy->fetch(PDO::FETCH_ASSOC) ?: ['admin_profit'=>0,'reseller_profit'=>0];

        $eventWhere = "status='earned'"; $eventParams = [];
        if ($from !== null) { $eventWhere .= ' AND effective_on >= ?'; $eventParams[] = $from; }
        if ($through !== null) { $eventWhere .= ' AND effective_on <= ?'; $eventParams[] = $through; }
        $events = $db->prepare("SELECT COALESCE(SUM(direct_income),0) direct_income,
            COALESCE(SUM(admin_share),0) admin_share,
            COALESCE(SUM(reseller_income),0) reseller_income FROM tbl_profit_events WHERE ".$eventWhere);
        $events->execute($eventParams);
        $eventRow = $events->fetch(PDO::FETCH_ASSOC) ?: ['direct_income'=>0,'admin_share'=>0,'reseller_income'=>0];

        // Legacy direct income belongs to the reseller owner captured on the
        // transaction, never to the customer's current assignment.
        $legacyDirectWhere = "t.owner_snapshot_saved=1 AND t.owner_reseller_id=0
            AND t.type IN ('Hotspot','PPPOE')
            AND NOT EXISTS (SELECT 1 FROM tbl_profit_events pe WHERE pe.recharge_id=t.id)
            AND NOT EXISTS (SELECT 1 FROM tbl_reseller_earnings re WHERE re.recharge_id=t.id)";
        $directParams = [];
        if ($from !== null) { $legacyDirectWhere .= ' AND t.recharged_on >= ?'; $directParams[] = $from; }
        if ($through !== null) { $legacyDirectWhere .= ' AND t.recharged_on <= ?'; $directParams[] = $through; }
        $legacyDirect = $db->prepare("SELECT COALESCE(SUM(CAST(t.price AS DECIMAL(15,2))),0)
            FROM tbl_transactions t
            LEFT JOIN tbl_users au ON au.id=t.admin_id
            WHERE ".$legacyDirectWhere);
        $legacyDirect->execute($directParams);

        $direct = round((float)$legacyDirect->fetchColumn() + (float)$eventRow['direct_income'], 2);
        $adminShare = round((float)$legacyRow['admin_profit'] + (float)$eventRow['admin_share'], 2);
        $reseller = round((float)$legacyRow['reseller_profit'] + (float)$eventRow['reseller_income'], 2);
        $own = round($direct + $adminShare, 2);
        return ['direct'=>$direct,'reseller_admin'=>$adminShare,'admin'=>$own,
            'reseller'=>$reseller,'total'=>$own];
    }

    public static function periodStart(string $today, int $resetDay): string
    {
        $date = new DateTimeImmutable($today);
        $resetDay = max(1, min(31, $resetDay));
        $monthStart = static function (DateTimeImmutable $month, int $day): DateTimeImmutable {
            $day = min($day, (int)$month->format('t'));
            return $month->setDate((int)$month->format('Y'), (int)$month->format('m'), $day);
        };
        $start = $monthStart($date, $resetDay);
        if ($date < $start) $start = $monthStart($date->modify('first day of previous month'), $resetDay);
        return $start->format('Y-m-d');
    }

    public static function dashboard(PDO $db, int $resetDay = 1): array
    {
        $today = date('Y-m-d');
        return ['today'=>self::period($db,$today,$today),
            'month'=>self::period($db,self::periodStart($today,$resetDay),$today),
            'lifetime'=>self::period($db,null,$today)];
    }
}
