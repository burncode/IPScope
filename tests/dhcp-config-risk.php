<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/lib/IPv4Calculator.php';
require_once __DIR__ . '/../src/lib/DhcpConfigStore.php';
require_once __DIR__ . '/../src/lib/AddressRiskEngine.php';

use IPScope\DhcpConfigStore;
use IPScope\AddressRiskEngine;

$path = sys_get_temp_dir() . '/ipscope-dhcp-test-' . getmypid() . '.json';
@unlink($path);
$store = new DhcpConfigStore($path);
$default = $store->load();
$checks = [
    ($default['mode'] ?? '') === 'unknown',
    count($default['pools'] ?? []) === 0,
];
$config = $store->save([
    'mode'=>'configured',
    'pools'=>[
        ['network'=>'bond0','subnet'=>'192.168.50.0/24','start_ip'=>'192.168.50.100','end_ip'=>'192.168.50.254','source'=>'manual'],
    ],
    'reservations'=>[
        ['network'=>'bond0','ip'=>'192.168.50.15','label'=>'NAS'],
    ],
]);
$loaded = $store->load();
$checks[] = ($loaded['mode'] ?? '') === 'configured';
$checks[] = count($loaded['pools'] ?? []) === 1;
$checks[] = count($loaded['reservations'] ?? []) === 1;

$risk = new AddressRiskEngine();
$checks[] = ($risk->evaluate('192.168.50.120','bond0',$loaded)['code'] ?? '') === 'dhcp_dynamic_pool';
$checks[] = ($risk->evaluate('192.168.50.15','bond0',$loaded)['code'] ?? '') === 'dhcp_static_reservation';
$checks[] = ($risk->evaluate('192.168.50.20','bond0',$loaded)['recommendable'] ?? false) === true;
$checks[] = ($risk->evaluate('192.168.50.120','bridge',$loaded)['recommendable'] ?? false) === true;
$checks[] = ($risk->evaluate('192.168.50.120','bond0',['mode'=>'unknown'])['level'] ?? '') === 'review';
$checks[] = ($risk->evaluate('192.168.50.120','bond0',['mode'=>'disabled'])['recommendable'] ?? false) === true;

$invalidRejected = false;
try {
    $store->save(['mode'=>'configured','pools'=>[
        ['network'=>'bond0','subnet'=>'192.168.50.0/24','start_ip'=>'192.168.50.200','end_ip'=>'192.168.50.100'],
    ]]);
} catch (InvalidArgumentException $error) { $invalidRejected = true; }
$checks[] = $invalidRejected;

$outsideSubnetRejected = false;
try {
    $store->save(['mode'=>'configured','pools'=>[
        ['network'=>'bond0','subnet'=>'192.168.50.0/24','start_ip'=>'192.168.49.2','end_ip'=>'192.168.50.100'],
    ]]);
} catch (InvalidArgumentException $error) { $outsideSubnetRejected = true; }
$checks[] = $outsideSubnetRejected;

$modeRejected = false;
try { $store->save(['mode'=>'automatic']); }
catch (InvalidArgumentException $error) { $modeRejected = true; }
$checks[] = $modeRejected;

@unlink($path);
foreach ($checks as $index=>$passed) {
    if (!$passed) {
        fwrite(STDERR, 'DHCP configuration/risk test failed at assertion ' . ($index+1) . PHP_EOL);
        exit(1);
    }
}
echo 'DHCP configuration/risk tests passed (' . count($checks) . ' assertions).' . PHP_EOL;
