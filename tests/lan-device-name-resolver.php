<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/lib/ProcessRunner.php';
require_once __DIR__ . '/../src/lib/LanDeviceNameResolver.php';

use IPScope\ProcessRunner;
use IPScope\LanDeviceNameResolver;

$runner = new class extends ProcessRunner {
    public array $calls = [];
    public function run(array $command, int $timeoutSeconds = 20): array {
        $this->calls[] = $command;
        if (($command[1] ?? '') === 'hosts' && ($command[2] ?? '') === '192.168.50.2') {
            return ['exit_code'=>0,'stdout'=>"192.168.50.2\tSynology\n",'stderr'=>''];
        }
        return ['exit_code'=>2,'stdout'=>'','stderr'=>'not found'];
    }
};
$resolver = new LanDeviceNameResolver($runner, '/bin/true', '/not-installed/avahi-resolve-address', '/not-installed/nmblookup');
$resolved = $resolver->resolve('192.168.50.2');
$checks = [
    $resolved['name'] === 'Synology',
    $resolved['name_source'] === 'reverse_dns',
    count($runner->calls) === 1,
];
$trailingR = $resolver->resolve('192.168.50.10');
$checks[] = $trailingR['name'] === 'printer';
$unknown = $resolver->resolve('192.168.50.99');
$checks[] = $unknown['name'] === null;
$checks[] = $unknown['name_source'] === null;
$checks[] = count($runner->calls) === 3;
$invalidRejected = false;
try { $resolver->resolve('not-an-ip'); } catch (InvalidArgumentException $error) { $invalidRejected = true; }
$checks[] = $invalidRejected;

foreach ($checks as $index=>$passed) {
    if (!$passed) {
        fwrite(STDERR, 'LAN device name resolver test failed at assertion ' . ($index+1) . PHP_EOL);
        exit(1);
    }
}
echo 'LAN device name resolver tests passed (' . count($checks) . ' assertions).' . PHP_EOL;
