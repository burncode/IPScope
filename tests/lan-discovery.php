<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/lib/ProcessRunner.php';
require_once __DIR__ . '/../src/lib/LanInterfaceCollector.php';
require_once __DIR__ . '/../src/lib/NeighborCollector.php';

use IPScope\ProcessRunner;
use IPScope\LanInterfaceCollector;
use IPScope\NeighborCollector;

$runner = new class extends ProcessRunner {
    public array $calls = [];
    public function run(array $command, int $timeoutSeconds = 20): array {
        $this->calls[] = $command;
        $joined = implode(' ', $command);
        if (str_contains($joined, '-4 -o addr show')) return ['exit_code'=>0,'stdout'=>"5: bond0    inet 192.168.50.21/24 brd 192.168.50.255 scope global bond0\n8: virbr0    inet 192.168.122.1/24 brd 192.168.122.255 scope global virbr0\n",'stderr'=>''];
        if (str_contains($joined, '-o link show')) return ['exit_code'=>0,'stdout'=>"5: bond0: <BROADCAST,MULTICAST,MASTER,UP,LOWER_UP> mtu 1500\n8: virbr0: <NO-CARRIER,BROADCAST,MULTICAST,UP> mtu 1500\n",'stderr'=>''];
        if (str_contains($joined, 'route show default')) return ['exit_code'=>0,'stdout'=>"default via 192.168.50.1 dev bond0 metric 1\n",'stderr'=>''];
        if (str_contains($joined, 'neigh show')) return ['exit_code'=>0,'stdout'=>"192.168.50.1 dev bond0 lladdr aa:bb:cc:dd:ee:ff REACHABLE\n192.168.50.1 dev vhost0 lladdr aa:bb:cc:dd:ee:ff STALE\n192.168.50.2 dev bond0 lladdr 11:22:33:44:55:66 STALE\n",'stderr'=>''];
        return ['exit_code'=>1,'stdout'=>'','stderr'=>'unexpected command'];
    }
};
$interfaces = (new LanInterfaceCollector($runner))->collect(['bond0']);
$neighbors = (new NeighborCollector($runner))->collect();
$merged = (new NeighborCollector($runner))->merge($neighbors);
$assertions = [
    count($interfaces['scan_targets']) === 1,
    $interfaces['scan_targets'][0]['cidr'] === '192.168.50.21/24',
    $interfaces['default_routes'][0]['gateway'] === '192.168.50.1',
    count($neighbors) === 3,
    count($merged) === 2,
    $merged[0]['observation'] === 'neighbor_cached',
    count($merged[0]['interfaces']) === 2,
    $merged[0]['preferred_interface'] === 'bond0',
];
foreach ($assertions as $i => $passed) {
    if (!$passed) { fwrite(STDERR, 'LAN discovery test failed at assertion '.($i+1).PHP_EOL); exit(1); }
}
echo 'LAN discovery tests passed ('.count($assertions).' assertions).'.PHP_EOL;
