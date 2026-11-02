<?php
declare(strict_types=1);

namespace IPScope;

final class NetworkCollector
{
    private DockerClient $docker;

    public function __construct(DockerClient $docker)
    {
        $this->docker = $docker;
    }

    /** @return list<array<string,mixed>> */
    public function collect(): array
    {
        $result = [];
        foreach ($this->docker->inspectNetworks() as $network) {
            $ipamConfigs = $network['IPAM']['Config'] ?? [];
            $ipv4Configs = [];

            foreach (is_array($ipamConfigs) ? $ipamConfigs : [] as $config) {
                $subnet = $config['Subnet'] ?? null;
                if (!is_string($subnet) || strpos($subnet, ':') !== false) {
                    continue;
                }
                $ipv4Configs[] = [
                    'subnet' => $subnet,
                    'gateway' => $config['Gateway'] ?? null,
                    'ip_range' => $config['IPRange'] ?? null,
                    'auxiliary_addresses' => $config['AuxiliaryAddresses'] ?? [],
                ];
            }

            $result[] = [
                'id' => $network['Id'] ?? null,
                'name' => $network['Name'] ?? 'unknown',
                'driver' => $network['Driver'] ?? null,
                'scope' => $network['Scope'] ?? null,
                'internal' => (bool)($network['Internal'] ?? false),
                'ipv4' => $ipv4Configs,
            ];
        }
        return $result;
    }
}
