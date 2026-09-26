<?php

/**
 *  Arivo ISP Billing
 *  Maintainer: Mustafizur Rahman Mahin
 *  Upstream: https://github.com/hotspotbilling/phpnuxbill
 **/

header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Expires: Tue, 01 Jan 2000 00:00:00 GMT");
header("Pragma: no-cache");

run_hook('customer_logout'); #HOOK
if (session_status() == PHP_SESSION_NONE) session_start();
Admin::removeCookie();
User::removeCookie();
session_destroy();
_alert(Lang::T('Logout Successful'), 'warning', "login");
