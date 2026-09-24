<?php
/** GitHub-only JM Broadband mobile release connector; no billing or DB writes. */
const JMAPP_GITHUB_REPO = 'mahin-wpdev/jm-broadband-android';
const JMAPP_APK_NAME = 'jm-broadband.apk';
// A release snapshot committed by our signed GitHub Actions workflow avoids
// the shared-IP GitHub API quota without placing a token on the panel server.
const JMAPP_GITHUB_RAW = 'https://raw.githubusercontent.com/mahin-wpdev/jm-broadband-android/main/mobile-release.json';

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

/** Cache validated public GitHub release snapshots and tolerate brief CDN outages. */
function jmapp_latest_release(): array {
    $cache = sys_get_temp_dir() . '/jmapp-public-github-release-v2.json';
    $saved = null;
    $age = PHP_INT_MAX;
    if (is_file($cache)) {
        $age = max(0, time() - (int) @filemtime($cache));
        $candidate = json_decode((string) @file_get_contents($cache), true);
        if (is_array($candidate) && preg_match('/^[a-f0-9]{64}$/', (string) ($candidate['sha256'] ?? ''))) {
            $saved = $candidate;
            if ($age < 600) return $saved;
        }
    }
    if (!function_exists('curl_init')) {
        if ($saved !== null && $age < 86400) return $saved;
        throw new RuntimeException('PHP cURL required.');
    }
    $curl = curl_init(JMAPP_GITHUB_RAW);
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_MAXREDIRS => 0,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'User-Agent: JM-Broadband-Mobile-Update',
        ],
    ]);
    try {
        $json = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
    } finally {
        curl_close($curl);
    }
    if ($status === 404) throw new OutOfBoundsException('No public release manifest.');
    if ($json === false || $status !== 200 || strlen($json) > 1048576) {
        // Temporary GitHub/CDN errors cannot override a recently verified release.
        if ($saved !== null && $age < 86400) return $saved;
        throw new RuntimeException('GitHub release manifest unavailable.');
    }
    $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($data)) throw new UnexpectedValueException('GitHub JSON invalid.');
    $release = jmapp_parse_github_release($data);
    @file_put_contents($cache . '.tmp', json_encode($release), LOCK_EX);
    @rename($cache . '.tmp', $cache);
    return $release;
}
