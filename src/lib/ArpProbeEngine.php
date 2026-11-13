<?php
declare(strict_types=1);

namespace IPScope;

/**
 * Conservative, rate-limited IPv4 ARP probe.
 * A no-response result is deliberately not classified as an available address.
 */
final class ArpProbeEngine
{
    private ProcessRunner $runner;
    private string $arpingBinary;

    public function __construct(ProcessRunner $runner, string $arpingBinary = '/sbin/arping')
    {
        $this->runner = $runner;
        $this->arpingBinary = $arpingBinary;
    }

    /** @return array{ip:string,interface:string,status:string,mac:?string,exit_code:?int,error:?string} */
    public function probe(string $ip, string $interface, int $waitSeconds = 1): array
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw new \InvalidArgumentException('ARP probe requires a valid IPv4 address.');
        }
        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,32}$/', $interface)) {
            throw new \InvalidArgumentException('Invalid network interface name.');
        }
        if ($waitSeconds < 1 || $waitSeconds > 5) {
            throw new \InvalidArgumentException('ARP probe wait must be between 1 and 5 seconds.');
        }

        try {
            $result = $this->runner->run(
                [$this->arpingBinary, '-I', $interface, '-c', '1', '-w', (string)$waitSeconds, $ip],
                $waitSeconds + 3
            );
        } catch (\Throwable $error) {
            return ['ip'=>$ip, 'interface'=>$interface, 'status'=>'probe_error', 'mac'=>null, 'exit_code'=>null, 'error'=>$error->getMessage()];
        }

        $output = $result['stdout'] . "\n" . $result['stderr'];
        preg_match('/\b(?:reply from|Unicast reply from)\s+' . preg_quote($ip, '/') . '\s*\[([0-9a-fA-F:]{17})\]/i', $output, $match);
        if (!isset($match[1])) {
            preg_match('/\b([0-9a-fA-F]{2}(?::[0-9a-fA-F]{2}){5})\b/', $output, $match);
        }
        $mac = isset($match[1]) ? strtolower($match[1]) : null;
        $hasResponse = preg_match('/\bReceived\s+[1-9][0-9]*\s+response/i', $output) === 1;
        $explicitNoResponse = preg_match('/\bReceived\s+0\s+response/i', $output) === 1;
        $responded = $mac !== null || $hasResponse;
        $probeFailed = !$responded && !$explicitNoResponse && $result['exit_code'] !== 0;

        return [
            'ip' => $ip,
            'interface' => $interface,
            'status' => $responded ? 'responding' : ($probeFailed ? 'probe_error' : ($result['exit_code'] === 0 ? 'response_unparsed' : 'no_response')),
            'mac' => $mac,
            'exit_code' => $result['exit_code'],
            'error' => $probeFailed ? trim($result['stderr']) : null,
        ];
    }

    /**
     * Probe an explicit, bounded list only. No subnet expansion is performed here.
     * @return list<array<string,mixed>>
     */
    public function probeMany(array $ips, string $interface, int $maxTargets = 32, int $intervalMs = 100): array
    {
        if ($maxTargets < 1 || $maxTargets > 64) {
            throw new \InvalidArgumentException('maxTargets must be between 1 and 64.');
        }
        if ($intervalMs < 0 || $intervalMs > 2000) {
            throw new \InvalidArgumentException('intervalMs must be between 0 and 2000.');
        }
        $unique = [];
        foreach ($ips as $ip) {
            if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new \InvalidArgumentException('Probe list contains an invalid IPv4 address.');
            }
            $unique[$ip] = true;
        }
        if (count($unique) > $maxTargets) {
            throw new \InvalidArgumentException('Probe target limit exceeded.');
        }

        $results = [];
        foreach (array_keys($unique) as $index => $ip) {
            if ($index > 0 && $intervalMs > 0) {
                usleep($intervalMs * 1000);
            }
            $results[] = $this->probe($ip, $interface);
        }
        return $results;
    }
}
