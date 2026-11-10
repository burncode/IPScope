<?php
declare(strict_types=1);

namespace IPScope;

/**
 * Stores user-supplied DHCP knowledge without contacting or modifying routers.
 * Modes: unknown, disabled, configured.
 */
final class DhcpConfigStore
{
    private string $path;

    public function __construct(string $path) { $this->path = $path; }

    /** @return array<string,mixed> */
    public function load(): array
    {
        if (!is_file($this->path)) return $this->emptyConfig();
        if (!is_readable($this->path)) throw new \\RuntimeException('DHCP configuration file is not readable.');
        $raw = file_get_contents($this->path);
        if ($raw === false || trim($raw) === '') throw new \\RuntimeException('DHCP configuration file is empty or unreadable.');
        try { $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
        catch (\\JsonException $error) { throw new \\RuntimeException('DHCP configuration contains invalid JSON.', 0, $error); }
        if (!is_array($data) || (int)($data['schema_version'] ?? 0) !== 1) {
            throw new \\RuntimeException('DHCP configuration has an unsupported schema.');
        }
        return $this->normalize($data);
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    public function save(array $config): array
    {
        $normalized = $this->normalize($config);
        $dir = dirname($this->path);
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) throw new \\RuntimeException('Unable to create DHCP configuration directory.');
        $json = json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $tmp = $this->path . '.tmp.' . getmypid();
        if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) { @unlink($tmp); throw new \\RuntimeException('Unable to write DHCP configuration.'); }
        @chmod($tmp, 0640);
        if (!rename($tmp, $this->path)) { @unlink($tmp); throw new \\RuntimeException('Unable to publish DHCP configuration.'); }
        return $normalized;
    }

    /** @return array<string,mixed> */
    private function emptyConfig(): array
    {
        return ['schema_version'=>1, 'mode'=>'unknown', 'pools'=>[], 'reservations'=>[], 'updated_at'=>null];
    }

    /** @param array<string,mixed> $config @return array<string,mixed> */
    private function normalize(array $config): array
    {
        $mode = (string)($config['mode'] ?? 'unknown');
        if (!in_array($mode, ['unknown','disabled','configured'], true)) throw new \\InvalidArgumentException('DHCP mode must be unknown, disabled, or configured.');
        $pools = $config['pools'] ?? [];
        $reservations = $config['reservations'] ?? [];
        if (!is_array($pools) || !is_array($reservations)) throw new \\InvalidArgumentException('DHCP pools and reservations must be arrays.');
        if ($mode !== 'configured' && ($pools || $reservations)) throw new \\InvalidArgumentException('DHCP pools and reservations require configured mode.');
        $calculator = new IPv4Calculator();
        $normalizedPools = [];
        foreach ($pools as $i=>$pool) {
            if (!is_array($pool)) throw new \\InvalidArgumentException('DHCP pool #' . ($i+1) . ' must be an object.');
            $network = trim((string)($pool['network'] ?? '*'));
            $subnet = trim((string)($pool['subnet'] ?? ''));
            $start = trim((string)($pool['start_ip'] ?? ''));
            $end = trim((string)($pool['end_ip'] ?? ''));
            if ($network === '' || strlen($network) > 128) throw new \\InvalidArgumentException('DHCP pool network is required.');
            try { $range = $calculator->range($subnet); } catch (\\InvalidArgumentException $e) { throw new \\InvalidArgumentException('DHCP pool subnet must be valid IPv4 CIDR.', 0, $e); }
            foreach ([$start,$end] as $ip) if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) throw new \\InvalidArgumentException('DHCP pool boundaries must be valid IPv4 addresses.');
            $first = (int)sprintf('%u', ip2long($range['first_host'] ?? '0.0.0.0'));
            $last = (int)sprintf('%u', ip2long($range['last_host'] ?? '0.0.0.0'));
            $a = (int)sprintf('%u', ip2long($start)); $b = (int)sprintf('%u', ip2long($end));
            if ($a > $b || $a < $first || $b > $last) throw new \\InvalidArgumentException('DHCP pool range must be ordered and inside its subnet host range.');
            $normalizedPools[] = ['id'=>trim((string)($pool['id'] ?? ('pool-' . ($i+1)))), 'network'=>$network, 'subnet'=>$subnet, 'start_ip'=>$start, 'end_ip'=>$end, 'source'=>(string)($pool['source'] ?? 'manual')];
        }
        $normalizedReservations = []; $seen = [];
        foreach ($reservations as $i=>$reservation) {
            if (!is_array($reservation)) throw new \\InvalidArgumentException('DHCP reservation #' . ($i+1) . ' must be an object.');
            $ip = trim((string)($reservation['ip'] ?? ''));
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) throw new \\InvalidArgumentException('DHCP reservation IP must be valid IPv4.');
            $key = (string)($reservation['network'] ?? '*') . '|' . $ip;
            if (isset($seen[$key])) throw new \\InvalidArgumentException('DHCP reservation IPs must be unique per network.');
            $seen[$key] = true;
            $normalizedReservations[] = ['network'=>(string)($reservation['network'] ?? '*'), 'ip'=>$ip, 'label'=>substr(trim((string)($reservation['label'] ?? '')),0,120)];
        }
        return ['schema_version'=>1,'mode'=>$mode,'pools'=>$normalizedPools,'reservations'=>$normalizedReservations,'updated_at'=>(string)($config['updated_at'] ?? date(DATE_ATOM))];
    }
}
