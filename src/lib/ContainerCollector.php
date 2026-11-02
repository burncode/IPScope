<?php
declare(strict_types=1);

namespace IPScope;

final class ContainerCollector
{
    private DockerClient $docker;

    public function __construct(DockerClient $docker)
    {
        $this->docker = $docker;
    }

    /** @return list<array<string,mixed>> */
    public function collect(): array
    {
        $containers = [];
        foreach ($this->docker->inspectAllContainers() as $container) {
            $networks = [];
            $runtimeNetworks = $container['NetworkSettings']['Networks'] ?? [];

            foreach (is_array($runtimeNetworks) ? $runtimeNetworks : [] as $networkName => $details) {
                $networks[$networkName] = [
                    'ipv4' => $this->validIPv4($details['IPAddress'] ?? null),
                    'ipam_ipv4' => $this->validIPv4($details['IPAMConfig']['IPv4Address'] ?? null),
                    'mac' => $details['MacAddress'] ?? null,
                ];
            }

            $containers[] = [
                'id' => $container['Id'] ?? null,
                'name' => ltrim((string)($container['Name'] ?? ''), '/'),
                'state' => $container['State']['Status'] ?? 'unknown',
                'running' => (bool)($container['State']['Running'] ?? false),
                'networks' => $networks,
            ];
        }
        return $containers;
    }

    private function validIPv4($value): ?string
    {
        if (!is_string($value) || $value === '' || filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return null;
        }
        return $value;
    }
}
