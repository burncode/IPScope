<?php
declare(strict_types=1);

namespace IPScope;

/**
 * Best-effort IPv4 device-name lookup using the host's configured name services.
 * A missing name is normal and never changes the ARP probe classification.
 */
final class LanDeviceNameResolver
{
    private ProcessRunner $runner;
    private string $getentBinary;
    private string $avahiBinary;
    private string $nmblookupBinary;

    public function __construct(
        ProcessRunner $runner,
        string $getentBinary = '/usr/bin/getent',
        string $avahiBinary = '/usr/bin/avahi-resolve-address',
        string $nmblookupBinary = '/usr/bin/nmblookup'
    ) {
        $this->runner = $runner;
        $this->getentBinary = $getentBinary;
        $this->avahiBinary = $avahiBinary;
        $this->nmblookupBinary = $nmblookupBinary;
    }

    /** @return array{name:?string,name_source:?string} */
    public function resolve(string $ip): array
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new \InvalidArgumentException('Device-name lookup requires a valid IPv4 address.');
        }

        $name = $this->resolveGetent($ip);
        if ($name !== null) return ['name'=>$name, 'name_source'=>'reverse_dns'];

        if (is_executable($this->avahiBinary)) {
            $name = $this->resolveAvahi($ip);
            if ($name !== null) return ['name'=>$name, 'name_source'=>'mdns'];
        }

        if (is_executable($this->nmblookupBinary)) {
            $name = $this->resolveNetbios($ip);
            if ($name !== null) return ['name'=>$name, 'name_source'=>'netbios'];
        }

        return ['name'=>null, 'name_source'=>null];
    }

    private function resolveGetent(string $ip): ?string
    {
        if (!is_executable($this->getentBinary)) return null;
        try {
            $result = $this->runner->run([$this->getentBinary, 'hosts', $ip], 1);
        } catch (\Throwable $error) {
            return null;
        }
        if (($result['exit_code'] ?? 1) !== 0) return null;
        $parts = preg_split('/\\s+/', trim((string)($result['stdout'] ?? '')));
        if (!is_array($parts) || count($parts) < 2 || $parts[0] !== $ip) return null;
        return $this->cleanName($parts[1]);
    }

    private function resolveAvahi(string $ip): ?string
    {
        try {
            $result = $this->runner->run([$this->avahiBinary, '-4', $ip], 1);
        } catch (\Throwable $error) {
            return null;
        }
        if (($result['exit_code'] ?? 1) !== 0) return null;
        $parts = preg_split('/\\s+/', trim((string)($result['stdout'] ?? '')));
        if (!is_array($parts) || count($parts) < 2 || $parts[0] !== $ip) return null;
        return $this->cleanName($parts[1]);
    }

    private function resolveNetbios(string $ip): ?string
    {
        try {
            $result = $this->runner->run([$this->nmblookupBinary, '-A', $ip], 1);
        } catch (\Throwable $error) {
            return null;
        }
        if (($result['exit_code'] ?? 1) !== 0) return null;
        $output = (string)($result['stdout'] ?? '');
        if (preg_match('/^\\s*([A-Za-z0-9][A-Za-z0-9._-]{0,62})\\s+<00>\\s+[-+]/m', $output, $match) !== 1) {
            return null;
        }
        return $this->cleanName($match[1]);
    }

    private function cleanName(string $name): ?string
    {
        $name = trim($name, " .\\t\\r\\n");
        if ($name === '' || filter_var($name, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) return null;
        if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,62}$/', $name)) return null;
        return $name;
    }
}
