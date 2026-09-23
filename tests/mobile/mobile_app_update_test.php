<?php
/** Isolated test; never touches production database or sends messages. */
define('APP_URL', 'https://isp.example.com/panel');
function register_menu(...$args): void {}
class ORM {
    public static function for_table(string $table): self {
        if ($table !== 'tbl_appconfig') throw new RuntimeException('Incorrect table');
        return new self();
    }
    public function where(string $key, string $value): self {
        if ($key !== 'setting' || $value !== 'jm_mobile_release')
            throw new RuntimeException('Incorrect setting');
        return $this;
    }
    public function find_one(): array {
        return ['value' => json_encode(['filename' => 'release.apk',
            'version' => '1.0.8', 'build_number' => 9])];
    }
}
require dirname(__DIR__, 2) . '/system/plugin/mobileApp.php';
$expected = 'https://isp.example.com/panel/mobile-app-download.php';
if (jmapp_link() !== $expected) throw new RuntimeException('Link mismatch');
if (jmapp_replace_placeholder('Install: [[app_download_link]]') !== 'Install: ' . $expected)
    throw new RuntimeException('Placeholder not substituted');
if (jmapp_replace_placeholder('Hello') !== 'Hello')
    throw new RuntimeException('Unrelated text was changed');
if (jmapp_release()['build_number'] !== 9) throw new RuntimeException('Wrong metadata');
echo "Mobile release + placeholder tests passed.\n";