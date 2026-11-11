<?php
declare(strict_types=1);

namespace IPScope;

/**
 * Short-lived device identity cache keyed by both IPv4 and MAC.
 * Binding the name to the MAC prevents a DHCP-reused IP from inheriting
 * the previous device's hostname.
 */
final class LanDeviceIdentityCache
{
    private string $path;
    private int $ttlSeconds;
    private \Closure $clock;
    private int $maxEntries;

    public function __construct(string $path, int $ttlSeconds = 21600, ?callable $clock = null, int $maxEntries = 2048)
    {
        if ($ttlSeconds < 60 || $ttlSeconds > 604800) {
            throw new \InvalidArgumentException('Identity cache TTL must be between 60 seconds and 7 days.');
        }
        if ($maxEntries < 1 || $maxEntries > 10000) {
            throw new \InvalidArgumentException('Identity cache size must be between 1 and 10000 entries.');
        }
        $this->path = $path;
        $this->ttlSeconds = $ttlSeconds;
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn(): int => time();
        $this->maxEntries = $maxEntries;
    }

    /** @return array{name:string,name_source:string,cached:true}|null */
    public function get(string $ip, string $mac): ?array
    {
        if (!$this->validIdentity($ip, $mac)) return null;
        $data = $this->read();
        $key = $this->key($ip, $mac);
        $entry = $data['entries'][$key] ?? null;
        if (!is_array($entry)) return null;
        $updatedAt = (int)($entry['updated_at'] ?? 0);
        if ($updatedAt <= 0 || ($this->now() - $updatedAt) > $this->ttlSeconds) return null;
        if (($entry['ip'] ?? '') !== $ip || strtolower((string)($entry['mac'] ?? '')) !== strtolower($mac)) return null;
        $name = $this->cleanName((string)($entry['name'] ?? ''));
        $source = (string)($entry['name_source'] ?? '');
        if ($name === null || !in_array($source, ['reverse_dns','mdns','netbios'], true)) return null;
        return ['name'=>$name, 'name_source'=>$source, 'cached'=>true];
    }

    public function put(string $ip, string $mac, ?string $name, ?string $nameSource): void
    {
        if (!$this->validIdentity($ip, $mac) || $name === null || $nameSource === null) return;
        $name = $this->cleanName($name);
        if ($name === null || !in_array($nameSource, ['reverse_dns','mdns','netbios'], true)) return;

        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create device identity cache directory.');
        }
        $lock = fopen($this->path . '.lock', 'c');
        if ($lock === false) throw new \RuntimeException('Unable to open device identity cache lock.');
        try {
            if (!flock($lock, LOCK_EX)) throw new \RuntimeException('Unable to lock device identity cache.');
            $data = $this->read();
            $entries = is_array($data['entries'] ?? null) ? $data['entries'] : [];
            $now = $this->now();
            foreach ($entries as $key => $entry) {
                if (!is_array($entry) || ($now - (int)($entry['updated_at'] ?? 0)) > $this->ttlSeconds) {
                    unset($entries[$key]);
                }
            }
            $entries[$this->key($ip, $mac)] = [
                'ip'=>$ip,
                'mac'=>strtolower($mac),
                'name'=>$name,
                'name_source'=>$nameSource,
                'updated_at'=>$now,
            ];
            if (count($entries) > $this->maxEntries) {
                uasort($entries, static fn(array $a, array $b): int => ((int)($a['updated_at'] ?? 0)) <=> ((int)($b['updated_at'] ?? 0)));
                $entries = array_slice($entries, -$this->maxEntries, null, true);
            }
            $payload = ['schema_version'=>1, 'entries'=>$entries];
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $tmp = $this->path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
            if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
                @unlink($tmp);
                throw new \RuntimeException('Unable to write device identity cache.');
            }
            @chmod($tmp, 0640);
            if (!rename($tmp, $this->path)) {
                @unlink($tmp);
                throw new \RuntimeException('Unable to publish device identity cache.');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function read(): array
    {
        if (!is_file($this->path) || !is_readable($this->path)) return ['entries'=>[]];
        $raw = file_get_contents($this->path);
        if ($raw === false || trim($raw) === '') return ['entries'=>[]];
        $data = json_decode($raw, true);
        if (!is_array($data) || (int)($data['schema_version'] ?? 0) !== 1 || !is_array($data['entries'] ?? null)) {
            return ['entries'=>[]];
        }
        return $data;
    }

    private function validIdentity(string $ip, string $mac): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && preg_match('/^[0-9a-fA-F]{2}(?::[0-9a-fA-F]{2}){5}$/', $mac) === 1;
    }

    private function key(string $ip, string $mac): string
    {
        return hash('sha256', $ip . '|' . strtolower($mac));
    }

    private function cleanName(string $name): ?string
    {
        $name = trim($name, " .\t\r\n");
        if ($name === '' || filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) return null;
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,62}$/', $name) !== 1) return null;
        return $name;
    }

    private function now(): int
    {
        return (int)($this->clock)();
    }
}
