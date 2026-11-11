<?php
declare(strict_types=1);

namespace IPScope;

/**
 * Builds a bounded ARP scan plan from explicit IPv4 targets and CIDR ranges.
 * CIDR expansion is limited to 256 usable targets per task.
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
        if (count($targets) < 1 || count($targets) > 256) {
            throw new \InvalidArgumentException('A scan request must contain between 1 and 256 entries.');
        }

        $local = [];
        foreach ($localIps as $ip) {
            if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                throw new \InvalidArgumentException('Local address list contains an invalid IPv4 address.');
            }
            $local[$ip] = true;
        }

        $unique = [];
        foreach ($targets as $target) {
            if (!is_string($target)) {
                throw new \InvalidArgumentException('Scan targets must be IPv4 addresses or CIDR ranges.');
            }
            $target = trim($target);
            if (str_contains($target, '/')) {
                if (!preg_match('/^((?:\d{1,3}\.){3}\d{1,3})\/(\d|[12]\d|3[0-2])$/', $target, $matches)
                    || filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    throw new \InvalidArgumentException('CIDR target must use valid IPv4/prefix notation.');
                }
                $prefix = (int)$matches[2];
                $ipLong = (int)sprintf('%u', ip2long($matches[1]));
                $mask = $prefix === 0 ? 0 : ((0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF);
                $network = $ipLong & $mask;
                $broadcast = $network | ((~$mask) & 0xFFFFFFFF);
                $first = $prefix <= 30 ? $network + 1 : $network;
                $last = $prefix <= 30 ? $broadcast - 1 : $broadcast;
                if ($last >= $first) {
                    for ($value = $first; $value <= $last; $value++) {
                        $ip = long2ip($value);
                        if (!isset($local[$ip])) $unique[$ip] = true;
                        if (count($unique) > 256) {
                            throw new \InvalidArgumentException('A scan plan cannot contain more than 256 expanded targets. Use a smaller subnet.');
                        }
                    }
                }
            } else {
                if (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    throw new \InvalidArgumentException('Scan target list contains an invalid IPv4 address.');
                }
                if (!isset($local[$target])) $unique[$target] = true;
                if (count($unique) > 256) {
                    throw new \InvalidArgumentException('A scan plan cannot contain more than 256 targets.');
                }
            }
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
