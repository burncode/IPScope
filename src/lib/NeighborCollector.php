<?php
declare(strict_types=1);

namespace IPScope;

final class NeighborCollector
{
    private ProcessRunner $runner;
    private string $ipBinary;

    public function __construct(ProcessRunner $runner, string $ipBinary = '/usr/sbin/ip')
    {
        $this->runner = $runner;
        $this->ipBinary = $ipBinary;
    }

    /** @return list<array{ip:string,mac:?string,interface:string,state:string}> */
    public function collect(?string $interface = null): array
    {
        $command = [$this->ipBinary, 'neigh', 'show'];
        if ($interface !== null && $interface !== '') {
            $command[] = 'dev';
            $command[] = $interface;
        }
        $result = $this->runner->run($command, 10);
        if ($result['exit_code'] !== 0) {
            throw new \RuntimeException('Unable to collect neighbor table: ' . trim($result['stderr']));
        }
        $records = [];
        foreach (preg_split('/\r?\n/', trim($result['stdout'])) ?: [] as $line) {
            if (!preg_match('/^(\d+\.\d+\.\d+\.\d+)\s+dev\s+(\S+)(.*)$/', trim($line), $m)) {
                continue;
            }
            $tail = $m[3];
            preg_match('/\blladdr\s+([0-9a-fA-F:]{17})\b/', $tail, $macMatch);
            preg_match('/\b(REACHABLE|STALE|DELAY|PROBE|INCOMPLETE|FAILED|PERMANENT|NOARP)\b/', $tail, $stateMatch);
            $records[] = [
                'ip' => $m[1],
                'mac' => isset($macMatch[1]) ? strtolower($macMatch[1]) : null,
                'interface' => $m[2],
                'state' => strtolower($stateMatch[1] ?? 'unknown'),
            ];
        }
        return $records;
    }

    /** Merge duplicate interface observations without treating them as separate devices. */
    public function merge(array $records, string $preferredInterface = 'bond0'): array
    {
        $merged = [];
        foreach ($records as $record) {
            $ip = $record['ip'];
            $merged[$ip] ??= ['ip' => $ip, 'mac_addresses' => [], 'interfaces' => [], 'states' => []];
            if (is_string($record['mac']) && $record['mac'] !== '') {
                $merged[$ip]['mac_addresses'][$record['mac']] = true;
            }
            $merged[$ip]['interfaces'][$record['interface']] = true;
            $merged[$ip]['states'][$record['state']] = true;
        }
        $result = [];
        foreach ($merged as $entry) {
            $macs = array_keys($entry['mac_addresses']);
            $interfaces = array_keys($entry['interfaces']);
            $states = array_keys($entry['states']);
            $result[] = [
                'ip' => $entry['ip'],
                'mac_addresses' => $macs,
                'interfaces' => $interfaces,
                'preferred_interface' => in_array($preferredInterface, $interfaces, true) ? $preferredInterface : ($interfaces[0] ?? null),
                'states' => $states,
                'observation' => count($macs) > 1 ? 'multiple_macs' : (count($macs) === 1 ? 'neighbor_cached' : 'unresolved'),
            ];
        }
        return $result;
    }
}
