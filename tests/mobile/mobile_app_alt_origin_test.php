<?php
/** Pure origin and SMS-link checks; no DB, GitHub, or billing side effects. */
define('APP_URL', 'https://27.147.201.165/panel');
require dirname(__DIR__, 2) . '/system/mobile/github-release.php';
$release = ['download_url' => 'ignored', 'sha256' => str_repeat('a',64)];
unset($_SERVER['HTTP_HOST']);
if (jmapp_contextualize_release($release)['download_url'] !==
 'https://27.147.201.165/panel/mobile-app-download.php') throw new RuntimeException('Default origin wrong');
$_SERVER['HTTP_HOST'] = '27.147.201.165:8443';
if (jmapp_contextualize_release($release)['download_url'] !==
 'https://27.147.201.165:8443/panel/mobile-app-download.php') throw new RuntimeException('LTE origin wrong');
$_SERVER['HTTP_HOST'] = 'untrusted.example:8443';
if (jmapp_contextualize_release($release)['download_url'] !==
 'https://27.147.201.165/panel/mobile-app-download.php') throw new RuntimeException('Untrusted host accepted');
if (jmapp_replace_placeholder('Download [[app_download_link]]') !==
 'Download https://27.147.201.165:8443/panel/mobile-app-download.php')
 throw new RuntimeException('LTE placeholder wrong');
echo "PANEL_ALT_ORIGIN_AND_CACHED_LINK_TESTS_OK\n";
