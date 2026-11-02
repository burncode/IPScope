<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/lib/AddressInventory.php';

use IPScope\AddressInventory;

$networks = [
    ['name' => 'bond0', 'ipv4' => [['subnet' => '192.168.50.0/24']]],
];
$containers = [
    [
        'name' => 'plex', 'state' => 'exited', 'running' => false,
        'networks' => [],
    ],
    [
        'name' => 'jellyfin', 'state' => 'running', 'running' => true,
        'networks' => ['bond0' => ['ipv4' => '192.168.50.101', 'ipam_ipv4' => null]],
    ],
    [
        'name' => 'duplicate', 'state' => 'exited', 'running' => false,
        'networks' => [],
    ],
    [
        'name' => 'mismatch', 'state' => 'running', 'running' => true,
        'networks' => ['bond0' => ['ipv4' => '192.168.50.104', 'ipam_ipv4' => null]],
    ],
];
$templates = [
    ['name' => 'plex', 'network' => 'bond0', 'ips' => ['192.168.50.100']],
    ['name' => 'duplicate', 'network' => 'bond0', 'ips' => ['192.168.50.100']],
    ['name' => 'mismatch', 'network' => 'bond0', 'ips' => ['192.168.50.103']],
];

$result = (new AddressInventory())->build($networks, $containers, $templates);
$addresses = [];
foreach ($result['networks'][0]['known_addresses'] as $address) {
    $addresses[$address['ip']] = $address['status'];
}

$checks = [
    ($addresses['192.168.50.100'] ?? null) === 'conflict',
    ($addresses['192.168.50.101'] ?? null) === 'occupied',
    ($addresses['192.168.50.103'] ?? null) === 'reserved',
    ($addresses['192.168.50.104'] ?? null) === 'occupied',
    count(array_filter($result['conflicts'], static fn(array $c): bool => $c['type'] === 'duplicate_ip')) === 1,
    count(array_filter($result['conflicts'], static fn(array $c): bool => $c['type'] === 'template_runtime_mismatch')) === 1,
];

foreach ($checks as $index => $passed) {
    if (!$passed) {
        fwrite(STDERR, 'Address inventory test failed at assertion ' . ($index + 1) . PHP_EOL);
        exit(1);
    }
}

echo 'Address inventory tests passed (' . count($checks) . ' assertions).' . PHP_EOL;
