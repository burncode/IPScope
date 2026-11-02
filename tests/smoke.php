<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/lib/IPv4Calculator.php';

use IPScope\IPv4Calculator;

$calculator = new IPv4Calculator();
$range = $calculator->range('192.168.50.0/24');

$assertions = [
    $range['network'] === '192.168.50.0',
    $range['broadcast'] === '192.168.50.255',
    $range['first_host'] === '192.168.50.1',
    $range['last_host'] === '192.168.50.254',
    $range['total_hosts'] === 254,
    $calculator->contains('192.168.50.0/24', '192.168.50.100'),
    !$calculator->contains('192.168.50.0/24', '192.168.51.100'),
];

foreach ($assertions as $index => $passed) {
    if (!$passed) {
        fwrite(STDERR, 'Smoke test failed at assertion ' . ($index + 1) . PHP_EOL);
        exit(1);
    }
}

echo 'IPScope smoke tests passed (' . count($assertions) . ' assertions).' . PHP_EOL;
