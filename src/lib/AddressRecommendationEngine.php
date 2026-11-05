<?php
declare(strict_types=1);

namespace IPScope;

/**
 * Produces conservative IPv4 candidates from the latest inventory snapshot.
 *
 * This is advisory only: Docker/template observations cannot detect DHCP leases,
 * offline hosts, or devices outside the Docker inventory.
 */
final class AddressRecommendationEngine
{
    /**
     * @param array<string,mixed> $snapshot
     * @return array<string,mixed>
     */
    public function recommend(array $snapshot, ?string $networkName = null, int $limit = 20): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Recommendation limit must be between 1 and 100.');
        }

        $networks = $snapshot['networks'] ?? null;
        if (!is_array($networks)) {
            throw new InvalidArgumentException('Snapshot does not contain a valid network inventory.');
        }

        $calculator = new IPv4Calculator();
        $selected = [];
        $knownNetwork = false;
        foreach ($networks as $network) {
            if (!is_array($network)) {
                continue;
            }

            $name = (string)($network['name'] ?? '');
            if ($name === '') {
                continue;
            }
            if ($networkName !== null && $networkName !== '' && $name !== $networkName) {
                continue;
            }

            $knownNetwork = true;

            // Fail closed: only explicitly managed address pools can produce suggestions.
            if (($network['address_space_managed'] ?? null) !== true) {
                continue;
            }

            $subnets = [];
            $hostRanges = [];
            foreach (($network['ipv4'] ?? []) as $config) {
                if (!is_array($config)) {
                    continue;
                }
                $subnet = trim((string)($config['subnet'] ?? ''));
                if ($subnet === '') {
                    continue;
                }
                try {
                    $range = $calculator->range($subnet);
                } catch (InvalidArgumentException $error) {
                    continue;
                }
                // The inventory only expands conventional host ranges. Match that
                // policy here so /31, /32, network and broadcast addresses fail closed.
                if ($range['first_host'] === null || $range['last_host'] === null) {
                    continue;
                }
                $subnets[] = $subnet;
                $hostRanges[] = [
                    'first' => (int)sprintf('%u', ip2long($range['first_host'])),
                    'last' => (int)sprintf('%u', ip2long($range['last_host'])),
                ];
            }

            // No valid subnet means there is no trustworthy address pool boundary.
            if (!$hostRanges) {
                continue;
            }

            $candidates = [];
            foreach (($network['known_addresses'] ?? []) as $address) {
                if (!is_array($address)
                    || ($address['status'] ?? '') !== 'available'
                    || ($address['available'] ?? false) !== true) {
                    continue;
                }

                $ip = $address['ip'] ?? null;
                if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    continue;
                }

                $ipNumber = (int)sprintf('%u', ip2long($ip));
                $insideAssignableRange = false;
                foreach ($hostRanges as $range) {
                    if ($ipNumber >= $range['first'] && $ipNumber <= $range['last']) {
                        $insideAssignableRange = true;
                        break;
                    }
                }
                if (!$insideAssignableRange) {
                    continue;
                }

                $candidates[$ip] = [
                    'ip' => $ip,
                    'status' => 'available',
                    'reason' => 'Marked available by the inventory and verified inside a managed IPv4 host range.',
                ];
            }

            $candidates = array_values($candidates);
            usort($candidates, static function (array $left, array $right): int {
                return (int)sprintf('%u', ip2long($left['ip']))
                    <=> (int)sprintf('%u', ip2long($right['ip']));
            });

            $selected[] = [
                'network' => $name,
                'driver' => (string)($network['driver'] ?? 'unknown'),
                'subnets' => array_values(array_unique($subnets)),
                'candidate_count' => count($candidates),
                'candidates' => array_slice($candidates, 0, $limit),
            ];
        }

        if ($networkName !== null && $networkName !== '' && !$knownNetwork) {
            throw new InvalidArgumentException('The requested Docker network was not found in the snapshot.');
        }

        $candidateCount = array_sum(array_map(
            static fn(array $item): int => $item['candidate_count'],
            $selected
        ));

        return [
            'generated_at' => (string)($snapshot['generated_at'] ?? ''),
            'network_filter' => $networkName,
            'limit_per_network' => $limit,
            'candidate_count' => $candidateCount,
            'networks' => $selected,
            'notice' => 'Suggestions are advisory only. Candidates must be inside an explicitly managed IPv4 host range and marked available by the snapshot. Docker-only data cannot detect DHCP leases, offline devices, or hosts outside Docker.',
        ];
    }
}
