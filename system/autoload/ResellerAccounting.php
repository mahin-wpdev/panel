<?php
/** One definition of earned reseller profit for the Panel and mobile API. */
class ResellerAccounting
{
    public static function sales(PDO $db, ?int $resellerId, string $from, string $through): float
    {
        $ownerFilter = $resellerId === null ? 'owner_reseller_id>0' : 'owner_reseller_id=?';
        $legacyParams = $resellerId === null ? [$from,$through] : [$resellerId,$from,$through];
        $legacy = $db->prepare("SELECT COALESCE(SUM(CAST(t.price AS DECIMAL(15,2))),0) FROM tbl_transactions t
            WHERE t.owner_snapshot_saved=1 AND $ownerFilter AND t.type IN ('Hotspot','PPPOE')
            AND NOT EXISTS(SELECT 1 FROM tbl_profit_events pe WHERE pe.recharge_id=t.id)
            AND t.recharged_on>=? AND t.recharged_on<=?");
        $legacy->execute($legacyParams);
        $eventOwnerFilter = $resellerId === null ? 'owner_reseller_id IS NOT NULL' : 'owner_reseller_id=?';
        $eventParams = $resellerId === null ? [$from,$through] : [$resellerId,$from,$through];
        $events = $db->prepare("SELECT COALESCE(SUM(recharge_amount),0) FROM tbl_profit_events
            WHERE $eventOwnerFilter AND status='earned'
            AND effective_on>=? AND effective_on<=?");
        $events->execute($eventParams);
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
