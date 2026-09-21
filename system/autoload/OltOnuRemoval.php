<?php
declare(strict_types=1);
/**
 * Single-offline-ONU removal for the verified V-SOL EPON Telnet CLI.
 * Never invokes a bulk operation, alters RADIUS/billing, or deletes history.
 */
final class OltOnuRemoval
{
    private const PROMPT = 'epon#';

    private static function readUntil($socket, string $prompt, int $seconds = 12): string
    {
        $output = '';
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline && strlen($output) < 131072) {
            $part = fread($socket, 8192);
            if ($part === false) throw new RuntimeException('OLT read failed');
            $output .= $part;
            if (strpos($output, $prompt) !== false) return $output;
            usleep(100000);
        }
        throw new RuntimeException('OLT response timed out; verify device manually');
    }

    private static function send($socket, string $command, string $prompt, int $seconds = 12): string
    {
        if (fwrite($socket, $command . "\r\n") === false) {
            throw new RuntimeException('OLT command write failed');
        }
        return self::readUntil($socket, $prompt, $seconds);
    }

    private static function rows(string $output): array
    {
        if (stripos($output, self::PROMPT) === false ||
            preg_match('/(?:bad command|unknown command|invalid command)/i', $output)) {
            throw new RuntimeException('OLT inventory response cannot be verified');
        }
        preg_match_all('/^\s*(\d+)\s+(\d+)\s+([0-9a-f-]{17})\s+(\S+)/mi',
            $output, $matches, PREG_SET_ORDER);
        if (!$matches) throw new RuntimeException('OLT inventory is empty or unreadable');
        $rows = [];
        foreach ($matches as $r) {
            $rows[] = ['port' => (int)$r[1], 'id' => (int)$r[2],
                'mac' => strtoupper(str_replace('-', ':', $r[3])),
                'status' => strtolower($r[4])];
        }
        return $rows;
    }

    private static function match(array $rows, int $port, int $id, string $mac): array
    {
        $matches = array_values(array_filter($rows, static function (array $row) use ($port, $id) {
            return $row['port'] === $port && $row['id'] === $id;
        }));
        if (count($matches) !== 1 || !hash_equals($mac, $matches[0]['mac'])) {
            throw new RuntimeException('ONU location/MAC changed or is absent on OLT');
        }
        return $matches[0];
    }
    /**
     * Fail closed on everything except one unassigned, currently offline ONU.
     * Caller must hold the shared OLT sync lock for the entire operation.
     */
    public static function removeOffline($olt, $onu): void
    {
        if (strtolower((string)$olt['vendor']) !== 'vsol' ||
            strtolower((string)$olt['protocol']) !== 'telnet' ||
            !OltManager::validHost((string)$olt['management_host'])) {
            throw new RuntimeException('Unsupported OLT adapter');
        }
        $portString = (string)$onu['pon_port'];
        $idString = (string)$onu['onu_id'];
        $mac = strtoupper(str_replace('-', ':', (string)$onu['mac_address']));
        if (!preg_match('/^[1-8]$/', $portString) ||
            !preg_match('/^(?:[1-9]|[1-5][0-9]|6[0-4])$/', $idString) ||
            !preg_match('/^(?:[0-9A-F]{2}:){5}[0-9A-F]{2}$/', $mac) ||
            (string)$onu['status'] !== 'OFFLINE' ||
            !empty($onu['customer_id']) ||
            (int)$onu['olt_id'] !== (int)$olt['id']) {
            throw new RuntimeException('Only an unassigned offline ONU with valid identity can be removed');
        }
        $port = (int)$portString;
        $id = (int)$idString;
        $host = (string)$olt['management_host'];
        $socket = @fsockopen($host, (int)$olt['port'], $errno, $error, 8);
        if (!$socket) throw new RuntimeException('OLT connection failed');
        stream_set_timeout($socket, 1);
        try {
            self::readUntil($socket, 'Username:', 8);
            self::send($socket, (string)$olt['username'], 'Password:', 8);
            self::send($socket, OltManager::decrypt($olt['encrypted_password']), self::PROMPT, 10);

            $before = self::rows(self::send($socket, 'show olt 1 all-onu-info', self::PROMPT));
            $target = self::match($before, $port, $id, $mac);
            if (!in_array($target['status'], ['offline', 'powerdown'], true)) {
                throw new RuntimeException('ONU is not offline on the live OLT');
            }

            $modePrompt = 'epon(olt-' . $port . ')#';
            self::send($socket, 'olt ' . $port, $modePrompt, 8);
            // Verified on this OLT CLI: offline-onu del <single numeric ONU ID>.
            $response = self::send($socket, 'offline-onu del ' . $id, $modePrompt, 12);
            if (preg_match('/(?:error|invalid|failed|bad command|not exist|online)/i', $response)) {
                throw new RuntimeException('OLT rejected the single-ONU removal');
            }
            self::send($socket, 'exit', self::PROMPT, 8);
            $after = self::rows(self::send($socket, 'show olt 1 all-onu-info', self::PROMPT));
            foreach ($after as $entry) {
                if ($entry['port'] === $port && $entry['id'] === $id) {
                    throw new RuntimeException('ONU still registered; OLT removal not confirmed');
                }
                if (hash_equals($mac, $entry['mac'])) {
                    throw new RuntimeException('ONU reappeared after removal');
                }
            }
        } finally {
            fclose($socket);
        }
    }
}
