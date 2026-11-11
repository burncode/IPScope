<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/lib/LanAddressMerger.php';

use IPScope\LanAddressMerger;

$networks = [[
    'name' => 'bond0',
    'known_addresses' => [
        ['ip'=>'192.168.50.1','status'=>'system','available'=>false,'owners'=>[]],
        ['ip'=>'192.168.50.15','status'=>'available','available'=>true,'owners'=>[]],
        ['ip'=>'192.168.50.21','status'=>'system','available'=>false,'owners'=>[]],
        ['ip'=>'192.168.50.222','status'=>'occupied','available'=>false,'owners'=>[
            ['container'=>'webui','source'=>'docker_runtime','kind'=>'occupied'],
        ]],
    ],
]];
$lan = [
    'status'=>'collected',
    'devices'=>[
        ['ip'=>'192.168.50.1','mac_addresses'=>['aa:bb:cc:dd:ee:01'],'interfaces'=>['bond0'],'states'=>['reachable'],'status'=>'observed_reachable'],
        ['ip'=>'192.168.50.15','mac_addresses'=>['aa:bb:cc:dd:ee:15'],'interfaces'=>['bond0'],'states'=>['reachable'],'status'=>'observed_reachable'],
        ['ip'=>'192.168.50.97','mac_addresses'=>['aa:bb:cc:dd:ee:97'],'interfaces'=>['bond0'],'states'=>['stale'],'status'=>'neighbor_cached'],
        ['ip'=>'192.168.50.222','mac_addresses'=>['aa:bb:cc:dd:ee:22'],'interfaces'=>['bond0'],'states'=>['reachable'],'status'=>'observed_reachable'],
    ],
    'local_addresses'=>[
        ['ip'=>'192.168.50.21','interface'=>'bond0','status'=>'local'],
    ],
];
$containers = [[
    'name'=>'webui',
    'networks'=>['bond0'=>['mac'=>'aa:bb:cc:dd:ee:99']],
]];
$result=(new LanAddressMerger())->merge($networks,$lan,$containers);
$addresses=[];
foreach($result['networks'][0]['known_addresses'] as $address) $addresses[$address['ip']]=$address;
$types=array_column($result['conflicts'],'type');
$checks=[
    ($addresses['192.168.50.1']['status']??null)==='system',
    ($addresses['192.168.50.15']['status']??null)==='available',
    ($addresses['192.168.50.15']['available']??null)===true,
    count($addresses['192.168.50.15']['lan_evidence']??[])===1,
    ($addresses['192.168.50.21']['lan_evidence'][0]['status']??null)==='local',
    in_array('192.168.50.97',array_column($result['lan_discovery']['unmatched_devices'],'ip'),true),
    ($result['lan_discovery']['matched_device_count']??null)===3,
    ($result['lan_discovery']['matched_inventory_address_count']??null)===3,
    ($result['lan_discovery']['matched_docker_claim_count']??null)===1,
    in_array('docker_lan_mac_mismatch',$types,true),
    ($result['lan_discovery']['availability_inferred']??null)===false,
    !($addresses['192.168.50.15']['active_scan_evidence']??[]),
];
foreach($checks as $i=>$passed) {
    if(!$passed) { fwrite(STDERR,'LAN address merger test failed at assertion '.($i+1).PHP_EOL); exit(1); }
}
echo 'LAN address merger tests passed ('.count($checks).' assertions).'.PHP_EOL;
