<?php
/* Shared, read-only customer traffic helper. Never returns router credentials. */
class CustomerTraffic
{
    public static $lastError = '';
    public static function human($value, $perSecond = false)
    {
        $value = (float)$value; $units = ['bps','Kbps','Mbps','Gbps','Tbps']; $i = 0;
        while ($value >= 1000 && $i < count($units) - 1) { $value /= 1000; $i++; }
        return number_format($value, $value >= 100 ? 0 : 2) . ' ' . $units[$i] . ($perSecond ? '' : '');
    }
    public static function bytes($value)
    {
        if (function_exists('UserDataUsage_human')) return UserDataUsage_human($value);
        $value=(float)$value; foreach (['bytes','KB','MB','GB','TB'] as $unit) { if ($value < 1024) return number_format($value,2).' '.$unit; $value/=1024; } return number_format($value,2).' PB';
    }
    public static function report($customer)
    {
        $username = !empty($customer['pppoe_username']) ? $customer['pppoe_username'] : $customer['username'];
        $historic = null;
        if (function_exists('UserDataUsage_schema')) {
            try { $schema=UserDataUsage_schema(); if($schema){ $row=ORM::for_table($schema['table'],$schema['conn'])->where('username',$username)->select_expr('SUM(COALESCE(acctinputoctets,0))','in_sum')->select_expr('SUM(COALESCE(acctoutputoctets,0))','out_sum')->find_one(); $historic=['upload'=>(float)($row?$row->in_sum:0),'download'=>(float)($row?$row->out_sum:0)]; } } catch (Throwable $e) { $historic=null; }
        }
        $live = null;
        try {
            $active=ORM::for_table('tbl_user_recharges')->where('customer_id',$customer['id'])->where('status','on')->order_by_desc('id')->find_one();
            if($active){$plan=ORM::for_table('tbl_plans')->find_one($active['plan_id']);if($plan && in_array($plan['device'],['MikrotikPppoe','Radius'],true)){require_once __DIR__.'/../devices/MikrotikPppoe.php';$live=(new MikrotikPppoe())->traffic_customer($customer,($plan['routers'] ?: 'Mikrotik'));}}
        } catch (Throwable $e) { self::$lastError=$e->getMessage(); _log('Customer traffic monitor error: '.$e->getMessage()); $live=null; }
        return ['historical'=>$historic,'live'=>$live];
    }
    public static function json($customer)
    {
        $r=self::report($customer);$h=$r['historical'];$l=$r['live'];
        /* RouterOS interface TX flows router → subscriber (download); RX flows subscriber → router (upload). */
        return ['accounting_available'=>$h!==null,'total_download'=>$h?self::bytes($h['download']):'Unavailable','total_upload'=>$h?self::bytes($h['upload']):'Unavailable','total_usage'=>$h?self::bytes($h['download']+$h['upload']):'Unavailable','connected'=>$l?!!$l['connected']:null,'session_download'=>$l?self::bytes($l['bytes_out']):'Unavailable','session_upload'=>$l?self::bytes($l['bytes_in']):'Unavailable','session_total'=>$l?self::bytes($l['bytes_in']+$l['bytes_out']):'Unavailable','download_speed'=>$l?self::human($l['tx_bps'],true):'Unavailable','upload_speed'=>$l?self::human($l['rx_bps'],true):'Unavailable','download_bps'=>$l?(float)$l['tx_bps']:0,'upload_bps'=>$l?(float)$l['rx_bps']:0,'uptime'=>$l&&!empty($l['uptime'])?$l['uptime']:'—'];
    }
}
