<?php
/** Pure contract tests: no GitHub calls, database or SMS side effects. */
define('APP_URL', 'https://isp.example.com/panel');
require dirname(__DIR__, 2) . '/system/mobile/github-release.php';

$assetUrl = 'https://github.com/mahin-wpdev/jm-broadband-android/releases/download/v1.0.8%2B9/jm-broadband.apk';
$fixture = [
    'tag_name' => 'v1.0.8+9',
    'published_at' => '2026-09-24T00:00:00Z',
    'draft' => false, 'prerelease' => false,
    'body' => "<!-- jm-app-update:required=true -->\nBug fixes and improvements.",
    'assets' => [[
        'name' => 'jm-broadband.apk', 'state' => 'uploaded',
        'size' => 51715568,
        'digest' => 'sha256:' . str_repeat('a', 64),
        'browser_download_url' => $assetUrl,
    ]],
];
$release = jmapp_parse_github_release($fixture);
if ($release['version'] !== '1.0.8' || $release['build_number'] !== 9 ||
    !$release['required_update'] || $release['sha256'] !== str_repeat('a', 64) ||
    $release['github_url'] !== $assetUrl ||
    $release['notes'] !== 'Bug fixes and improvements.') {
    throw new RuntimeException('GitHub release parsing failed.');
}
if (jmapp_link() !== 'https://isp.example.com/panel/mobile-app-download.php' ||
    jmapp_replace_placeholder('Install: [[app_download_link]]') !==
    'Install: https://isp.example.com/panel/mobile-app-download.php' ||
    jmapp_replace_placeholder('Hello') !== 'Hello') {
    throw new RuntimeException('Stable download placeholder failed.');
}
$fixture['body'] = "<!-- jm-app-update:required=false -->\nOptional update.";
if (jmapp_parse_github_release($fixture)['required_update']) {
    throw new RuntimeException('Optional update policy failed.');
}
$fixture['assets'][0]['digest'] = 'sha256:wrong';
try { jmapp_parse_github_release($fixture); throw new RuntimeException('Invalid digest accepted.'); }
catch (UnexpectedValueException $expected) {}
$fixture['assets'][0]['digest'] = 'sha256:' . str_repeat('a', 64);
$fixture['body'] = 'No update policy';
try { jmapp_parse_github_release($fixture); throw new RuntimeException('Missing update policy accepted.'); }
catch (UnexpectedValueException $expected) {}
echo "GitHub release, required/optional, digest and link tests passed.\n";
