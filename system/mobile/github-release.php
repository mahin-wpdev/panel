<?php
/** GitHub release connector for the mobile app; public endpoint performs read-only work. */
const JMAPP_DEFAULT_GITHUB_REPO = 'mahin-wpdev/jm-broadband-android';
const JMAPP_DEFAULT_APK_NAME = 'jm-broadband.apk';

function jmapp_release_config(): array {
    $config = [
        'repo' => JMAPP_DEFAULT_GITHUB_REPO,
        'channel' => 'stable',
        'apk_name' => JMAPP_DEFAULT_APK_NAME,
        'required_update_default' => false,
    ];
    $file = dirname(__DIR__) . '/secure/mobile-release.json';
    if (is_readable($file)) {
        $saved = json_decode((string) file_get_contents($file), true);
        if (is_array($saved)) $config = array_merge($config, $saved);
    }
    if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', (string) $config['repo'])) {
        $config['repo'] = JMAPP_DEFAULT_GITHUB_REPO;
    }
    if (!in_array($config['channel'], ['stable', 'beta'], true)) $config['channel'] = 'stable';
    if (!preg_match('/^[A-Za-z0-9_.-]+\.apk$/', (string) $config['apk_name'])) {
        $config['apk_name'] = JMAPP_DEFAULT_APK_NAME;
    }
    return $config;
}

function jmapp_link(): string {
    if (!defined('APP_URL')) return '';
    $base = rtrim(APP_URL, '/');
    if (parse_url($base, PHP_URL_HOST) === '27.147.201.165' &&
        ($_SERVER['HTTP_HOST'] ?? '') === '27.147.201.165:8443') {
        return 'https://27.147.201.165:8443/panel/mobile-app-download.php';
    }
    return $base . '/mobile-app-download.php';
}

function jmapp_contextualize_release(array $release): array {
    $release['download_url'] = jmapp_link();
    return $release;
}

function jmapp_replace_placeholder(string $text): string {
    if (strpos($text, '[[app_download_link]]') === false) return $text;
    $link = defined('APP_URL') && parse_url(APP_URL, PHP_URL_HOST) === '27.147.201.165'
        ? 'https://27.147.201.165:8443/panel/mobile-app-download.php'
        : jmapp_link();
    return str_replace('[[app_download_link]]', $link, $text);
}

/** No release is a normal 404; all other invalid/unavailable data fail closed. */
function jmapp_parse_github_release(array $data): array {
    $cfg = jmapp_release_config();
    if (empty($data['published_at']) || !empty($data['draft']) ||
        ($cfg['channel'] === 'stable' && !empty($data['prerelease'])) ||
        !preg_match('/^v([0-9]+\.[0-9]+\.[0-9]+)\+([1-9][0-9]*)$/', (string) ($data['tag_name'] ?? ''), $tag)) {
        throw new UnexpectedValueException('Invalid public release tag.');
    }
    $body = (string) ($data['body'] ?? '');
    if (!preg_match('/^<!-- jm-app-update:required=(true|false) -->\r?\n/', $body, $flag)) {
        throw new UnexpectedValueException('Release update policy is missing.');
    }
    $asset = null;
    foreach ($data['assets'] ?? [] as $item) {
        if (($item['name'] ?? '') === $cfg['apk_name'] && ($item['state'] ?? '') === 'uploaded') {
            $asset = $item;
            break;
        }
    }
    if (!$asset) throw new UnexpectedValueException('APK asset is missing.');
    $bytes = (int) ($asset['size'] ?? 0);
    $digest = (string) ($asset['digest'] ?? '');
    $url = (string) ($asset['browser_download_url'] ?? '');
    $repoPattern = preg_quote($cfg['repo'], '~');
    $apkPattern = preg_quote($cfg['apk_name'], '~');
    if ($bytes < 10000 || $bytes > 250 * 1024 * 1024 ||
        !preg_match('/^sha256:([a-f0-9]{64})$/', $digest, $hash) ||
        !preg_match('~^https://github\.com/' . $repoPattern . '/releases/download/[^/]+/' . $apkPattern . '$~', $url)) {
        throw new UnexpectedValueException('APK digest or download URL is invalid.');
    }
    return [
        'version' => $tag[1],
        'build_number' => (int) $tag[2],
        'required_update' => $flag[1] === 'true',
        'download_url' => jmapp_link(),
        'sha256' => $hash[1],
        'bytes' => $bytes,
        'notes' => trim(substr($body, strlen($flag[0]))),
        'published_at' => (string) $data['published_at'],
        'github_url' => $url,
        'channel' => $cfg['channel'],
    ];
}

/** Cache validated public release snapshots for 15 minutes; tolerate a one-day CDN outage. */
function jmapp_latest_release(): array {
    $cfg = jmapp_release_config();
    $snapshot = $cfg['channel'] === 'beta' ? 'mobile-release-beta.json' : 'mobile-release.json';
    $raw = 'https://raw.githubusercontent.com/' . $cfg['repo'] . '/main/' . $snapshot;
    $cacheKey = hash('sha256', $cfg['repo'] . '|' . $cfg['channel'] . '|' . $cfg['apk_name']);
    $cache = sys_get_temp_dir() . '/jmapp-public-github-release-' . substr($cacheKey, 0, 16) . '.json';
    $saved = null;
    $age = PHP_INT_MAX;
    if (is_file($cache)) {
        $age = max(0, time() - (int) @filemtime($cache));
        $candidate = json_decode((string) @file_get_contents($cache), true);
        if (is_array($candidate) && preg_match('/^[a-f0-9]{64}$/', (string) ($candidate['sha256'] ?? ''))) {
            $saved = $candidate;
            if ($age < 900) return jmapp_contextualize_release($saved);
        }
    }
    if (!function_exists('curl_init')) {
        if ($saved !== null && $age < 86400) return jmapp_contextualize_release($saved);
        throw new RuntimeException('PHP cURL required.');
    }
    $curl = curl_init($raw);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: JM-Broadband-Mobile-Update'],
    ]);
    try {
        $json = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    } finally {
        curl_close($curl);
    }
    if ($status === 404) throw new OutOfBoundsException('No public release manifest.');
    if ($json === false || $status !== 200 || strlen($json) > 1048576) {
        if ($saved !== null && $age < 86400) return jmapp_contextualize_release($saved);
        throw new RuntimeException('GitHub release manifest unavailable.');
    }
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new UnexpectedValueException('GitHub JSON invalid.');
    $release = jmapp_parse_github_release($data);
    @file_put_contents($cache . '.tmp', json_encode($release), LOCK_EX);
    @rename($cache . '.tmp', $cache);
    return jmapp_contextualize_release($release);
}
