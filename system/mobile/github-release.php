<?php
/** GitHub-only JM Broadband mobile release connector; no billing or DB writes. */
const JMAPP_GITHUB_REPO = 'mahin-wpdev/jm-broadband-android';
const JMAPP_APK_NAME = 'jm-broadband.apk';
const JMAPP_GITHUB_API = 'https://api.github.com/repos/' . JMAPP_GITHUB_REPO . '/releases/latest';

function jmapp_link(): string {
    return defined('APP_URL')
        ? rtrim(APP_URL, '/') . '/mobile-app-download.php' : '';
}

function jmapp_replace_placeholder(string $text): string {
    if (strpos($text, '[[app_download_link]]') === false) return $text;
    return str_replace('[[app_download_link]]', jmapp_link(), $text);
}

/** No release is a normal 404; all other invalid/unavailable data fail closed. */
function jmapp_parse_github_release(array $data): array {
    if (empty($data['published_at']) || !empty($data['draft']) ||
        !empty($data['prerelease']) || !preg_match(
            '/^v([0-9]+\.[0-9]+\.[0-9]+)\+([1-9][0-9]*)$/',
            (string) ($data['tag_name'] ?? ''), $tag)) {
        throw new UnexpectedValueException('Invalid public release tag.');
    }
    $body = (string) ($data['body'] ?? '');
    if (!preg_match('/^<!-- jm-app-update:required=(true|false) -->\r?\n/', $body, $flag)) {
        throw new UnexpectedValueException('Release update policy is missing.');
    }
    $asset = null;
    foreach ($data['assets'] ?? [] as $item) {
        if (($item['name'] ?? '') === JMAPP_APK_NAME &&
            ($item['state'] ?? '') === 'uploaded') { $asset = $item; break; }
    }
    if (!$asset) throw new UnexpectedValueException('APK asset is missing.');
    $bytes = (int) ($asset['size'] ?? 0);
    $digest = (string) ($asset['digest'] ?? '');
    $url = (string) ($asset['browser_download_url'] ?? '');
    if ($bytes < 10000 || $bytes > 250 * 1024 * 1024 ||
        !preg_match('/^sha256:([a-f0-9]{64})$/', $digest, $hash) ||
        !preg_match('~^https://github\.com/mahin-wpdev/jm-broadband-android/releases/download/[^/]+/jm-broadband\.apk$~', $url)) {
        throw new UnexpectedValueException('APK digest or download URL is invalid.');
    }
    return [
        'version' => $tag[1], 'build_number' => (int) $tag[2],
        'required_update' => $flag[1] === 'true',
        'download_url' => jmapp_link(),
        'sha256' => $hash[1], 'bytes' => $bytes,
        'notes' => trim(substr($body, strlen($flag[0]))),
        'published_at' => (string) $data['published_at'],
        'github_url' => $url,
    ];
}

/** Cache successful GitHub reads briefly to stay below unauthenticated API limits. */
function jmapp_latest_release(): array {
    $cache = sys_get_temp_dir() . '/jmapp-public-github-release-v1.json';
    if (is_file($cache) && time() - filemtime($cache) < 90) {
        $saved = json_decode((string) @file_get_contents($cache), true);
        if (is_array($saved) && !empty($saved['sha256'])) return $saved;
    }
    if (!function_exists('curl_init')) throw new RuntimeException('PHP cURL required.');
    $curl = curl_init(JMAPP_GITHUB_API);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'User-Agent: JM-Broadband-Mobile-Update',
            'X-GitHub-Api-Version: 2022-11-28',
        ],
    ]);
    try {
        $json = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        if ($json === false) throw new RuntimeException('GitHub connection error.');
    } finally {
        curl_close($curl);
    }
    if ($status === 404) throw new OutOfBoundsException('No public release.');
    if ($status !== 200 || strlen($json) > 1048576) {
        throw new RuntimeException('GitHub API unavailable.');
    }
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new UnexpectedValueException('GitHub JSON invalid.');
    $release = jmapp_parse_github_release($data);
    @file_put_contents($cache . '.tmp', json_encode($release), LOCK_EX);
    @rename($cache . '.tmp', $cache);
    return $release;
}
