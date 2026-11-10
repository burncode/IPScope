<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/lib/IPv4Calculator.php';
require_once __DIR__ . '/../src/lib/AddressRecommendationEngine.php';

use IPScope\AddressRecommendationEngine;

$snapshot = [
    'generated_at' => '2026-11-05T12:00:00+00:00',
    'dhcp' => ['mode'=>'disabled','pools'=>[],'reservations'=>[]],
    'networks' => [
        [
            'name' => 'bond0',
            'driver' => 'macvlan',
            'address_space_managed' => true,
            'ipv4' => [['subnet' => '192.168.50.0/24']],
            'known_addresses' => [
                ['ip' => '192.168.50.8', 'status' => 'available', 'available' => true],
                ['ip' => '192.168.50.9', 'status' => 'available', 'available' => true, 'lan_evidence' => [['type'=>'neighbor_table','status'=>'observed_reachable']]],
                ['ip' => '192.168.50.10', 'status' => 'available', 'available' => true, 'lan_evidence' => [['type'=>'neighbor_table','status'=>'neighbor_cached']]],
                ['ip' => '192.168.50.3', 'status' => 'available', 'available' => true],
                ['ip' => '192.168.50.1', 'status' => 'system', 'available' => false],
                ['ip' => '192.168.50.4', 'status' => 'occupied', 'available' => false],
                ['ip' => '192.168.50.5', 'status' => 'reserved', 'available' => false],
                ['ip' => '192.168.50.6', 'status' => 'conflict', 'available' => false],
                ['ip' => '192.168.50.7', 'status' => 'excluded', 'available' => false],
                // Malformed snapshots must not smuggle non-host/out-of-pool IPs into results.
                ['ip' => '192.168.50.0', 'status' => 'available', 'available' => true],
                ['ip' => '192.168.50.255', 'status' => 'available', 'available' => true],
                ['ip' => '192.168.51.10', 'status' => 'available', 'available' => true],
            ],
        ],
        [
            'name' => 'bridge',
            'driver' => 'bridge',
            'address_space_managed' => false,
            'ipv4' => [['subnet' => '172.17.0.0/16']],
            'known_addresses' => [
                ['ip' => '172.17.0.2', 'status' => 'available', 'available' => true],
            ],
        ],
        [
            // Missing managed flag must fail closed, not default to managed.
            'name' => 'unknown-pool',
            'driver' => 'macvlan',
            'ipv4' => [['subnet' => '10.0.0.0/24']],
            'known_addresses' => [
                ['ip' => '10.0.0.10', 'status' => 'available', 'available' => true],
            ],
        ],
        [
            'name' => 'malformed-collections',
            'driver' => 'macvlan',
            'address_space_managed' => true,
            'ipv4' => 'not-an-array',
            'known_addresses' => 'not-an-array',
        ],
        [
            'name' => 'invalid-pool',
            'driver' => 'macvlan',
            'address_space_managed' => true,
            'ipv4' => [['subnet' => 'not-a-cidr']],
            'known_addresses' => [
                ['ip' => '10.0.1.10', 'status' => 'available', 'available' => true],
            ],
        ],
    ],
];

$engine = new AddressRecommendationEngine();
$result = $engine->recommend($snapshot);
$bond0 = $result['networks'][0] ?? [];

$checks = [
    $result['candidate_count'] === 2,
    count($result['networks']) === 1,
    ($bond0['network'] ?? null) === 'bond0',
    ($bond0['candidates'][0]['ip'] ?? null) === '192.168.50.3',
    ($bond0['candidates'][1]['ip'] ?? null) === '192.168.50.8',
    ($bond0['candidate_count'] ?? null) === 2,
    str_contains($result['notice'], 'DHCP'),
    str_contains($result['notice'], 'explicitly managed'),
    !in_array('192.168.50.9', array_column($bond0['candidates'], 'ip'), true),
    !in_array('192.168.50.10', array_column($bond0['candidates'], 'ip'), true),
    ($bond0['candidates'][0]['reason'] ?? '') !== '',
    ($bond0['candidates'][0]['evidence']['inventory_status'] ?? '') === 'available',
    ($bond0['candidates'][0]['evidence']['subnet_membership'] ?? '') === 'verified',
    ($bond0['candidates'][0]['evidence']['known_claims'] ?? '') === 'none_recorded_by_inventory',
    ($result['snapshot_time_status'] ?? '') === 'valid',
    is_int($result['snapshot_age_seconds'] ?? null),
];

$filtered = $engine->recommend($snapshot, 'bond0', 1);
$checks[] = $filtered['candidate_count'] === 2;
$checks[] = count($filtered['networks'][0]['candidates'] ?? []) === 1;
$checks[] = ($filtered['networks'][0]['candidates'][0]['ip'] ?? null) === '192.168.50.3';

$unknownNetworkRejected = false;
try {
    $engine->recommend($snapshot, 'missing-network');
} catch (InvalidArgumentException $error) {
    $unknownNetworkRejected = true;
}
$checks[] = $unknownNetworkRejected;

$invalidLimitRejected = false;
try {
    $engine->recommend($snapshot, null, 101);
} catch (InvalidArgumentException $error) {
    $invalidLimitRejected = true;
}
$checks[] = $invalidLimitRejected;

$invalidTimeSnapshot = $snapshot;
$invalidTimeSnapshot['generated_at'] = 'not-a-timestamp';
$invalidTimeResult = $engine->recommend($invalidTimeSnapshot);
$checks[] = $invalidTimeResult['snapshot_time_status'] === 'invalid';
$checks[] = $invalidTimeResult['snapshot_age_seconds'] === null;

$missingTimeSnapshot = $snapshot;
unset($missingTimeSnapshot['generated_at']);
$missingTimeResult = $engine->recommend($missingTimeSnapshot);
$checks[] = $missingTimeResult['snapshot_time_status'] === 'missing';

$futureTimeSnapshot = $snapshot;
$futureTimeSnapshot['generated_at'] = '2099-01-01T00:00:00+00:00';
$futureTimeResult = $engine->recommend($futureTimeSnapshot);
$checks[] = $futureTimeResult['snapshot_time_status'] === 'future';
$checks[] = $futureTimeResult['snapshot_age_seconds'] === null;

foreach ($checks as $index => $passed) {
    if (!$passed) {
        fwrite(STDERR, 'Address recommendation test failed at assertion ' . ($index + 1) . PHP_EOL);
        exit(1);
    }
}

echo 'Address recommendation tests passed (' . count($checks) . ' assertions).' . PHP_EOL;
