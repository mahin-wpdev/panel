<?php
/**
 * Customer-owned, read-only MikroTik PPPoE foreground traffic snapshot.
 * No caller-supplied customer ID, router host, interface, or credential is accepted.
 * This is NOT a background collector and does not synchronise billing / OLT every second.
 */
declare(strict_types=1);

function jm_live_unavailable(string $why): array {
    return ['available'=>false, 'message'=>$why, 'online'=>null,
        'download_bps'=>null, 'upload_bps'=>null, 'observed_at'=>gmdate('c')];
}

function jm_live_rate(?string $raw): ?float {
    if ($raw === null) return null;
    // RouterOS API commonly returns an integer; tolerate CLI-style units too.
    if (!preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)\s*([kmg]?)\s*(?:bps|b\/s)?\s*$/i', $raw, $m)) return null;
    $factor = match (strtolower($m[2])) { 'k'=>1e3, 'm'=>1e6, 'g'=>1e9, default=>1 };
    return (float)$m[1] * $factor;
}

function jm_live_snapshot(PDO $db, array $session): array {
    require_once __DIR__.'/panel-app.php';
    $person = jm_app_actor($db, $session);
    if ($person['role'] !== 'customer') respond(403, ['error'=>'FORBIDDEN']);
    if (!jm_app_table($db,'tbl_routers') || !jm_app_table($db,'tbl_user_recharges')) {
        return jm_live_unavailable('This Panel has no compatible PPPoE/router configuration.');
    }
    global $_app_stage;
    if (strtolower((string)($_app_stage ?? '')) === 'demo') {
        return jm_live_unavailable('Router access is disabled in Panel demo mode.');
    }
    $customer=jm_mobile_query($db,
        'SELECT id,username,pppoe_username,status FROM tbl_customers WHERE id=? LIMIT 1',
        [(int)$person['id']])->fetch(PDO::FETCH_ASSOC);
    if (!$customer || $customer['status']==='Banned') respond(403,['error'=>'ACCOUNT_DISABLED']);
    $pppoe=trim((string)($customer['pppoe_username'] ?: $customer['username']));
    if ($pppoe==='' || strlen($pppoe)>100) return jm_live_unavailable('PPPoE username is not configured.');
    $recharge=jm_mobile_query($db,
        "SELECT routers FROM tbl_user_recharges WHERE customer_id=? AND status='on' " .
        'ORDER BY expiration DESC,id DESC LIMIT 1',
        [(int)$person['id']])->fetch(PDO::FETCH_ASSOC);
    $routerName=trim((string)($recharge['routers'] ?? ''));
    if ($routerName==='') return jm_live_unavailable('No active recharge/router mapping for this customer.');
    $router=jm_mobile_query($db,
        'SELECT ip_address,username,password,enabled FROM tbl_routers WHERE name=? LIMIT 1',
        [$routerName])->fetch(PDO::FETCH_ASSOC);
    if (!$router || (int)$router['enabled']!==1) return jm_live_unavailable('Assigned router is not available.');
    $where=explode(':',(string)$router['ip_address'],2);
    $host=$where[0]; $port=isset($where[1])?(int)$where[1]:8728;
    if ($host==='' || $port<1 || $port>65535) return jm_live_unavailable('Router connection configuration is invalid.');

    try {
        $client=new \PEAR2\Net\RouterOS\Client($host, (string)$router['username'],
            (string)$router['password'], $port);
        $request=new \PEAR2\Net\RouterOS\Request('/ppp/active/print');
        $request->setArgument('.proplist','name,service,address,uptime');
        $request->setQuery(\PEAR2\Net\RouterOS\Query::where('name',$pppoe));
        $records=$client->sendSync($request)
            ->getAllOfType(\PEAR2\Net\RouterOS\Response::TYPE_DATA);
        $active=null;
        foreach ($records as $entry) {
            if ((string)$entry->getProperty('name') === $pppoe &&
                (string)$entry->getProperty('service') === 'pppoe') {
                $active=$entry;
                break;
            }
        }
        if ($active===null) {
            return ['available'=>true,'online'=>false,'download_bps'=>0,
                'upload_bps'=>0,'observed_at'=>gmdate('c'),
                'message'=>'No active PPPoE session on the assigned router.'];
        }
        // Dynamic PPPoE server interfaces are normally <pppoe-username>.
        // Do not guess another user's interface or expose router details if missing.
        $iface='<pppoe-'.$pppoe.'>';
        $monitor=new \PEAR2\Net\RouterOS\Request('/interface/monitor-traffic');
        $monitor->setArgument('interface',$iface);
        $monitor->setArgument('once','');
        $samples=$client->sendSync($monitor)
            ->getAllOfType(\PEAR2\Net\RouterOS\Response::TYPE_DATA);
        $sample=$samples->current();
        if (!$sample) return ['available'=>true,'online'=>true,'download_bps'=>null,
            'upload_bps'=>null,'observed_at'=>gmdate('c'),
            'message'=>'Connected; live interface counters are unavailable for this PPPoE session.'];
        // From the router's point of view: TX to client = download;
        // RX from client = upload. Never swap these labels.
        $download=jm_live_rate($sample->getProperty('tx-bits-per-second'));
        $upload=jm_live_rate($sample->getProperty('rx-bits-per-second'));
        return ['available'=>true,'online'=>true,'download_bps'=>$download,
            'upload_bps'=>$upload,'observed_at'=>gmdate('c'),
            'message'=>($download===null || $upload===null)
                ? 'Connected; traffic rate is unavailable from this interface.' : null];
    } catch (Throwable $error) {
        // Avoid logging router credentials or returning internal topology/stack traces.
        error_log('JM live traffic: read-only RouterOS query failed ('.get_class($error).')');
        return jm_live_unavailable('Unable to read live PPPoE traffic from the assigned router.');
    }
}
