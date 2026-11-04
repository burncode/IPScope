<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/ProcessRunner.php';
require_once __DIR__ . '/../lib/DockerClient.php';
require_once __DIR__ . '/../lib/NetworkCollector.php';
require_once __DIR__ . '/../lib/ContainerCollector.php';
require_once __DIR__ . '/../lib/TemplateParser.php';
require_once __DIR__ . '/../lib/IPv4Calculator.php';
require_once __DIR__ . '/../lib/AddressInventory.php';
require_once __DIR__ . '/../lib/AddressExclusionStore.php';
require_once __DIR__ . '/../lib/SnapshotBuilder.php';
require_once __DIR__ . '/../lib/SnapshotStore.php';

use IPScope\DockerClient;
use IPScope\SnapshotBuilder;
use IPScope\SnapshotStore;

// A full Docker inspection can take longer than the default web execution limit.
@set_time_limit(0);
ignore_user_abort(true);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

function ipscopeRespond(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$action = isset($_GET['action']) ? (string)$_GET['action'] : 'snapshot';
$allowedActions = ['snapshot', 'scan'];
if (!in_array($action, $allowedActions, true)) {
    ipscopeRespond(400, ['ok' => false, 'error' => 'Unsupported action.']);
}

$defaultStore = '/var/local/emhttp/plugins/ipscope/snapshot.json';
$storePath = getenv('IPSCOPE_SNAPSHOT_PATH') ?: $defaultStore;
$templateDirectory = getenv('IPSCOPE_TEMPLATE_DIR') ?: '/boot/config/plugins/dockerMan/templates-user';
$dockerBinary = getenv('IPSCOPE_DOCKER_BIN') ?: '/usr/bin/docker';

try {
    $store = new SnapshotStore($storePath);

    if ($action === 'snapshot') {
        $snapshot = $store->read();
        if ($snapshot === null) {
            ipscopeRespond(404, [
                'ok' => false,
                'error' => 'No snapshot is available. Run a scan first.',
            ]);
        }
        ipscopeRespond(200, ['ok' => true, 'snapshot' => $snapshot]);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        ipscopeRespond(405, ['ok' => false, 'error' => 'Scanning requires POST.']);
    }

    $docker = new DockerClient(null, $dockerBinary);

    $snapshot = (new SnapshotBuilder($docker, $templateDirectory))->build();

    $store->write($snapshot);

    ipscopeRespond(200, [
        'ok' => true,
        'snapshot' => $snapshot,
    ]);
} catch (Throwable $error) {
    ipscopeRespond(500, [
        'ok' => false,
        'error' => 'IPScope could not complete the request. Check the Unraid system log.',
    ]);
}
