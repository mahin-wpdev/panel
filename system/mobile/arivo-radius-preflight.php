<?php
/** Read-only CLI preflight; never writes NAS, accounts, PPP, or billing data. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
ini_set('display_errors', '0');
$isApi = true;
require dirname(__DIR__, 2) . '/init.php';

function arivo_preflight_table(PDO $db, string $name): bool {
    $q = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $q->execute([$name]);
    return (int)$q->fetchColumn() === 1;
}
function arivo_preflight_column(PDO $db, string $table, string $column): bool {
    $q = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $q->execute([$table, $column]);
    return (int)$q->fetchColumn() === 1;
}
$out = ['radius_configured' => false, 'panel_radacct' => false,
    'radius_radacct' => false, 'schema_compatible' => false,
    'recent_sessions' => null, 'active_sessions' => null,
    'nas_configured' => null, 'php_cli' => PHP_VERSION,
    'warning' => 'Read-only preflight; no router configuration verified.'];
try {
    $panel = ORM::get_db();
    $panel->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $out['panel_radacct'] = arivo_preflight_table($panel, 'radacct');
    $out['radius_configured'] =
        !empty($radius_user) && !empty($config['radius_enable']);
    $radius = $out['radius_configured'] ? ORM::get_db('radius') : $panel;
    $radius->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $out['radius_radacct'] = arivo_preflight_table($radius, 'radacct');
    $out['nas_configured'] = arivo_preflight_table($radius, 'nas') ?
        (int)$radius->query('SELECT COUNT(*) FROM nas')->fetchColumn() : null;
    $columns = ['username', 'acctuniqueid', 'acctstarttime',
        'acctupdatetime', 'acctstoptime', 'acctinputoctets',
        'acctoutputoctets'];
    $out['schema_compatible'] = $out['radius_radacct'];
    foreach ($columns as $column) {
        $out['schema_compatible'] = $out['schema_compatible'] &&
            arivo_preflight_column($radius, 'radacct', $column);
    }
    if ($out['schema_compatible']) {
        $out['recent_sessions'] = (int)$radius->query(
            'SELECT COUNT(*) FROM radacct WHERE acctstarttime >= NOW() - INTERVAL 7 DAY'
        )->fetchColumn();
        $out['active_sessions'] = (int)$radius->query(
            'SELECT COUNT(*) FROM radacct WHERE acctstoptime IS NULL'
        )->fetchColumn();
    }
} catch (Throwable $error) {
    $out['error_type'] = get_class($error); // Never print DB credentials or SQL.
}
echo json_encode($out, JSON_PRETTY_PRINT | JSON_INVALID_UTF8_SUBSTITUTE), PHP_EOL;
