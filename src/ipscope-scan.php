<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/ProcessRunner.php';
require_once __DIR__ . '/lib/DockerClient.php';
require_once __DIR__ . '/lib/NetworkCollector.php';
require_once __DIR__ . '/lib/ContainerCollector.php';
require_once __DIR__ . '/lib/TemplateParser.php';
require_once __DIR__ . '/lib/IPv4Calculator.php';
require_once __DIR__ . '/lib/AddressInventory.php';
require_once __DIR__ . '/lib/AddressExclusionStore.php';
require_once __DIR__ . '/lib/SnapshotBuilder.php';
require_once __DIR__ . '/lib/SnapshotStore.php';

use IPScope\DockerClient;
use IPScope\SnapshotBuilder;
use IPScope\SnapshotStore;

$options = getopt('', ['templates::', 'docker::', 'output::']);
$templateDirectory = $options['templates'] ?? '/boot/config/plugins/dockerMan/templates-user';
$dockerBinary = $options['docker'] ?? null;
$outputPath = $options['output'] ?? null;

try {
    $docker = new DockerClient(null, is_string($dockerBinary) ? $dockerBinary : null);
    $snapshot = (new SnapshotBuilder($docker, $templateDirectory))->build();

    if (is_string($outputPath) && $outputPath !== '') {
        (new SnapshotStore($outputPath))->write($snapshot);
        fwrite(STDOUT, 'Snapshot written to ' . $outputPath . PHP_EOL);
    } else {
        echo json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'IPScope scan failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
