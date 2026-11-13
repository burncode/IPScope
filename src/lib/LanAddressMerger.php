<?php
declare(strict_types=1);

namespace IPScope;

/**
 * Joins passive LAN observations to Docker address inventory without changing
 * the inventory's availability or ownership decisions.
 */
final class LanAddressMerger
{
    /** @return array{networks:array,lan_discovery:array,conflicts:array} */
    public function merge(array $networks, array $lanDiscovery, array $containers = [], ?array $activeScan = null): array
    {
        $devices = is_array($lanDiscovery['devices'] ?? null) ? $lanDiscovery['devices'] : [];
        $deviceByIp = [];
        foreach ($devices as $device) {
            if (!is_array($device) || !$this->isIPv4($device['ip'] ?? null)) {
                continue;
            }
            $deviceByIp[$device['ip']][] = $device;
        }

        $localByIp = [];
        foreach (($lanDiscovery['local_addresses'] ?? []) as $local) {
            if (is_array($local) && $this->isIPv4($local['ip'] ?? null)) {
                $localByIp[$local['ip']] = $local;
            }
        }

        $dockerMacByContainer = [];
        foreach ($containers as $container) {
            $name = (string)($container['name'] ?? '');
            foreach (($container['networks'] ?? []) as $networkName => $details) {
                $mac = strtolower((string)($details['mac'] ?? ''));
                if ($name !== '' && $mac !== '' && preg_match('/^[0-9a-f]{2}(?::[0-9a-f]{2}){5}$/', $mac)) {
                    $dockerMacByContainer[$networkName][$name] = $mac;
                }
            }
        }

        $conflicts = [];
        $matchedIps = [];
        $dockerClaimIps = [];
        foreach ($networks as &$network) {
            if (!is_array($network)) {
                continue;
            }
            $addresses = is_array($network['known_addresses'] ?? null) ? $network['known_addresses'] : [];
            foreach ($addresses as &$address) {
                if (!is_array($address) || !$this->isIPv4($address['ip'] ?? null)) {
                    continue;
                }
                $ip = $address['ip'];
                foreach (($address['owners'] ?? []) as $owner) {
                    if (in_array($owner['source'] ?? '', ['docker_runtime', 'docker_ipam', 'unraid_template'], true)) {
                        $dockerClaimIps[$ip] = true;
                        break;
                    }
                }
                $address['lan_evidence'] = [];
                $address['active_scan_evidence'] = [];
                if (is_array($activeScan) && ($activeScan['interface'] ?? '') === ($network['name'] ?? null)) {
                    foreach (($activeScan['results'] ?? []) as $scanResult) {
                        if (!is_array($scanResult) || ($scanResult['ip'] ?? null) !== $ip) continue;
                        $address['active_scan_evidence'][] = [
                            'status' => (string)($scanResult['status'] ?? 'unknown'),
                            'mac' => $scanResult['mac'] ?? null,
                            'scanned_at' => $activeScan['scanned_at'] ?? null,
                            'task_id' => $activeScan['task_id'] ?? null,
                        ];
                    }
                }
                if (isset($localByIp[$ip])) {
                    $address['lan_evidence'][] = [
                        'type' => 'local_interface',
                        'status' => 'local',
                        'interface' => $localByIp[$ip]['interface'] ?? null,
                        'mac_addresses' => [],
                    ];
                    $matchedIps[$ip] = true;
                }
                foreach ($deviceByIp[$ip] ?? [] as $device) {
                    $address['lan_evidence'][] = [
                        'type' => 'neighbor_table',
                        'status' => $device['status'] ?? 'neighbor_cached',
                        'interfaces' => $device['interfaces'] ?? [],
                        'mac_addresses' => $device['mac_addresses'] ?? [],
                        'states' => $device['states'] ?? [],
                    ];
                    $matchedIps[$ip] = true;

                    $observedMacs = array_values(array_unique(array_map('strtolower', array_filter(
                        $device['mac_addresses'] ?? [],
                        static fn($mac): bool => is_string($mac) && preg_match('/^[0-9a-f]{2}(?::[0-9a-f]{2}){5}$/i', $mac) === 1
                    ))));
                    if (count($observedMacs) > 1) {
                        $conflicts[] = [
                            'type' => 'lan_multiple_macs_for_ip',
                            'severity' => 'warning',
                            'network' => $network['name'] ?? null,
                            'ip' => $ip,
                            'mac_addresses' => $observedMacs,
                            'evidence_status' => $device['status'] ?? 'neighbor_cached',
                        ];
                    }

                    foreach (($address['owners'] ?? []) as $owner) {
                        if (($owner['source'] ?? '') !== 'docker_runtime') {
                            continue;
                        }
                        $containerName = (string)($owner['container'] ?? '');
                        $dockerMac = $dockerMacByContainer[$network['name'] ?? ''][$containerName] ?? '';
                        if ($dockerMac !== '' && $observedMacs && !in_array($dockerMac, $observedMacs, true)) {
                            $conflicts[] = [
                                'type' => 'docker_lan_mac_mismatch',
                                'severity' => 'warning',
                                'network' => $network['name'] ?? null,
                                'ip' => $ip,
                                'container' => $containerName,
                                'docker_mac' => $dockerMac,
                                'observed_mac_addresses' => $observedMacs,
                                'evidence_status' => $device['status'] ?? 'neighbor_cached',
                            ];
                        }
                    }
                }
            }
            unset($address);
            $network['known_addresses'] = $addresses;
            unset($network['_container_macs']);
        }
        unset($network);

        $unmatchedDevices = [];
        foreach ($devices as $device) {
            if (is_array($device) && isset($device['ip']) && !isset($matchedIps[$device['ip']])) {
                $unmatchedDevices[] = $device;
            }
        }

        $lanDiscovery['matched_device_count'] = count(array_filter($devices, static fn(array $device): bool => isset($matchedIps[$device['ip'] ?? ''])));
        // A device can match the full subnet inventory without matching any
        // Docker reservation/occupancy claim. Keep these concepts separate.
        $lanDiscovery['matched_inventory_address_count'] = $lanDiscovery['matched_device_count'];
        $lanDiscovery['matched_docker_claim_count'] = count(array_filter(
            $devices,
            static fn(array $device): bool => isset($dockerClaimIps[$device['ip'] ?? ''])
        ));
        $lanDiscovery['unmatched_devices'] = $unmatchedDevices;
        $lanDiscovery['availability_inferred'] = false;

        return [
            'networks' => $networks,
            'lan_discovery' => $lanDiscovery,
            'conflicts' => $conflicts,
        ];
    }

    private function isIPv4($value): bool
    {
        return is_string($value) && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }
}
