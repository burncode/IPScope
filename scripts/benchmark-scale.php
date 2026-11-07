<?php
declare(strict_types=1);

/**
 * IPScope synthetic scale benchmark.
 *
 * Runs the real collectors and inventory builder against an isolated fake
 * Docker CLI and generated Unraid-style XML templates. No real Docker state
 * is read or modified.
 */

$root = dirname(__DIR__);
$lib = $root . '/src/lib';

foreach ([
    'ProcessRunner.php',
    'DockerClient.php',
    'NetworkCollector.php',
    'ContainerCollector.php',
    'TemplateParser.php',
    'IPv4Calculator.php',
    'AddressExclusionStore.php',
    'AddressInventory.php',
    'SnapshotBuilder.php',
    'SnapshotStore.php',
] as $file) {
    require_once $lib . '/' . $file;
}

use IPScope\DockerClient;
use IPScope\ProcessRunner;
use IPScope\SnapshotBuilder;
use IPScope\SnapshotStore;

function benchmarkFail(string $message): void
{
    fwrite(STDERR, 'ERROR: ' . $message . PHP_EOL);
    exit(1);
}

function writeFakeDocker(string $path): void
{
    $source = <<<'PHP'
#!/usr/bin/env php
<?php
$args = array_slice($argv, 1);
$action = $args[0] ?? '';

if ($action === 'network' && ($args[1] ?? '') === 'ls') {
    $count = (int)(getenv('IPScope_FAKE_NETWORKS') ?: 0);
    for ($i = 0; $i < $count; $i++) {
        echo 'ipscope-net-' . $i . PHP_EOL;
    }
    exit(0);
}

if ($action === 'network' && ($args[1] ?? '') === 'inspect') {
    $networks = [];
    foreach (array_slice($args, 2) as $name) {
        if (!preg_match('/^ipscope-net-(\d+)$/', $name, $match)) {
            fwrite(STDERR, 'Unknown synthetic network: ' . $name);
            exit(2);
        }
        $index = (int)$match[1];
        $octet = ($index % 250) + 1;
        $networks[] = [
            'Id' => sprintf('%064x', $index + 1),
            'Name' => $name,
            'Driver' => 'bridge',
            'Scope' => 'local',
            'Internal' => false,
            'IPAM' => [
                'Config' => [[
                    'Subnet' => '10.' . $octet . '.0.0/16',
                    'Gateway' => '10.' . $octet . '.0.1',
                ]],
            ],
        ];
    }
    echo json_encode($networks, JSON_THROW_ON_ERROR);
    exit(0);
}

if ($action === 'container' && ($args[1] ?? '') === 'ls') {
    $count = (int)(getenv('IPScope_FAKE_CONTAINERS') ?: 0);
    for ($i = 0; $i < $count; $i++) {
        echo sprintf('synthetic-container-%06d', $i) . PHP_EOL;
    }
    exit(0);
}

if ($action === 'inspect' && ($args[1] ?? '') === '--type' && ($args[2] ?? '') === 'container') {
    $count = (int)(getenv('IPScope_FAKE_CONTAINERS') ?: 0);
    $networkCount = max(1, (int)(getenv('IPScope_FAKE_NETWORKS') ?: 1));
    $containers = [];

    foreach (array_slice($args, 3) as $id) {
        if (!preg_match('/^synthetic-container-(\d{6})$/', $id, $match)) {
            fwrite(STDERR, 'Unknown synthetic container ID');
            exit(2);
        }
        $index = (int)$match[1];
        if ($index >= $count) {
            fwrite(STDERR, 'Synthetic container index out of range');
            exit(2);
        }

        $networkIndex = $index % $networkCount;
        $octet = ($networkIndex % 250) + 1;
        $host = ($index % 250) + 2;
        $networkName = 'ipscope-net-' . $networkIndex;
        $containers[] = [
            'Id' => hash('sha256', $id),
            'Name' => '/' . $id,
            'State' => ['Status' => 'running', 'Running' => true],
            'NetworkSettings' => [
                'Networks' => [
                    $networkName => [
                        'IPAddress' => '10.' . $octet . '.' . intdiv($index, 250) . '.' . $host,
                        'IPAMConfig' => ['IPv4Address' => ''],
                        'MacAddress' => sprintf('02:00:%02x:%02x:%02x:%02x',
                            ($index >> 24) & 255, ($index >> 16) & 255,
                            ($index >> 8) & 255, $index & 255),
                    ],
                ],
            ],
        ];
    }

    echo json_encode($containers, JSON_THROW_ON_ERROR);
    exit(0);
}

fwrite(STDERR, 'Unsupported fake Docker arguments: ' . implode(' ', $args));
exit(2);
PHP;

    if (file_put_contents($path, $source) === false || !chmod($path, 0700)) {
        benchmarkFail('Unable to create fake Docker executable.');
    }
}

function createTemplates(string $directory, int $count, int $networkCount): void
{
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
        benchmarkFail('Unable to create synthetic template directory.');
    }

    for ($i = 0; $i < $count; $i++) {
        $networkIndex = $i % max(1, $networkCount);
        $octet = ($networkIndex % 250) + 1;
        $host = ($i % 250) + 2;
        $ip = '10.' . $octet . '.' . intdiv($i, 250) . '.' . $host;
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Container><Name>Synthetic Template ' . $i . '</Name>'
            . '<Network>ipscope-net-' . $networkIndex . '</Network>'
            . '<MyIP>' . $ip . '</MyIP>'
            . '<MyMAC></MyMAC></Container>';

        $file = $directory . '/template-' . sprintf('%05d', $i) . '.xml';
        if (file_put_contents($file, $xml) === false) {
            benchmarkFail('Unable to write synthetic template: ' . $file);
        }
    }
}

function removeTree(string $directory): void
{
    if (!is_dir($directory)) {
        return;
    }
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $path = $directory . '/' . $entry;
        if (is_dir($path)) removeTree($path);
        else @unlink($path);
    }
    @rmdir($directory);
}

$scales = [
    ['label' => 'baseline', 'networks' => 4, 'containers' => 38, 'templates' => 63],
    ['label' => 'medium', 'networks' => 10, 'containers' => 100, 'templates' => 200],
    ['label' => 'large', 'networks' => 20, 'containers' => 300, 'templates' => 500],
];

$workspace = sys_get_temp_dir() . '/ipscope-scale-benchmark-' . getmypid();
if (!mkdir($workspace, 0700, true) && !is_dir($workspace)) {
    benchmarkFail('Unable to create benchmark workspace.');
}

$fakeDocker = $workspace . '/docker';
writeFakeDocker($fakeDocker);

echo "IPScope synthetic scale benchmark" . PHP_EOL;
echo "Environment: isolated fake Docker CLI; real IPScope collectors and inventory" . PHP_EOL;
echo str_repeat('-', 78) . PHP_EOL;
printf("%-10s %8s %10s %10s %12s %12s %12s\n",
    'Scale', 'Networks', 'Containers', 'Templates', 'Elapsed ms', 'Peak MB', 'Snapshot KB');

try {
    foreach ($scales as $scale) {
        $templateDirectory = $workspace . '/templates-' . $scale['label'];
        $snapshotPath = $workspace . '/snapshot-' . $scale['label'] . '.json';
        $exclusionPath = $workspace . '/exclusions-' . $scale['label'] . '.json';

        createTemplates($templateDirectory, $scale['templates'], $scale['networks']);

        putenv('IPScope_FAKE_NETWORKS=' . $scale['networks']);
        putenv('IPScope_FAKE_CONTAINERS=' . $scale['containers']);

        $startedAt = microtime(true);
        if (function_exists('memory_reset_peak_usage')) {
            memory_reset_peak_usage();
        }

        $docker = new DockerClient(new ProcessRunner(), $fakeDocker);
        $snapshot = (new SnapshotBuilder($docker, $templateDirectory, $exclusionPath))->build();
        (new SnapshotStore($snapshotPath))->write($snapshot);

        $elapsedMs = (microtime(true) - $startedAt) * 1000;
        $peakMb = memory_get_peak_usage(true) / 1048576;
        $snapshotBytes = filesize($snapshotPath);
        $decoded = (new SnapshotStore($snapshotPath))->read();

        if (!is_array($decoded)) {
            benchmarkFail('Generated snapshot cannot be read for scale ' . $scale['label']);
        }

        $actualNetworks = count($decoded['networks'] ?? []);
        $actualContainers = count($decoded['containers'] ?? []);
        $actualTemplates = count($decoded['templates'] ?? []);

        if ($actualNetworks !== $scale['networks']
            || $actualContainers !== $scale['containers']
            || $actualTemplates !== $scale['templates']) {
            benchmarkFail('Count mismatch for scale ' . $scale['label']);
        }

        printf("%-10s %8d %10d %10d %12.1f %12.2f %12.1f\n",
            $scale['label'],
            $actualNetworks,
            $actualContainers,
            $actualTemplates,
            $elapsedMs,
            $peakMb,
            $snapshotBytes / 1024
        );
    }
} finally {
    putenv('IPScope_FAKE_NETWORKS');
    putenv('IPScope_FAKE_CONTAINERS');
    removeTree($workspace);
}

echo str_repeat('-', 78) . PHP_EOL;
echo "Benchmark completed. Synthetic files and fake Docker executable were removed." . PHP_EOL;
