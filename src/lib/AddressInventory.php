<?php
declare(strict_types=1);

namespace IPScope;

/**
 * Merges Docker runtime observations with persistent Unraid template reservations.
 * This class never mutates Docker or Unraid configuration.
 */
final class AddressInventory
{
    /**
     * @param list<array<string,mixed>> $networks
     * @param list<array<string,mixed>> $containers
     * @param list<array<string,mixed>> $templates
     * @return array{networks:list<array<string,mixed>>,conflicts:list<array<string,mixed>>}
     */
    public function build(array $networks, array $containers, array $templates): array
    {
        $containerByName = [];
        foreach ($containers as $container) {
            $name = (string)($container['name'] ?? '');
            if ($name !== '') {
                $containerByName[$name] = $container;
            }
        }

        $claims = [];
        $orphanTemplates = [];
        $networkNames = [];
        foreach ($networks as $network) {
            $name = (string)($network['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $networkNames[$name] = true;
        }

        // Reserve Docker-declared infrastructure addresses before container claims.
        foreach ($networks as $network) {
            $networkName = (string)($network['name'] ?? '');
            if ($networkName === '') {
                continue;
            }
            foreach (($network['ipv4'] ?? []) as $config) {
                foreach (['gateway' => 'gateway', 'auxiliary_addresses' => 'auxiliary'] as $field => $kind) {
                    $values = $field === 'auxiliary_addresses'
                        ? (is_array($config[$field] ?? null) ? array_values($config[$field]) : [])
                        : [$config[$field] ?? null];
                    foreach ($values as $ip) {
                        if ($this->isIPv4($ip)) {
                            $this->addClaim($claims, $networkName, $ip, [
                                'container' => 'system:' . $kind,
                                'source' => 'docker_network',
                                'kind' => 'system',
                                'state' => 'system',
                            ]);
                        }
                    }
                }
            }
        }

        foreach ($templates as $template) {
            $networkName = trim((string)($template['network'] ?? ''));
            $containerName = (string)($template['name'] ?? 'unknown-template');
            // A saved template without a corresponding Docker container is only
            // an install template, not proof that an existing container reserves IP.
            if (!isset($containerByName[$containerName])) {
                $orphanTemplates[] = [
                    'name' => $containerName,
                    'network' => $networkName,
                    'ips' => array_values(array_filter($template['ips'] ?? [], [$this, 'isIPv4'])),
                    'reason' => 'no_matching_docker_container',
                ];
                continue;
            }

            if ($networkName === '' || !isset($networkNames[$networkName])) {
                continue;
            }

            foreach (($template['ips'] ?? []) as $ip) {
                if (!$this->isIPv4($ip)) {
                    continue;
                }
                $this->addClaim($claims, $networkName, $ip, [
                    'container' => $containerName,
                    'source' => 'unraid_template',
                    'kind' => 'reservation',
                    'state' => $containerByName[$containerName]['state'] ?? 'not_found',
                ]);
            }
        }

        foreach ($containers as $container) {
            $containerName = (string)($container['name'] ?? 'unknown-container');
            $running = (bool)($container['running'] ?? false);

            foreach (($container['networks'] ?? []) as $networkName => $details) {
                if (!isset($networkNames[$networkName]) || !is_array($details)) {
                    continue;
                }

                $actualIp = $details['ipv4'] ?? null;
                $requestedIp = $details['ipam_ipv4'] ?? null;

                if ($running && $this->isIPv4($actualIp)) {
                    $this->addClaim($claims, $networkName, $actualIp, [
                        'container' => $containerName,
                        'source' => 'docker_runtime',
                        'kind' => 'occupied',
                        'state' => 'running',
                    ]);
                }

                // A stopped container's stale endpoint IP is intentionally ignored.
                // Its explicit IPAM request, if present, remains reservation evidence.
                if ($this->isIPv4($requestedIp)) {
                    $this->addClaim($claims, $networkName, $requestedIp, [
                        'container' => $containerName,
                        'source' => 'docker_ipam',
                        'kind' => 'reservation',
                        'state' => $running ? 'running' : (string)($container['state'] ?? 'stopped'),
                    ]);
                }
            }
        }

        $networkResults = [];
        $conflicts = [];

        foreach ($networks as $network) {
            $networkName = (string)($network['name'] ?? '');
            if ($networkName === '') {
                continue;
            }

            $addresses = [];
            foreach (($claims[$networkName] ?? []) as $ip => $owners) {
                $distinctContainers = [];
                $runtimeOwners = [];
                $reservationOwners = [];
                $systemOwners = [];
                foreach ($owners as $owner) {
                    $distinctContainers[$owner['container']] = true;
                    if ($owner['kind'] === 'occupied') {
                        $runtimeOwners[$owner['container']] = true;
                    } elseif ($owner['kind'] === 'system') {
                        $systemOwners[$owner['container']] = true;
                    } else {
                        $reservationOwners[$owner['container']] = true;
                    }
                }

                // A running container's template/IPAM request is not a second,
                // independent reservation owner for the same address. Runtime
                // occupancy takes precedence for that same container/IP pair.
                foreach (array_keys($runtimeOwners) as $runtimeOwner) {
                    unset($reservationOwners[$runtimeOwner]);
                }

                $runtimeCount = count($runtimeOwners);
                $reservationCount = count($reservationOwners);
                $ownerCount = count($distinctContainers);
                $hasMultipleOwners = $ownerCount > 1;

                if ($hasMultipleOwners) {
                    $status = 'conflict';
                    if (count($systemOwners) > 0) {
                        $conflictType = 'system_address_collision';
                        $severity = 'critical';
                    } elseif ($runtimeCount > 0 && $reservationCount > 0) {
                        $conflictType = 'active_reservation_collision';
                        $severity = 'critical';
                    } elseif ($runtimeCount > 1) {
                        $conflictType = 'multiple_runtime_owners';
                        $severity = 'critical';
                    } else {
                        $conflictType = 'reservation_conflict';
                        $severity = 'warning';
                    }
                } else {
                    $status = count($systemOwners) > 0 ? 'system' : ($runtimeCount > 0 ? 'occupied' : 'reserved');
                    $conflictType = null;
                    $severity = null;
                }

                $addresses[] = [
                    'ip' => $ip,
                    'status' => $status,
                    'available' => false,
                    'runtime_owner_count' => $runtimeCount,
                    'reservation_owner_count' => $reservationCount,
                    'system_owner_count' => count($systemOwners),
                    'owners' => array_values($owners),
                ];

                if ($hasMultipleOwners) {
                    $conflicts[] = [
                        'type' => $conflictType,
                        'severity' => $severity,
                        'network' => $networkName,
                        'ip' => $ip,
                        'containers' => array_keys($distinctContainers),
                        'running_containers' => array_keys($runtimeOwners),
                        'reserved_containers' => array_keys($reservationOwners),
                        'runtime_owner_count' => $runtimeCount,
                        'reservation_owner_count' => $reservationCount,
                        'system_owners' => array_keys($systemOwners),
                    ];
                }
            }

            // Expand configured IPv4 subnets into a complete address inventory.
            // Guard against unexpectedly large networks so a malformed Docker
            // configuration cannot allocate an unbounded PHP array.
            $addressMap = [];
            foreach ($addresses as $address) {
                $addressMap[$address['ip']] = $address;
            }

            foreach (($network['ipv4'] ?? []) as $config) {
                $subnet = (string)($config['subnet'] ?? '');
                if ($subnet === '') {
                    continue;
                }

                try {
                    $range = (new IPv4Calculator())->range($subnet);
                } catch (\InvalidArgumentException $error) {
                    continue;
                }

                $prefix = (int)substr(strrchr($subnet, '/'), 1);

                // Network and broadcast addresses are never assignable in
                // conventional IPv4 subnets. Keep them visible as system rows.
                if ($prefix <= 30) {
                    foreach ([
                        $range['network'] => 'network_address',
                        $range['broadcast'] => 'broadcast_address',
                    ] as $specialIp => $reason) {
                        if (!isset($addressMap[$specialIp])) {
                            $addressMap[$specialIp] = [
                                'ip' => $specialIp,
                                'status' => 'system',
                                'available' => false,
                                'system_reason' => $reason,
                                'runtime_owner_count' => 0,
                                'reservation_owner_count' => 0,
                                'system_owner_count' => 1,
                                'owners' => [[
                                    'container' => 'system:' . $reason,
                                    'source' => 'ipv4_subnet',
                                    'kind' => 'system',
                                    'state' => 'system',
                                ]],
                            ];
                        }
                    }
                }

                if ($range['first_host'] === null || $range['last_host'] === null) {
                    continue;
                }

                $first = (int)sprintf('%u', ip2long($range['first_host']));
                $last = (int)sprintf('%u', ip2long($range['last_host']));
                if (($last - $first + 1) > 65536) {
                    continue;
                }

                for ($value = $first; $value <= $last; $value++) {
                    $ip = long2ip($value);
                    if (!isset($addressMap[$ip])) {
                        $addressMap[$ip] = [
                            'ip' => $ip,
                            'status' => 'available',
                            'available' => true,
                            'runtime_owner_count' => 0,
                            'reservation_owner_count' => 0,
                            'system_owner_count' => 0,
                            'owners' => [],
                        ];
                    }
                }
            }

            $addresses = array_values($addressMap);
            usort($addresses, static function (array $a, array $b): int {
                return ip2long($a['ip']) <=> ip2long($b['ip']);
            });

            $networkResults[] = array_merge($network, [
                'known_addresses' => $addresses,
                'known_address_count' => count($addresses),
            ]);
        }

        // Detect a running container whose saved template IP differs from its actual IP.
        foreach ($containers as $container) {
            if (empty($container['running'])) {
                continue;
            }
            $containerName = (string)($container['name'] ?? 'unknown-container');
            $template = $containerByName[$containerName] ?? null;
            if (!is_array($template)) {
                continue;
            }

            foreach (($template['networks'] ?? []) as $networkName => $details) {
                $actual = $details['ipv4'] ?? null;
                if (!$this->isIPv4($actual)) {
                    continue;
                }
                foreach ($templates as $savedTemplate) {
                    if (($savedTemplate['name'] ?? '') !== $containerName
                        || ($savedTemplate['network'] ?? '') !== $networkName) {
                        continue;
                    }
                    foreach (($savedTemplate['ips'] ?? []) as $reservedIp) {
                        if ($this->isIPv4($reservedIp) && $reservedIp !== $actual) {
                            $conflicts[] = [
                                'type' => 'template_runtime_mismatch',
                                'network' => $networkName,
                                'container' => $containerName,
                                'template_ip' => $reservedIp,
                                'runtime_ip' => $actual,
                            ];
                        }
                    }
                }
            }
        }

        return [
            'networks' => $networkResults,
            'conflicts' => $conflicts,
            'orphan_templates' => $orphanTemplates,
        ];
    }

    private function addClaim(array &$claims, string $network, string $ip, array $owner): void
    {
        $key = $owner['container'] . '|' . $owner['source'] . '|' . $owner['kind'];
        $claims[$network][$ip][$key] = $owner;
    }

    private function isIPv4($value): bool
    {
        return is_string($value)
            && filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false;
    }
}
