<?php
declare(strict_types=1);

namespace IPScope;

/**
 * Builds a passive LAN discovery snapshot. This collector never performs
 * active probes and never infers that an unobserved address is available.
 */
final class LanDiscoveryCollector
{
    private LanInterfaceCollector $interfaces;
    private NeighborCollector $neighbors;
    private string $preferredInterface;

    public function __construct(
        ProcessRunner $runner,
        string $preferredInterface = 'bond0',
        string $ipBinary = '/usr/sbin/ip'
    ) {
        $this->interfaces = new LanInterfaceCollector($runner, $ipBinary);
        $this->neighbors = new NeighborCollector($runner, $ipBinary);
        $this->preferredInterface = $preferredInterface;
    }

    /** @return array<string,mixed> */
    public function collect(): array
    {
        $interfaceData = $this->interfaces->collect([$this->preferredInterface]);
        $neighborRecords = $this->neighbors->collect();
        $merged = $this->neighbors->merge($neighborRecords, $this->preferredInterface);

        $localAddresses = [];
        foreach ($interfaceData['interfaces'] as $name => $entries) {
            foreach ($entries as $entry) {
                $localAddresses[$entry['ipv4']] = [
                    'ip' => $entry['ipv4'],
                    'interface' => $name,
                    'cidr' => $entry['cidr'],
                    'status' => 'local',
                    'source' => 'local_interface',
                ];
            }
        }

        $devices = [];
        foreach ($merged as $entry) {
            if (isset($localAddresses[$entry['ip']])) {
                continue;
            }
            $states = $entry['states'];
            $hasFreshState = count(array_intersect($states, ['reachable', 'permanent', 'noarp'])) > 0;
            $devices[] = $entry + [
                'status' => $hasFreshState ? 'observed_reachable' : 'neighbor_cached',
                'source' => 'neighbor_table',
            ];
        }

        return [
            'status' => 'collected',
            'preferred_interface' => $this->preferredInterface,
            'interfaces' => $interfaceData['interfaces'],
            'default_routes' => $interfaceData['default_routes'],
            'scan_targets' => $interfaceData['scan_targets'],
            'local_addresses' => array_values($localAddresses),
            'neighbor_record_count' => count($neighborRecords),
            'devices' => $devices,
            'active_probe_performed' => false,
            'availability_inferred' => false,
        ];
    }
}
