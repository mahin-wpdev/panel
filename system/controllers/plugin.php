<?php
/** Plugin dispatcher fallback for the reseller dashboard.
 * The legacy plugin loader ignores registration exceptions; load this known,
 * local module explicitly when its route is requested. */
foreach (glob($root_path . 'system/plugin/*.php') as $module) require_once $module;
// The reseller module is now a first-class controller.  Keep old bookmarks
// working without rendering a second menu or the old "Function not found" page.
if (($routes[1] ?? '') === 'reseller') {
    r2(getUrl('reseller'));
}
if (function_exists($routes[1])) {
    call_user_func($routes[1]);
} else {
    r2(getUrl('dashboard'), 'e', 'Function not found');
}
