<?php
declare(strict_types=1);
// Run by cron. It never changes OLT configuration; it only refreshes local cache.
$_SERVER['HTTP_HOST']='27.147.201.165';
$_SERVER['SERVER_PORT']='80';
$_SERVER['REQUEST_SCHEME']='http';
$_SERVER['SCRIPT_NAME']='/panel/index.php';
$_SERVER['REQUEST_URI']='/panel/';
require '/www/wwwroot/27.147.201.165/panel/init.php';
require '/www/wwwroot/27.147.201.165/panel/system/autoload/OltManager.php';
$lock=fopen('/tmp/phpnuxbill-olt-sync.lock','c');
if(!$lock||!flock($lock,LOCK_EX|LOCK_NB)) exit(0);
foreach(ORM::for_table('tbl_olts')->where_not_equal('status','Disabled')->find_many() as $olt){
    try {
        $result=OltManager::sync($olt);
        $olt->status='Online'; $olt->last_sync_at=date('Y-m-d H:i:s'); $olt->last_sync_status='success'; $olt->last_sync_error=null; $olt->updated_at=date('Y-m-d H:i:s'); $olt->save();
        echo 'OLT '.$olt->id.' synced '.$result['found'].PHP_EOL;
    } catch(Throwable $e) {
        // Do not mass-mark ONUs offline on a connectivity failure.
        $olt->last_sync_at=date('Y-m-d H:i:s'); $olt->last_sync_status='failed'; $olt->last_sync_error=substr($e->getMessage(),0,255); $olt->updated_at=date('Y-m-d H:i:s'); $olt->save();
        echo 'OLT '.$olt->id.' failed'.PHP_EOL;
    }
}
flock($lock,LOCK_UN);
