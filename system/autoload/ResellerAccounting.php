<?php
/** One definition of earned reseller profit for the Panel and mobile API. */
class ResellerAccounting
{
    public static function sales(PDO $db, int $resellerId, string $from, string $through): float
    {
        $legacy = $db->prepare("SELECT COALESCE(SUM(t.price),0) FROM tbl_transactions t
            JOIN tbl_customers c ON c.id=t.user_id
            WHERE c.reseller_id=?
            AND NOT EXISTS(SELECT 1 FROM tbl_profit_events pe WHERE pe.recharge_id=t.id)
            AND t.recharged_on>=? AND t.recharged_on<=?");
        $legacy->execute([$resellerId,$from,$through]);
        $events = $db->prepare("SELECT COALESCE(SUM(recharge_amount),0) FROM tbl_profit_events
            WHERE owner_reseller_id=? AND status='earned'
            AND effective_on>=? AND effective_on<=?");
        $events->execute([$resellerId,$from,$through]);
        return round((float)$legacy->fetchColumn()+(float)$events->fetchColumn(),2);
    }

    public static function earned(PDO $db, ?int $resellerId = null, ?string $from = null, ?string $through = null): float
    {
        $params = [];
        $where = "e.status='earned' AND NOT EXISTS
            (SELECT 1 FROM tbl_profit_events pe WHERE pe.recharge_id=e.recharge_id)";
        if ($resellerId !== null) { $where .= ' AND e.reseller_id=?'; $params[] = $resellerId; }
        if ($from !== null) { $where .= ' AND e.created_at>=?'; $params[] = $from.' 00:00:00'; }
        if ($through !== null) { $where .= ' AND e.created_at<=?'; $params[] = $through.' 23:59:59'; }
        $legacy = $db->prepare("SELECT COALESCE(SUM(e.profit_amount),0)
            FROM tbl_reseller_earnings e
            WHERE $where");
        $legacy->execute($params);

        $eventWhere = "status='earned' AND reseller_income<>0";
        $eventParams = [];
        if ($resellerId !== null) { $eventWhere .= ' AND owner_reseller_id=?'; $eventParams[] = $resellerId; }
        if ($from !== null) { $eventWhere .= ' AND effective_on>=?'; $eventParams[] = $from; }
        if ($through !== null) { $eventWhere .= ' AND effective_on<=?'; $eventParams[] = $through; }
        $events = $db->prepare("SELECT COALESCE(SUM(reseller_income),0) FROM tbl_profit_events WHERE $eventWhere");
        $events->execute($eventParams);
        return round((float)$legacy->fetchColumn() + (float)$events->fetchColumn(), 2);
    }

    public static function settled(PDO $db, ?int $resellerId = null): float
    {
        $where = '1=1';
        $params = [];
        if ($resellerId !== null) { $where .= ' AND s.reseller_id=?'; $params[] = $resellerId; }
        $q = $db->prepare("SELECT COALESCE(SUM(s.amount),0) FROM tbl_reseller_settlements s WHERE $where");
        $q->execute($params);
        return round((float)$q->fetchColumn(), 2);
    }

    public static function available(PDO $db, int $resellerId): float
    {
        return round(self::earned($db, $resellerId) - self::settled($db, $resellerId), 2);
    }
}
