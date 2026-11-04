<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/lib/AddressRecommendationEngine.php';

use IPScope\AddressRecommendationEngine;

$snapshot = [
    'generated_at' => '2026-11-05T12:00:00+00:00',
    'networks' => [
        [
            'name' => 'bond0',
            'driver' => 'macvlan',
            'address_space_managed' => true,
            'ipv4' => [['subnet' => '192.168.50.0/24']],
            'known_addresses' => [
                ['ip' => '192.168.50.8', 'status' => 'available', 'available' => true],
                ['ip' => '192.168.50.3', 'status' => 'available', 'available' => true],
                ['ip' => '192.168.50.1', 'status' => 'system', 'available' => false],
                ['ip' => '192.168.50.4', 'status' => 'occupied', 'available' => false],
                ['ip' => '192.168.50.5', 'status' => 'reserved', 'available' => false],
                ['ip' => '192.168.50.6', 'status' => 'conflict', 'available' => false],
                ['ip' => '192.168.50.7', 'status' => 'excluded', 'available' => false],
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

foreach ($checks as $index => $passed) {
    if (!$passed) {
        fwrite(STDERR, 'Address recommendation test failed at assertion ' . ($index + 1) . PHP_EOL);
        exit(1);
    }
}

echo 'Address recommendation tests passed (' . count($checks) . ' assertions).' . PHP_EOL;
