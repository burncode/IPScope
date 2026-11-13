<?php
declare(strict_types=1);

namespace IPScope;

final class LanInterfaceCollector
{
    private ProcessRunner $runner;
    private string $ipBinary;

    public function __construct(ProcessRunner $runner, string $ipBinary = '/usr/sbin/ip')
    {
        $this->runner = $runner;
        $this->ipBinary = $ipBinary;
    }

    /** @return array{interfaces:array,default_routes:array,scan_targets:array} */
    public function collect(array $preferredInterfaces = ['bond0']): array
    {
        $addresses = $this->runner->run([$this->ipBinary, '-4', '-o', 'addr', 'show'], 10);
        if ($addresses['exit_code'] !== 0) {
            throw new RuntimeException('Unable to collect IPv4 interfaces: ' . trim($addresses['stderr']));
        }
        $links = $this->runner->run([$this->ipBinary, '-o', 'link', 'show'], 10);
        $linkState = [];
        foreach (preg_split('/\r?\n/', trim($links['stdout'])) ?: [] as $line) {
            if (preg_match('/^\d+:\s+([^:@]+)(?:@[^:]+)?:\s+<([^>]*)>/', $line, $m)) {
                $linkState[$m[1]] = in_array('UP', explode(',', $m[2]), true);
            }
        }

        $interfaces = [];
        foreach (preg_split('/\r?\n/', trim($addresses['stdout'])) ?: [] as $line) {
            if (!preg_match('/^\d+:\s+([^\s]+)\s+inet\s+(\d+\.\d+\.\d+\.\d+\/\d+)/', $line, $m)) {
                continue;
            }
            $name = explode('@', $m[1])[0];
            [$ip, $prefix] = explode('/', $m[2], 2);
            $interfaces[$name][] = [
                'name' => $name,
                'ipv4' => $ip,
                'prefix' => (int)$prefix,
                'cidr' => $m[2],
                'up' => $linkState[$name] ?? false,
            ];
        }

        $routesResult = $this->runner->run([$this->ipBinary, '-4', 'route', 'show', 'default'], 10);
        $routes = [];
        foreach (preg_split('/\r?\n/', trim($routesResult['stdout'])) ?: [] as $line) {
            if (preg_match('/^default(?:\s+via\s+(\S+))?.*?\bdev\s+(\S+)/', $line, $m)) {
                $routes[] = ['gateway' => $m[1] ?? null, 'interface' => $m[2]];
            }
        }

        $targets = [];
        foreach ($preferredInterfaces as $name) {
            foreach ($interfaces[$name] ?? [] as $entry) {
                if (!$entry['up'] || $entry['prefix'] < 1 || $entry['prefix'] > 30) {
                    continue;
                }
                $targets[] = $entry + ['interface' => $name];
            }
        }
        return ['interfaces' => $interfaces, 'default_routes' => $routes, 'scan_targets' => $targets];
    }
}
