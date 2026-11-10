<?php
declare(strict_types=1);

namespace IPScope;

/**
 * Builds a bounded ARP scan plan from an explicit list of user-selected IPv4
 * targets. It never expands a subnet or scans an entire network implicitly.
 */
final class LanScanPlanner
{
    /** @return array<string,mixed> */
    public function plan(string $interface, array $targets, array $localIps = [], int $batchSize = 32): array
    {
        if (!preg_match('/^[a-zA-Z0-9_.:-]{1,32}$/', $interface)) {
            throw new \InvalidArgumentException('Invalid scan interface.');
        }
        if ($batchSize < 1 || $batchSize > 64) {
            throw new \InvalidArgumentException('Batch size must be between 1 and 64.');
        }
        if (count($targets) > 256) {
            throw new \InvalidArgumentException('A scan plan cannot contain more than 256 targets.');
        }

        $local = [];
        foreach ($localIps as $ip) {
            if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new \InvalidArgumentException('Local address list contains an invalid IPv4 address.');
            }
            $local[$ip] = true;
        }

        $unique = [];
        foreach ($targets as $ip) {
            if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new \InvalidArgumentException('Scan target list contains an invalid IPv4 address.');
            }
            if (isset($local[$ip])) {
                continue;
            }
            $unique[$ip] = true;
        }

        $ips = array_keys($unique);
        $batches = array_chunk($ips, $batchSize);
        return [
            'interface' => $interface,
            'target_count' => count($ips),
            'batch_size' => $batchSize,
            'batch_count' => count($batches),
            'batches' => array_map(static fn(array $batch, int $index): array => [
                'index' => $index,
                'targets' => $batch,
            ], $batches, array_keys($batches)),
            'created_at' => date(DATE_ATOM),
        ];
    }
}
