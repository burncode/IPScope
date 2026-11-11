<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/lib/LanDeviceIdentityCache.php';

use IPScope\LanDeviceIdentityCache;

$directory = sys_get_temp_dir() . '/ipscope-identity-cache-' . bin2hex(random_bytes(5));
$path = $directory . '/identity.json';
$now = 1000000;
$clock = static function () use (&$now): int { return $now; };
$cache = new LanDeviceIdentityCache($path, 600, $clock, 2);
$checks = [];

$checks[] = $cache->get('192.168.50.2', 'aa:bb:cc:dd:ee:02') === null;
$cache->put('192.168.50.2', 'AA:BB:CC:DD:EE:02', 'burncode-nas', 'mdns');
$hit = $cache->get('192.168.50.2', 'aa:bb:cc:dd:ee:02');
$checks[] = is_array($hit) && $hit['name'] === 'burncode-nas';
$checks[] = is_array($hit) && $hit['name_source'] === 'mdns' && $hit['cached'] === true;
$checks[] = $cache->get('192.168.50.3', 'aa:bb:cc:dd:ee:02') === null;
$checks[] = $cache->get('192.168.50.2', 'aa:bb:cc:dd:ee:03') === null;

$now += 601;
$checks[] = $cache->get('192.168.50.2', 'aa:bb:cc:dd:ee:02') === null;

$cache->put('192.168.50.4', 'aa:bb:cc:dd:ee:04', 'printer', 'reverse_dns');
$checks[] = $cache->get('192.168.50.4', 'aa:bb:cc:dd:ee:04') !== null;
$cache->put('192.168.50.5', 'aa:bb:cc:dd:ee:05', 'bad name', 'mdns');
$checks[] = $cache->get('192.168.50.5', 'aa:bb:cc:dd:ee:05') === null;
$cache->put('192.168.50.6', 'not-a-mac', 'invalid', 'mdns');
$checks[] = is_file($path);

try {
    new LanDeviceIdentityCache($path, 10, $clock);
    $checks[] = false;
} catch (InvalidArgumentException $error) {
    $checks[] = true;
}

@unlink($path);
@unlink($path . '.lock');
@rmdir($directory);

foreach ($checks as $index => $passed) {
    if (!$passed) {
        fwrite(STDERR, 'LAN device identity cache test failed at assertion ' . ($index + 1) . PHP_EOL);
        exit(1);
    }
}
echo 'LAN device identity cache tests passed (' . count($checks) . ' assertions).' . PHP_EOL;
