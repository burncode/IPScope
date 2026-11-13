<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/lib/LanScanPlanner.php';

use IPScope\LanScanPlanner;

$planner = new LanScanPlanner();
$plan = $planner->plan('bond0', ['192.168.50.0/24'], ['192.168.50.21'], 32);
$all = [];
foreach ($plan['batches'] as $batch) $all = array_merge($all, $batch['targets']);
$checks = [
    $plan['target_count'] === 253,
    $plan['batch_count'] === 8,
    $all[0] === '192.168.50.1',
    end($all) === '192.168.50.254',
    !in_array('192.168.50.0', $all, true),
    !in_array('192.168.50.255', $all, true),
    !in_array('192.168.50.21', $all, true),
];
$single = $planner->plan('bond0', ['192.168.50.10', '192.168.50.10', '192.168.50.11/32'], [], 32);
$checks[] = $single['target_count'] === 2;
$checks[] = $single['batches'][0]['targets'] === ['192.168.50.10', '192.168.50.11'];
$rejected = false;
try { $planner->plan('bond0', ['192.168.48.0/23'], [], 32); }
catch (InvalidArgumentException $error) { $rejected = true; }
$checks[] = $rejected;
foreach ($checks as $i => $ok) {
    if (!$ok) { fwrite(STDERR, 'LAN scan planner test failed at assertion ' . ($i + 1) . PHP_EOL); exit(1); }
}
echo 'LAN scan planner tests passed (' . count($checks) . " assertions)." . PHP_EOL;
