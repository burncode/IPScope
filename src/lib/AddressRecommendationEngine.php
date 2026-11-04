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
            throw new \InvalidArgumentException('Recommendation limit must be between 1 and 100.');
        }

        $networks = $snapshot['networks'] ?? null;
        if (!is_array($networks)) {
            throw new \InvalidArgumentException('Snapshot does not contain a valid network inventory.');
        }

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
            if (($network['address_space_managed'] ?? true) === false) {
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

                $candidates[] = [
                    'ip' => $ip,
                    'status' => 'available',
                    'reason' => 'No known Docker, template, system, or exclusion claim was found.',
                ];
            }

            usort($candidates, static function (array $left, array $right): int {
                return ip2long($left['ip']) <=> ip2long($right['ip']);
            });

            $subnets = array_values(array_filter(
                array_map(
                    static fn(array $config): string => (string)($config['subnet'] ?? ''),
                    is_array($network['ipv4'] ?? null) ? $network['ipv4'] : []
                ),
                static fn(string $subnet): bool => $subnet !== ''
            ));

            $selected[] = [
                'network' => $name,
                'driver' => (string)($network['driver'] ?? 'unknown'),
                'subnets' => $subnets,
                'candidate_count' => count($candidates),
                'candidates' => array_slice($candidates, 0, $limit),
            ];
        }

        if ($networkName !== null && $networkName !== '' && !$knownNetwork) {
            throw new \InvalidArgumentException('The requested Docker network was not found in the snapshot.');
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
            'notice' => 'Suggestions are based only on the latest Docker and Unraid template snapshot. They cannot guarantee that an address is unused by DHCP clients, offline devices, or hosts outside Docker.',
        ];
    }
}
