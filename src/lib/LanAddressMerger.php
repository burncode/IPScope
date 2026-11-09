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
    public function merge(array $networks, array $lanDiscovery): array
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

        $conflicts = [];
        $matchedIps = [];
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
                $address['lan_evidence'] = [];
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
                        foreach (($network['_container_macs'] ?? []) as $containerMac) {
                            if (($containerMac['container'] ?? '') !== $containerName) {
                                continue;
                            }
                            $dockerMac = strtolower((string)($containerMac['mac'] ?? ''));
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
