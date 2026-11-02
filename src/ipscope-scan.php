<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/ProcessRunner.php';
require_once __DIR__ . '/lib/DockerClient.php';
require_once __DIR__ . '/lib/NetworkCollector.php';
require_once __DIR__ . '/lib/ContainerCollector.php';
require_once __DIR__ . '/lib/TemplateParser.php';
require_once __DIR__ . '/lib/IPv4Calculator.php';
require_once __DIR__ . '/lib/AddressInventory.php';

use IPScope\DockerClient;
use IPScope\NetworkCollector;
use IPScope\ContainerCollector;
use IPScope\TemplateParser;

$options = getopt('', ['templates::', 'docker::']);
$templateDirectory = $options['templates'] ?? '/boot/config/plugins/dockerMan/templates-user';
$dockerBinary = $options['docker'] ?? null;

try {
    $docker = new DockerClient(null, is_string($dockerBinary) ? $dockerBinary : null);
    $networks = (new NetworkCollector($docker))->collect();
    $containers = (new ContainerCollector($docker))->collect();
    $templates = (new TemplateParser())->collect($templateDirectory);
    $inventory = (new AddressInventory())->build($networks, $containers, $templates);

    $snapshot = [
        'schema_version' => 1,
        'generated_at' => date(DATE_ATOM),
        'source' => [
            'docker' => 'docker-cli',
            'templates' => $templateDirectory,
        ],
        'networks' => $inventory['networks'],
        'containers' => $containers,
        'templates' => $templates,
        'conflicts' => $inventory['conflicts'],
    ];

    echo json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'IPScope scan failed: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
