<?php

use PEAR2\Net\RouterOS;

class MikrotikProvisioner
{
    private const MANAGED = 'JM-PANEL-MANAGED';

    public static function normalize(array $input): array
    {
        $mode = strtolower(trim((string)($input['mode'] ?? 'hybrid')));
        if (!in_array($mode, ['pppoe', 'hotspot', 'hybrid'], true)) {
            throw new InvalidArgumentException('Invalid MikroTik mode.');
        }
        $host = trim((string)($input['host'] ?? ''));
        if ($host === '' || preg_match('/[\s\r\n]/', $host)) {
            throw new InvalidArgumentException('MikroTik host is required.');
        }
        $apiPort = (int)($input['api_port'] ?? 8728);
        if ($apiPort < 1 || $apiPort > 65535) throw new InvalidArgumentException('Invalid API port.');
        $radiusServer = trim((string)($input['radius_server'] ?? ''));
        $panelServer = trim((string)($input['panel_server'] ?? $radiusServer));
        foreach (['RADIUS server' => $radiusServer, 'Panel server' => $panelServer] as $label => $value) {
            if (!filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                throw new InvalidArgumentException($label . ' must be a valid IPv4 address.');
            }
        }
        $wan = trim((string)($input['wan_interface'] ?? ''));
        $lan = trim((string)($input['lan_interface'] ?? ''));
        if ($wan === '' || $lan === '' || $wan === $lan) throw new InvalidArgumentException('WAN and LAN interfaces must be different.');
        $apiUser = trim((string)($input['panel_api_user'] ?? 'jm-panel-api'));
        if (!preg_match('/^[A-Za-z0-9_.@-]{3,40}$/', $apiUser)) throw new InvalidArgumentException('Invalid API username.');
        $profile = trim((string)($input['pppoe_profile'] ?? 'jm-panel-radius'));
        if (!preg_match('/^[A-Za-z0-9_. -]{1,60}$/', $profile)) throw new InvalidArgumentException('Invalid PPPoE profile name.');
        return [
            'host' => $host,
            'api_port' => $apiPort,
            'setup_user' => trim((string)($input['setup_user'] ?? 'admin')),
            'setup_password' => (string)($input['setup_password'] ?? ''),
            'mode' => $mode,
            'wan_interface' => $wan,
            'lan_interface' => $lan,
            'radius_server' => $radiusServer,
            'panel_server' => $panelServer,
            'panel_api_user' => $apiUser,
            'panel_api_password' => (string)($input['panel_api_password'] ?? bin2hex(random_bytes(18))),
            'radius_secret' => (string)($input['radius_secret'] ?? bin2hex(random_bytes(24))),
            'pppoe_profile' => $profile,
            'pppoe_pool' => trim((string)($input['pppoe_pool'] ?? '')),
            'interim_update' => trim((string)($input['interim_update'] ?? '5m')),
            'api_ssl' => !empty($input['api_ssl']),
        ];
    }

    private static function q(string $value): string
    {
        $value = str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $value);
        return '"' . $value . '"';
    }

    public static function rollbackScript(array $raw): string
    {
        $c = self::normalize($raw);
        $tag = self::MANAGED;
        $lines = [
            '# JM Broadband panel rollback',
            '/ip firewall filter remove [find comment=' . self::q($tag . '-API') . ']',
            '/ip firewall filter remove [find comment=' . self::q($tag . '-COA') . ']',
            '/radius remove [find comment=' . self::q($tag . '-RADIUS') . ']',
            '/user remove [find name=' . self::q($c['panel_api_user']) . ']',
            '/user group remove [find name=' . self::q('jm-panel') . ']',
        ];
        if (in_array($c['mode'], ['pppoe', 'hybrid'], true)) {
            $lines[] = '/ppp profile remove [find name=' . self::q($c['pppoe_profile']) . ' comment=' . self::q($tag . '-PPPOE') . ']';
        }
        return implode("\n", $lines) . "\n";
    }

    public static function buildScript(array $raw): string
    {
        $c = self::normalize($raw);
        $tag = self::MANAGED;
        $backup = 'jm-panel-before-' . gmdate('Ymd-His');
        $rollback = self::rollbackScript($c);
        $body = [];
        $body[] = '/user group remove [find name=' . self::q('jm-panel') . ']';
        $body[] = '/user group add name=' . self::q('jm-panel') . ' policy=read,write,test,api,!local,!telnet,!ssh,!ftp,!reboot,!policy,!winbox,!password,!web,!sniff,!sensitive,!romon';
        $body[] = '/user remove [find name=' . self::q($c['panel_api_user']) . ']';
        $body[] = '/user add name=' . self::q($c['panel_api_user']) . ' group=' . self::q('jm-panel') . ' password=' . self::q($c['panel_api_password']) . ' comment=' . self::q($tag);
        $service = $c['api_ssl'] ? 'api-ssl' : 'api';
        $body[] = '/ip service set [find name=' . self::q($service) . '] disabled=no port=' . $c['api_port'] . ' address=' . self::q($c['panel_server'] . '/32');
        $body[] = '/radius remove [find comment=' . self::q($tag . '-RADIUS') . ']';
        $services = $c['mode'] === 'pppoe' ? 'ppp' : ($c['mode'] === 'hotspot' ? 'hotspot' : 'ppp,hotspot');
        $body[] = '/radius add address=' . self::q($c['radius_server']) . ' secret=' . self::q($c['radius_secret']) . ' service=' . self::q($services) . ' authentication-port=1812 accounting-port=1813 timeout=3s comment=' . self::q($tag . '-RADIUS');
        $body[] = '/radius incoming set accept=yes port=3799';
        if (in_array($c['mode'], ['pppoe', 'hybrid'], true)) {
            $body[] = '/ppp aaa set use-radius=yes accounting=yes interim-update=' . self::q($c['interim_update']);
            if ($c['pppoe_pool'] !== '') {
                $body[] = '/ppp profile remove [find name=' . self::q($c['pppoe_profile']) . ' comment=' . self::q($tag . '-PPPOE') . ']';
                $body[] = '/ppp profile add name=' . self::q($c['pppoe_profile']) . ' remote-address=' . self::q($c['pppoe_pool']) . ' use-compression=no use-encryption=default comment=' . self::q($tag . '-PPPOE');
            }
        }
        if (in_array($c['mode'], ['hotspot', 'hybrid'], true)) {
            $body[] = '/ip hotspot profile set [find default=yes] use-radius=yes radius-accounting=yes radius-interim-update=' . self::q($c['interim_update']);
        }
        $body[] = '/ip firewall filter remove [find comment=' . self::q($tag . '-API') . ']';
        $body[] = '/ip firewall filter add chain=input action=accept protocol=tcp src-address=' . self::q($c['panel_server']) . ' dst-port=' . $c['api_port'] . ' place-before=0 comment=' . self::q($tag . '-API');
        $body[] = '/ip firewall filter remove [find comment=' . self::q($tag . '-COA') . ']';
        $body[] = '/ip firewall filter add chain=input action=accept protocol=udp src-address=' . self::q($c['radius_server']) . ' dst-port=3799 place-before=0 comment=' . self::q($tag . '-COA');
        $body[] = ':put ' . self::q('JM Panel configuration applied');

        return '# JM Broadband generated configuration - review before applying' . "\n"
            . '/system backup save name=' . self::q($backup) . "\n"
            . '/export file=' . self::q($backup) . "\n"
            . ':do {' . "\n  " . implode("\n  ", $body) . "\n"
            . '} on-error={\n  :log error ' . self::q('JM Panel onboarding failed; rolling back managed changes') . "\n  "
            . str_replace("\n", "\n  ", trim($rollback)) . "\n  :error " . self::q('JM Panel onboarding rolled back') . "\n}\n";
    }

    public static function probe(array $raw): array
    {
        $c = self::normalize($raw);
        if ($c['setup_user'] === '' || $c['setup_password'] === '') {
            throw new InvalidArgumentException('Setup username and password are required for API mode.');
        }
        $client = Mikrotik::getClient($c['host'] . ':' . $c['api_port'], $c['setup_user'], $c['setup_password']);
        $resource = $client->sendSync(new RouterOS\Request('/system/resource/print'));
        $identity = $client->sendSync(new RouterOS\Request('/system/identity/print'));
        $interfaces = [];
        $responses = $client->sendSync(new RouterOS\Request('/interface/print .proplist=name,type,running,disabled'));
        foreach ($responses as $response) {
            $name = $response->getProperty('name');
            if ($name) {
                $interfaces[] = [
                    'name' => $name,
                    'type' => $response->getProperty('type'),
                    'running' => $response->getProperty('running'),
                    'disabled' => $response->getProperty('disabled'),
                ];
            }
        }
        return [
            'identity' => $identity->getProperty('name'),
            'version' => $resource->getProperty('version'),
            'board' => $resource->getProperty('board-name'),
            'architecture' => $resource->getProperty('architecture-name'),
            'interfaces' => $interfaces,
        ];
    }

    private static function runScript(RouterOS\Client $client, string $name, string $source): void
    {
        try {
            $client->sendSync((new RouterOS\Request('/system/script/remove'))
                ->setQuery(RouterOS\Query::where('name', $name)));
        } catch (Throwable $ignored) {
        }
        $client->sendSync((new RouterOS\Request('/system/script/add'))
            ->setArgument('name', $name)
            ->setArgument('policy', 'read,write,test,api,policy,password,sensitive')
            ->setArgument('source', $source));
        try {
            $client->sendSync((new RouterOS\Request('/system/script/run'))->setArgument('number', $name));
        } finally {
            try {
                $client->sendSync((new RouterOS\Request('/system/script/remove'))
                    ->setQuery(RouterOS\Query::where('name', $name)));
            } catch (Throwable $ignored) {
            }
        }
    }

    public static function apply(array $raw): array
    {
        $c = self::normalize($raw);
        $probe = self::probe($c);
        $client = Mikrotik::getClient($c['host'] . ':' . $c['api_port'], $c['setup_user'], $c['setup_password']);
        self::runScript($client, 'jm-panel-onboarding', self::buildScript($c));
        return ['probe' => $probe, 'config' => $c];
    }
}
