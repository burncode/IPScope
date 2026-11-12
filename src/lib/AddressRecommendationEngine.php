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
    public function recommend(array $snapshot, ?string $networkName = null, int $limit = 20, int $maxSnapshotAgeSeconds = 300, int $offset = 0): array
    {
        if ($limit < 1 || $limit > 100) {
            throw new \InvalidArgumentException('Recommendation limit must be between 1 and 100.');
        }

        if ($offset < 0 || $offset > 10000) {
            throw new \InvalidArgumentException('Recommendation offset must be between 0 and 10000.');
        }

        if ($maxSnapshotAgeSeconds < 60 || $maxSnapshotAgeSeconds > 86400) {
            throw new \InvalidArgumentException('Maximum snapshot age must be between 60 seconds and 24 hours.');
        }

        $networks = $snapshot['networks'] ?? null;
        if (!is_array($networks)) {
            throw new \InvalidArgumentException('Snapshot does not contain a valid network inventory.');
        }

        $generatedAt = (string)($snapshot['generated_at'] ?? '');
        $snapshotTimeStatus = 'missing';
        $snapshotAgeSeconds = null;
        if ($generatedAt !== '') {
            try {
                $snapshotTimestamp = (new \DateTimeImmutable($generatedAt))->getTimestamp();
                $now = time();
                if ($snapshotTimestamp > $now + 60) {
                    $snapshotTimeStatus = 'future';
                } else {
                    $snapshotAgeSeconds = max(0, $now - $snapshotTimestamp);
                    $snapshotTimeStatus = $snapshotAgeSeconds > $maxSnapshotAgeSeconds ? 'stale' : 'valid';
                }
            } catch (\Exception $error) {
                $snapshotTimeStatus = 'invalid';
            }
        }

        $lanDiscovery = $snapshot['lan_discovery'] ?? null;
        $lanStatus = is_array($lanDiscovery) ? (string)($lanDiscovery['status'] ?? 'unavailable') : 'unavailable';
        $recommendationBlockedReason = null;
        if ($snapshotTimeStatus !== 'valid') {
            $recommendationBlockedReason = 'snapshot_' . $snapshotTimeStatus;
        } elseif ($lanStatus !== 'collected') {
            $recommendationBlockedReason = 'lan_discovery_' . $lanStatus;
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

            // Fail closed when the snapshot or passive LAN evidence is unavailable or stale.
            if ($recommendationBlockedReason !== null) {
                continue;
            }

            // Fail closed: only explicitly managed address pools can produce suggestions.
            if (($network['address_space_managed'] ?? null) !== true) {
                continue;
            }

            $subnets = [];
            $hostRanges = [];
            $ipConfigs = is_array($network['ipv4'] ?? null) ? $network['ipv4'] : [];
            foreach ($ipConfigs as $config) {
                if (!is_array($config)) {
                    continue;
                }
                $subnet = trim((string)($config['subnet'] ?? ''));
                if ($subnet === '') {
                    continue;
                }
                try {
                    $range = $calculator->range($subnet);
                } catch (\InvalidArgumentException $error) {
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
            $knownAddresses = is_array($network['known_addresses'] ?? null) ? $network['known_addresses'] : [];
            foreach ($knownAddresses as $address) {
                if (!is_array($address)
                    || ($address['status'] ?? '') !== 'available'
                    || ($address['available'] ?? false) !== true) {
                    continue;
                }

                // Any LAN neighbor observation is occupancy evidence. REACHABLE
                // confirms a live peer; STALE is historical and inconclusive, but
                // neither may be advertised as a safe candidate until re-probed
                // or explicitly reviewed by the user.
                if (!empty($address['lan_evidence']) && is_array($address['lan_evidence'])) {
                    continue;
                }
                $activeEvidence = is_array($address['active_scan_evidence'] ?? null) ? $address['active_scan_evidence'] : [];
                $hasActiveResponse = false;
                foreach ($activeEvidence as $evidence) {
                    if (is_array($evidence) && in_array(($evidence['status'] ?? ''), ['responding', 'response_unparsed'], true)) {
                        $hasActiveResponse = true;
                        break;
                    }
                }
                if ($hasActiveResponse) {
                    continue;
                }

                $ip = $address['ip'] ?? null;
                if (!is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    continue;
                }

                $risk = (new AddressRiskEngine())->evaluate($ip, $name, is_array($snapshot['dhcp'] ?? null) ? $snapshot['dhcp'] : ['mode'=>'unknown']);
                if (!$risk['recommendable']) {
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
                    'risk' => $risk,
                    'evidence' => [
                        'inventory_status' => 'available',
                        'subnet_membership' => 'verified',
                        'known_claims' => 'none_recorded_by_inventory',
                    ],
                    'reason' => 'The snapshot marks this address available, and the address falls inside a managed subnet host range. No Docker, template, system, or exclusion claim is recorded in the inventory.',
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
                'candidates' => array_slice($candidates, $offset, $limit),
                'candidate_offset' => $offset,
                'candidate_limit' => $limit,
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
            'generated_at' => $generatedAt,
            'snapshot_time_status' => $snapshotTimeStatus,
            'snapshot_age_seconds' => $snapshotAgeSeconds,
            'network_filter' => $networkName,
            'limit_per_network' => $limit,
            'offset_per_network' => $offset,
            'candidate_count' => $candidateCount,
            'dhcp_mode' => (string)($snapshot['dhcp']['mode'] ?? 'unknown'),
            'networks' => $selected,
            'blocked_reason' => $recommendationBlockedReason,
            'max_snapshot_age_seconds' => $maxSnapshotAgeSeconds,
            'notice' => 'Suggestions are advisory only. Candidates must be inside an explicitly managed IPv4 host range, marked available, and have no LAN neighbor evidence. REACHABLE and STALE neighbor observations are excluded pending verification. Addresses in configured DHCP dynamic pools or static reservations are excluded. When DHCP mode is unknown, no address is presented as recommendable until the user configures DHCP mode or confirms DHCP is disabled.',
        ];
    }
}
