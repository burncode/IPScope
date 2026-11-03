<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/lib/IPv4Calculator.php';
require_once __DIR__ . '/../src/lib/AddressInventory.php';

use IPScope\AddressInventory;

$networks = [
    ['name' => 'bond0', 'ipv4' => [[
        'subnet' => '192.168.50.0/24',
        'gateway' => '192.168.50.1',
        'auxiliary_addresses' => ['server' => '192.168.50.21'],
    ]]],
    ['name' => 'bridge', 'ipv4' => [[
        'subnet' => '172.17.0.0/16',
        'gateway' => '172.17.0.1',
        'auxiliary_addresses' => [],
    ]]],
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
    [
        'name' => 'stopped-reserver', 'state' => 'exited', 'running' => false,
        'networks' => [],
    ],
];
$templates = [
    ['name' => 'plex', 'network' => 'bond0', 'ips' => ['192.168.50.100']],
    ['name' => 'duplicate', 'network' => 'bond0', 'ips' => ['192.168.50.100']],
    ['name' => 'mismatch', 'network' => 'bond0', 'ips' => ['192.168.50.103']],
    ['name' => 'saved-only-app', 'network' => 'bond0', 'ips' => ['192.168.50.105']],
    ['name' => 'stopped-reserver', 'network' => 'bond0', 'ips' => ['192.168.50.104']],
];

$result = (new AddressInventory())->build($networks, $containers, $templates);
$bridgeNetwork = $result['networks'][1];
$bridgeAddresses = $bridgeNetwork['known_addresses'] ?? [];
$bridgeCount = count($bridgeAddresses);

$addresses = [];
foreach ($result['networks'][0]['known_addresses'] as $address) {
    $addresses[$address['ip']] = $address['status'];
}

$checks = [
    ($addresses['192.168.50.100'] ?? null) === 'conflict',
    ($addresses['192.168.50.102'] ?? null) === 'available',
    $bridgeNetwork['address_space_managed'] === false,
    $bridgeCount === 1,
    ($bridgeAddresses[0]['ip'] ?? null) === '172.17.0.1',
    ($addresses['192.168.50.101'] ?? null) === 'occupied',
    ($addresses['192.168.50.103'] ?? null) === 'reserved',
    ($addresses['192.168.50.104'] ?? null) === 'conflict',
    ($addresses['192.168.50.1'] ?? null) === 'system',
    ($addresses['192.168.50.21'] ?? null) === 'system',
    ($addresses['192.168.50.0'] ?? null) === 'system',
    ($addresses['192.168.50.255'] ?? null) === 'system',
    ($addresses['192.168.50.100'] ?? null) === 'conflict',
    count(array_filter($result['conflicts'], static fn(array $c): bool => $c['type'] === 'reservation_conflict')) === 1,
    count(array_filter($result['conflicts'], static fn(array $c): bool => $c['type'] === 'active_reservation_collision')) === 1,
    count(array_filter($result['conflicts'], static fn(array $c): bool => $c['type'] === 'template_runtime_mismatch')) === 1,
    !isset($addresses['192.168.50.105']),
    count($result['orphan_templates']) === 1,
];

foreach ($checks as $index => $passed) {
    if (!$passed) {
        fwrite(STDERR, 'Address inventory test failed at assertion ' . ($index + 1) . PHP_EOL);
        exit(1);
    }
}

echo 'Address inventory tests passed (' . count($checks) . ' assertions).' . PHP_EOL;
