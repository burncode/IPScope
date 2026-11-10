<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/ProcessRunner.php';
require_once __DIR__ . '/../lib/LanInterfaceCollector.php';
require_once __DIR__ . '/../lib/NeighborCollector.php';
require_once __DIR__ . '/../lib/LanDiscoveryCollector.php';
require_once __DIR__ . '/../lib/LanAddressMerger.php';
require_once __DIR__ . '/../lib/DockerClient.php';
require_once __DIR__ . '/../lib/NetworkCollector.php';
require_once __DIR__ . '/../lib/ContainerCollector.php';
require_once __DIR__ . '/../lib/TemplateParser.php';
require_once __DIR__ . '/../lib/IPv4Calculator.php';
require_once __DIR__ . '/../lib/AddressInventory.php';
require_once __DIR__ . '/../lib/AddressExclusionStore.php';
require_once __DIR__ . '/../lib/AddressRiskEngine.php';
require_once __DIR__ . '/../lib/DhcpConfigStore.php';
require_once __DIR__ . '/../lib/AddressRecommendationEngine.php';
require_once __DIR__ . '/../lib/SnapshotBuilder.php';
require_once __DIR__ . '/../lib/SnapshotStore.php';

use IPScope\DockerClient;
use IPScope\SnapshotBuilder;
use IPScope\SnapshotStore;
use IPScope\AddressRecommendationEngine;

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
$allowedActions = ['snapshot', 'scan', 'exclusions', 'dhcp', 'recommendations'];
if (!in_array($action, $allowedActions, true)) {
    ipscopeRespond(400, ['ok' => false, 'error' => 'Unsupported action.']);
}

$defaultStore = '/var/local/emhttp/plugins/ipscope/snapshot.json';
$exclusionPath = '/boot/config/plugins/ipscope/exclusions.json';
$dhcpPath = '/boot/config/plugins/ipscope/dhcp.json';
$storePath = getenv('IPSCOPE_SNAPSHOT_PATH') ?: $defaultStore;
$templateDirectory = getenv('IPSCOPE_TEMPLATE_DIR') ?: '/boot/config/plugins/dockerMan/templates-user';
$dockerBinary = getenv('IPSCOPE_DOCKER_BIN') ?: '/usr/bin/docker';

try {
    if ($action === 'dhcp') {
        $dhcpStore = new \IPScope\DhcpConfigStore($dhcpPath);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'GET') ipscopeRespond(200, ['ok'=>true, 'dhcp'=>$dhcpStore->load()]);
        if ($method !== 'POST') {
            header('Allow: GET, POST');
            ipscopeRespond(405, ['ok'=>false, 'error'=>'DHCP configuration supports GET and POST.']);
        }
        $raw = file_get_contents('php://input');
        if ($raw === false || strlen($raw) > 65536) ipscopeRespond(400, ['ok'=>false, 'error'=>'Invalid or oversized DHCP configuration request.']);
        try { $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException $error) { ipscopeRespond(400, ['ok'=>false, 'error'=>'Request body must be valid JSON.']); }
        if (!is_array($payload)) ipscopeRespond(400, ['ok'=>false, 'error'=>'Request body must be a DHCP configuration object.']);
        try {
            $dhcp = $dhcpStore->save($payload);
            ipscopeRespond(200, ['ok'=>true, 'dhcp'=>$dhcp]);
        } catch (InvalidArgumentException $error) {
            ipscopeRespond(422, ['ok'=>false, 'error'=>$error->getMessage()]);
        }
    }

    if ($action === 'exclusions') {
        $exclusionStore = new \IPScope\AddressExclusionStore($exclusionPath);
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'GET') {
            ipscopeRespond(200, ['ok' => true, 'rules' => $exclusionStore->load()]);
        }
        if ($method !== 'POST') {
            header('Allow: GET, POST');
            ipscopeRespond(405, ['ok' => false, 'error' => 'Exclusion rules support GET and POST.']);
        }

        $raw = file_get_contents('php://input');
        if ($raw === false || strlen($raw) > 65536) {
            ipscopeRespond(400, ['ok' => false, 'error' => 'Invalid or oversized exclusion request.']);
        }
        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            ipscopeRespond(400, ['ok' => false, 'error' => 'Request body must be valid JSON.']);
        }
        if (!is_array($payload) || !is_array($payload['rules'] ?? null)) {
            ipscopeRespond(400, ['ok' => false, 'error' => 'Request must contain a rules array.']);
        }
        try {
            $rules = $exclusionStore->save($payload['rules']);
            ipscopeRespond(200, ['ok' => true, 'rules' => $rules]);
        } catch (InvalidArgumentException $error) {
            ipscopeRespond(422, ['ok' => false, 'error' => $error->getMessage()]);
        }
    }

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

    if ($action === 'recommendations') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            header('Allow: GET');
            ipscopeRespond(405, ['ok' => false, 'error' => 'Recommendations support GET only.']);
        }

        $snapshot = $store->read();
        if ($snapshot === null) {
            ipscopeRespond(404, ['ok' => false, 'error' => 'No snapshot is available. Run a scan first.']);
        }

        $snapshot['dhcp'] = (new \IPScope\DhcpConfigStore($dhcpPath))->load();

        $network = isset($_GET['network']) ? trim((string)$_GET['network']) : null;
        $rawLimit = $_GET['limit'] ?? '20';
        $limit = filter_var($rawLimit, FILTER_VALIDATE_INT);
        if ($limit === false) {
            ipscopeRespond(422, ['ok' => false, 'error' => 'Recommendation limit must be an integer between 1 and 100.']);
        }

        try {
            $recommendations = (new AddressRecommendationEngine())->recommend($snapshot, $network, $limit);
            ipscopeRespond(200, ['ok' => true, 'recommendations' => $recommendations]);
        } catch (InvalidArgumentException $error) {
            ipscopeRespond(422, ['ok' => false, 'error' => $error->getMessage()]);
        }
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
