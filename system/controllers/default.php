<?php
/**
 *  Arivo ISP Billing
 *  Maintainer: Mustafizur Rahman Mahin
 *  Upstream: https://github.com/hotspotbilling/phpnuxbill
 **/

if(Admin::getID()){
    //r2(getUrl('dashboard'));
    $handler = 'dashboard';
}else if(User::getID()){
    //r2(getUrl('home'));
    $handler = 'home';
}else{
    //r2(getUrl('login'));
    $handler = 'login';
}
include($root_path . File::pathFixer('system/controllers/' . $handler . '.php'));
