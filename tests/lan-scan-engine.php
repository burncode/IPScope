<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/lib/ProcessRunner.php';
require_once __DIR__ . '/../src/lib/ArpProbeEngine.php';
require_once __DIR__ . '/../src/lib/LanScanPlanner.php';
require_once __DIR__ . '/../src/lib/LanScanEngine.php';

use IPScope\ProcessRunner;
use IPScope\ArpProbeEngine;
use IPScope\LanScanPlanner;
use IPScope\LanScanEngine;

$planner = new LanScanPlanner();
$plan = $planner->plan('bond0', [
    '192.168.50.1','192.168.50.2','192.168.50.2','192.168.50.3',
], ['192.168.50.1'], 2);
$checks = [
    $plan['target_count'] === 2,
    $plan['batch_count'] === 1,
    $plan['batches'][0]['targets'] === ['192.168.50.2','192.168.50.3'],
];
$many = $planner->plan('bond0', array_map(static fn(int $n): string => '10.0.0.' . $n, range(1, 130)), [], 32);
$checks[] = $many['target_count'] === 130;
$checks[] = $many['batch_count'] === 5;
$checks[] = count($many['batches'][0]['targets']) === 32;
try { $planner->plan('bond0;bad', ['192.168.50.2']); $checks[] = false; }
catch (InvalidArgumentException $error) { $checks[] = true; }
try { $planner->plan('bond0', ['not-an-ip']); $checks[] = false; }
catch (InvalidArgumentException $error) { $checks[] = true; }
try { $planner->plan('bond0', array_fill(0, 257, '192.168.50.2')); $checks[] = false; }
catch (InvalidArgumentException $error) { $checks[] = true; }
try { $planner->plan('bond0', ['192.168.50.2'], [], 65); $checks[] = false; }
catch (InvalidArgumentException $error) { $checks[] = true; }

$runner = new class extends ProcessRunner {
    public array $calls = [];
    public function run(array $command, int $timeoutSeconds = 20): array {
        $this->calls[] = $command;
        $ip = (string)end($command);
        if ($ip === '192.168.50.2') {
            return ['exit_code'=>0,'stdout'=>'Unicast reply from 192.168.50.2 [aa:bb:cc:dd:ee:02] 1.0ms','stderr'=>''];
        }
        return ['exit_code'=>1,'stdout'=>'Sent 1 probes','stderr'=>'Received 0 response(s)'];
    }
};
$scan = (new LanScanEngine(new ArpProbeEngine($runner)))->execute($plan, 100);
$checks[] = $scan['status'] === 'completed';
$checks[] = $scan['completed_count'] === 2;
$checks[] = $scan['counts']['responding'] === 1;
$checks[] = $scan['counts']['no_response'] === 1;
$checks[] = $scan['availability_inferred'] === false;
$checks[] = $scan['results'][0]['status'] === 'responding';
$checks[] = $scan['results'][1]['status'] === 'no_response';
try { (new LanScanEngine(new ArpProbeEngine($runner)))->execute($plan, 50); $checks[] = false; }
catch (InvalidArgumentException $error) { $checks[] = true; }

foreach ($checks as $i=>$passed) {
    if (!$passed) {
        fwrite(STDERR, 'LAN scan engine test failed at assertion ' . ($i+1) . PHP_EOL);
        exit(1);
    }
}
echo 'LAN scan engine tests passed (' . count($checks) . ' assertions).' . PHP_EOL;
