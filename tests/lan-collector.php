<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/lib/ProcessRunner.php';
require_once __DIR__ . '/../src/lib/LanInterfaceCollector.php';
require_once __DIR__ . '/../src/lib/NeighborCollector.php';
require_once __DIR__ . '/../src/lib/LanDiscoveryCollector.php';

use IPScope\ProcessRunner;
use IPScope\LanDiscoveryCollector;

$runner = new class extends ProcessRunner {
    public function run(array $command, int $timeoutSeconds = 20): array {
        $joined = implode(' ', $command);
        if (str_contains($joined, '-4 -o addr show')) return ['exit_code'=>0,'stdout'=>"5: bond0 inet 192.168.50.21/24 brd 192.168.50.255 scope global bond0\n",'stderr'=>''];
        if (str_contains($joined, '-o link show')) return ['exit_code'=>0,'stdout'=>"5: bond0: <BROADCAST,MULTICAST,MASTER,UP,LOWER_UP> mtu 1500\n",'stderr'=>''];
        if (str_contains($joined, 'route show default')) return ['exit_code'=>0,'stdout'=>"default via 192.168.50.1 dev bond0 metric 1\n",'stderr'=>''];
        if (str_contains($joined, 'neigh show')) return ['exit_code'=>0,'stdout'=>"192.168.50.21 dev bond0 lladdr 18:c0:4d:09:84:e9 REACHABLE\n192.168.50.1 dev bond0 lladdr aa:bb:cc:dd:ee:ff REACHABLE\n192.168.50.2 dev vhost0 lladdr 11:22:33:44:55:66 STALE\n",'stderr'=>''];
        return ['exit_code'=>1,'stdout'=>'','stderr'=>'unexpected command'];
    }
};
$data = (new LanDiscoveryCollector($runner))->collect();
$assertions = [
    $data['status'] === 'collected',
    count($data['local_addresses']) === 1,
    $data['local_addresses'][0]['ip'] === '192.168.50.21',
    count($data['devices']) === 2,
    $data['devices'][0]['status'] === 'observed_reachable',
    $data['devices'][1]['status'] === 'neighbor_cached',
    $data['active_probe_performed'] === false,
    $data['availability_inferred'] === false,
];
foreach ($assertions as $i => $passed) {
    if (!$passed) { fwrite(STDERR, 'LAN collector test failed at assertion '.($i+1).PHP_EOL); exit(1); }
}
echo 'LAN collector tests passed ('.count($assertions).' assertions).'.PHP_EOL;
