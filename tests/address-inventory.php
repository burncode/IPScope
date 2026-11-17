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
        'name' => 'gateway-collision', 'state' => 'running', 'running' => true,
        'networks' => ['bond0' => ['ipv4' => '192.168.50.1', 'ipam_ipv4' => null]],
    ],
    [
        'name' => 'auxiliary-collision', 'state' => 'running', 'running' => true,
        'networks' => ['bond0' => ['ipv4' => '192.168.50.21', 'ipam_ipv4' => null]],
    ],
    [
        'name' => 'network-address-collision', 'state' => 'running', 'running' => true,
        'networks' => ['bond0' => ['ipv4' => '192.168.50.0', 'ipam_ipv4' => null]],
    ],
    [
        'name' => 'broadcast-address-collision', 'state' => 'running', 'running' => true,
        'networks' => ['bond0' => ['ipv4' => '192.168.50.255', 'ipam_ipv4' => null]],
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
    [
        'name' => 'paused-worker', 'state' => 'paused', 'running' => false,
        'networks' => ['bond0' => ['ipv4' => '192.168.50.106', 'ipam_ipv4' => '192.168.50.106']],
    ],
    [
        'name' => 'stopped-ipam-only', 'state' => 'exited', 'running' => false,
        'networks' => ['bond0' => ['ipv4' => '192.168.50.107', 'ipam_ipv4' => '192.168.50.107']],
    ],
    [
        'name' => 'created-ipam-only', 'state' => 'created', 'running' => false,
        'networks' => ['bond0' => ['ipv4' => null, 'ipam_ipv4' => '192.168.50.108']],
    ],
    [
        'name' => 'stopped-template-and-ipam', 'state' => 'exited', 'running' => false,
        'networks' => ['bond0' => ['ipv4' => null, 'ipam_ipv4' => '192.168.50.110']],
    ],
    [
        'id' => 'container-id-duplicate-name',
        'name' => 'duplicate-id-test', 'state' => 'exited', 'running' => false,
        'networks' => ['bond0' => ['ipv4' => null, 'ipam_ipv4' => '192.168.50.109']],
    ],
];
$templates = [
    ['name' => 'plex', 'network' => 'bond0', 'ips' => ['192.168.50.100']],
    ['name' => 'duplicate', 'network' => 'bond0', 'ips' => ['192.168.50.100']],
    ['name' => 'mismatch', 'network' => 'bond0', 'ips' => ['192.168.50.103']],
    ['name' => 'saved-only-app', 'network' => 'bond0', 'ips' => ['192.168.50.105']],
    ['name' => 'stopped-reserver', 'network' => 'bond0', 'ips' => ['192.168.50.104']],
    ['name' => 'stopped-template-and-ipam', 'network' => 'bond0', 'ips' => ['192.168.50.110']],
    ['name' => 'duplicate-id-test', 'network' => 'bond0', 'ips' => ['192.168.50.109']],
];

$overlappingRules = [
    [
        'id' => 'exclude-pool',
        'enabled' => true,
        'network' => 'bond0',
        'match_type' => 'cidr',
        'value' => '192.168.50.0/25',
        'reason' => 'DHCP pool',
    ],
    [
        'id' => 'exclude-specific',
        'enabled' => true,
        'network' => 'bond0',
        'match_type' => 'ip',
        'value' => '192.168.50.100',
        'reason' => 'Manual reservation',
    ],
    [
        'id' => 'exclude-running',
        'enabled' => true,
        'network' => 'bond0',
        'match_type' => 'ip',
        'value' => '192.168.50.101',
        'reason' => 'Do not recommend',
    ],
];
$overlapResult = (new AddressInventory())->build($networks, $containers, $templates, $overlappingRules);
$overlapAddresses = [];
foreach ($overlapResult['networks'][0]['known_addresses'] as $address) {
    $overlapAddresses[$address['ip']] = $address;
}
$excludedInUseEvents = array_values(array_filter(
    $overlapResult['conflicts'],
    static fn(array $conflict): bool => ($conflict['type'] ?? '') === 'excluded_address_in_use'
));
$eventsByIp = [];
foreach ($excludedInUseEvents as $event) {
    $eventsByIp[$event['ip']][] = $event;
}

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
    ($addresses['192.168.50.1'] ?? null) === 'conflict',
    count(array_filter($result['conflicts'], static fn(array $c): bool => ($c['type'] ?? '') === 'system_address_collision' && ($c['ip'] ?? '') === '192.168.50.1')) === 1,
    (array_values(array_filter($result['conflicts'], static fn(array $c): bool => ($c['type'] ?? '') === 'system_address_collision' && ($c['ip'] ?? '') === '192.168.50.1'))[0]['system_owners'][0] ?? null) === 'system:gateway',
    // Auxiliary addresses are system-owned and must report a container collision.
    ($addresses['192.168.50.21'] ?? null) === 'conflict',
    count(array_filter($result['conflicts'], static fn(array $c): bool =>
        ($c['type'] ?? '') === 'system_address_collision' && ($c['ip'] ?? '') === '192.168.50.21'
    )) === 1,
    (array_values(array_filter($result['conflicts'], static fn(array $c): bool =>
        ($c['ip'] ?? '') === '192.168.50.21'
    ))[0]['system_owners'][0] ?? null) === 'system:auxiliary',
    // Subnet network/broadcast addresses must participate in collision classification.
    ($addresses['192.168.50.0'] ?? null) === 'conflict',
    ($addresses['192.168.50.255'] ?? null) === 'conflict',
    count(array_filter($result['conflicts'], static fn(array $c): bool =>
        ($c['type'] ?? '') === 'system_address_collision'
        && in_array(($c['ip'] ?? ''), ['192.168.50.0', '192.168.50.255'], true)
    )) === 2,
    (array_values(array_filter($result['conflicts'], static fn(array $c): bool =>
        ($c['ip'] ?? '') === '192.168.50.0'
    ))[0]['system_owners'][0] ?? null) === 'system:network_address',
    (array_values(array_filter($result['conflicts'], static fn(array $c): bool =>
        ($c['ip'] ?? '') === '192.168.50.255'
    ))[0]['system_owners'][0] ?? null) === 'system:broadcast_address',
    ($addresses['192.168.50.100'] ?? null) === 'conflict',
    count(array_filter($result['conflicts'], static fn(array $c): bool => $c['type'] === 'reservation_conflict')) === 1,
    count(array_filter($result['conflicts'], static fn(array $c): bool => $c['type'] === 'active_reservation_collision')) === 1,
    count(array_filter($result['conflicts'], static fn(array $c): bool => ($c['ip'] ?? '') === '192.168.50.106')) === 0,
    count(array_filter($result['conflicts'], static fn(array $c): bool => $c['type'] === 'template_runtime_mismatch')) === 1,
    ($addresses['192.168.50.105'] ?? null) === 'available',
    ($addresses['192.168.50.106'] ?? null) === 'occupied',
    ($addresses['192.168.50.107'] ?? null) === 'reserved',
    ($addresses['192.168.50.108'] ?? null) === 'reserved',
    ($addresses['192.168.50.109'] ?? null) === 'reserved',
    // Template and IPAM evidence for one stopped container remain one reservation owner.
    ($addresses['192.168.50.110'] ?? null) === 'reserved',
    count((array_values(array_filter($result['networks'][0]['known_addresses'], static fn(array $a): bool =>
        ($a['ip'] ?? '') === '192.168.50.110'
    ))[0]['owners'] ?? [])) === 2,
    count(array_filter($result['conflicts'], static fn(array $c): bool =>
        ($c['ip'] ?? '') === '192.168.50.110'
    )) === 0,
    count($result['orphan_templates']) === 1,
    // Overlapping rules must not turn an available address into an in-use conflict.
    ($overlapAddresses['192.168.50.102']['status'] ?? null) === 'excluded',
    count($overlapAddresses['192.168.50.102']['exclusions'] ?? []) === 1,
    count(array_filter($excludedInUseEvents, static fn(array $event): bool => $event['ip'] === '192.168.50.102')) === 0,
    // A reservation conflict keeps its original classification and gets one exclusion event.
    ($overlapAddresses['192.168.50.100']['status'] ?? null) === 'conflict',
    count($overlapAddresses['192.168.50.100']['exclusions'] ?? []) === 2,
    count($eventsByIp['192.168.50.100'] ?? []) === 1,
    ($eventsByIp['192.168.50.100'][0]['rule_count'] ?? 0) === 2,
    count($eventsByIp['192.168.50.100'][0]['rule_ids'] ?? []) === 2,
    // A runtime-occupied address remains occupied and emits one event for one rule.
    ($overlapAddresses['192.168.50.101']['status'] ?? null) === 'occupied',
    count($eventsByIp['192.168.50.101'] ?? []) === 1,
    ($eventsByIp['192.168.50.101'][0]['status'] ?? null) === 'occupied',
];

foreach ($checks as $index => $passed) {
    if (!$passed) {
        fwrite(STDERR, 'Address inventory test failed at assertion ' . ($index + 1) . PHP_EOL);
        exit(1);
    }
}

echo 'Address inventory tests passed (' . count($checks) . ' assertions).' . PHP_EOL;
