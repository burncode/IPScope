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

        foreach ($templates as $template) {
            $networkName = trim((string)($template['network'] ?? ''));
            $containerName = (string)($template['name'] ?? 'unknown-template');
            // A saved template without a corresponding Docker container is only
            // an install template, not proof that an existing container reserves IP.
            if (!isset($containerByName[$containerName])) {
                if (!empty($template['ips'])) {
                    $orphanTemplates[] = [
                        'name' => $containerName,
                        'network' => $networkName,
                        'ips' => array_values(array_filter($template['ips'], [$this, 'isIPv4'])),
                        'reason' => 'no_matching_docker_container',
                    ];
                }
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
                $hasRuntime = false;
                foreach ($owners as $owner) {
                    $distinctContainers[$owner['container']] = true;
                    if ($owner['kind'] === 'occupied') {
                        $hasRuntime = true;
                    }
                }

                $status = count($distinctContainers) > 1
                    ? 'conflict'
                    : ($hasRuntime ? 'occupied' : 'reserved');

                $addresses[] = [
                    'ip' => $ip,
                    'status' => $status,
                    'available' => false,
                    'owners' => array_values($owners),
                ];

                if (count($distinctContainers) > 1) {
                    $conflicts[] = [
                        'type' => 'duplicate_ip',
                        'network' => $networkName,
                        'ip' => $ip,
                        'containers' => array_keys($distinctContainers),
                    ];
                }
            }

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
