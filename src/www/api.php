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
require_once __DIR__ . '/../lib/ArpProbeEngine.php';
require_once __DIR__ . '/../lib/LanScanPlanner.php';
require_once __DIR__ . '/../lib/LanScanEngine.php';
require_once __DIR__ . '/../lib/LanScanTaskStore.php';
require_once __DIR__ . '/../lib/LanScanResultStore.php';
require_once __DIR__ . '/../lib/LanDeviceNameResolver.php';
require_once __DIR__ . '/../lib/LanScanTaskRunner.php';


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
$allowedActions = ['snapshot', 'scan', 'exclusions', 'dhcp', 'recommendations', 'lanscan', 'laninterfaces'];
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
    if ($action === 'laninterfaces') {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
            header('Allow: GET');
            ipscopeRespond(405, ['ok'=>false, 'error'=>'Interface discovery supports GET only.']);
        }
        $data = (new \IPScope\LanInterfaceCollector(new \IPScope\ProcessRunner()))->collect([]);
        $interfaces = [];
        foreach ($data['interfaces'] as $name => $entries) {
            foreach ($entries as $entry) {
                if (empty($entry['up']) || $entry['prefix'] < 1 || $entry['prefix'] > 30) continue;
                $interfaces[] = [
                    'name'=>$name,
                    'ipv4'=>$entry['ipv4'],
                    'cidr'=>$entry['cidr'],
                    'prefix'=>$entry['prefix'],
                    'is_default_route'=>in_array($name, array_column($data['default_routes'], 'interface'), true),
                ];
            }
        }
        ipscopeRespond(200, ['ok'=>true, 'interfaces'=>$interfaces]);
    }

    if ($action === 'lanscan') {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $taskStore = new \IPScope\LanScanTaskStore('/var/local/emhttp/plugins/ipscope/scan-tasks');
        if ($method === 'GET') {
            $id = (string)($_GET['id'] ?? '');
            $task = $taskStore->get($id);
            if ($task === null) ipscopeRespond(404, ['ok'=>false, 'error'=>'Scan task not found.']);
            ipscopeRespond(200, ['ok'=>true, 'task'=>$task]);
        }
        if ($method !== 'POST') {
            header('Allow: GET, POST');
            ipscopeRespond(405, ['ok'=>false, 'error'=>'LAN scan tasks support GET and POST.']);
        }
        $raw = file_get_contents('php://input');
        if ($raw === false || strlen($raw) > 32768) ipscopeRespond(400, ['ok'=>false, 'error'=>'Invalid or oversized scan task request.']);
        try { $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
        catch (JsonException $error) { ipscopeRespond(400, ['ok'=>false, 'error'=>'Request body must be valid JSON.']); }
        if (!is_array($payload)) ipscopeRespond(400, ['ok'=>false, 'error'=>'Request body must be an object.']);
        $operation = (string)($payload['operation'] ?? 'create');
        if ($operation === 'cancel') {
            $task = $taskStore->cancel((string)($payload['id'] ?? ''));
            ipscopeRespond(200, ['ok'=>true, 'task'=>$task]);
        }
        if ($operation === 'step') {
            $runner = new \IPScope\LanScanTaskRunner(
                $taskStore,
                new \IPScope\LanScanEngine(new \IPScope\ArpProbeEngine(new \IPScope\ProcessRunner())),
                new \IPScope\LanDeviceNameResolver(new \IPScope\ProcessRunner()),
            );
            $task = $runner->step((string)($payload['id'] ?? ''));
            if (($task['status'] ?? '') === 'completed') {
                (new \IPScope\LanScanResultStore('/boot/config/plugins/ipscope/lan-scan-results.json'))->save($task);
            }
            ipscopeRespond(200, ['ok'=>true, 'task'=>$task]);
        }
        if ($operation !== 'create') ipscopeRespond(422, ['ok'=>false, 'error'=>'Unsupported scan task operation.']);
        $interface = (string)($payload['interface'] ?? '');
        $targets = $payload['targets'] ?? null;
        if (!is_array($targets) || count($targets) < 1 || count($targets) > 256) {
            ipscopeRespond(422, ['ok'=>false, 'error'=>'Provide between 1 and 256 IPv4 addresses or CIDR ranges.']);
        }
        $processRunner = new \IPScope\ProcessRunner();
        $interfaceData = (new \IPScope\LanInterfaceCollector($processRunner))->collect([$interface]);
        $interfaceAddresses = $interfaceData['interfaces'][$interface] ?? [];
        $active = array_values(array_filter($interfaceAddresses, static fn(array $entry): bool => !empty($entry['up']) && $entry['prefix'] >= 1 && $entry['prefix'] <= 30));
        if (!$active) ipscopeRespond(422, ['ok'=>false, 'error'=>'Selected interface is not an active IPv4 interface.']);
        $localIps = array_column($active, 'ipv4');
        foreach ($targets as $target) {
            if (!is_string($target)) ipscopeRespond(422, ['ok'=>false, 'error'=>'Targets must be IPv4 addresses or CIDR ranges.']);
            $target = trim($target);
            if (str_contains($target, '/')) {
                if (!preg_match('/^((?:\d{1,3}\.){3}\d{1,3})\/(\d|[12]\d|3[0-2])$/', $target, $matches)
                    || filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    ipscopeRespond(422, ['ok'=>false, 'error'=>'CIDR target must use valid IPv4/prefix notation.']);
                }
                $prefix = (int)$matches[2];
                $targetLong = (int)sprintf('%u', ip2long($matches[1]));
                $mask = $prefix === 0 ? 0 : ((0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF);
                $network = $targetLong & $mask;
                $broadcast = $network | ((~$mask) & 0xFFFFFFFF);
                $contained = false;
                foreach ($active as $entry) {
                    $interfacePrefix = (int)$entry['prefix'];
                    $interfaceIp = (int)sprintf('%u', ip2long($entry['ipv4']));
                    $interfaceMask = $interfacePrefix === 0 ? 0 : ((0xFFFFFFFF << (32 - $interfacePrefix)) & 0xFFFFFFFF);
                    $interfaceNetwork = $interfaceIp & $interfaceMask;
                    $interfaceBroadcast = $interfaceNetwork | ((~$interfaceMask) & 0xFFFFFFFF);
                    if ($prefix >= $interfacePrefix && $network >= $interfaceNetwork && $broadcast <= $interfaceBroadcast) {
                        $contained = true;
                        break;
                    }
                }
                if (!$contained) ipscopeRespond(422, ['ok'=>false, 'error'=>'CIDR ranges must be inside the selected interface subnet.']);
            } else {
                if (filter_var($target, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
                    ipscopeRespond(422, ['ok'=>false, 'error'=>'All scan targets must be valid IPv4 addresses or CIDR ranges.']);
                }
                $targetLong = (int)sprintf('%u', ip2long($target));
                $sameSubnet = false;
                foreach ($active as $entry) {
                    $prefix = (int)$entry['prefix'];
                    $mask = $prefix === 0 ? 0 : ((0xFFFFFFFF << (32 - $prefix)) & 0xFFFFFFFF);
                    $network = ((int)sprintf('%u', ip2long($entry['ipv4']))) & $mask;
                    if (($targetLong & $mask) === $network) { $sameSubnet = true; break; }
                }
                if (!$sameSubnet) ipscopeRespond(422, ['ok'=>false, 'error'=>'Scan targets must belong to the selected interface subnet.']);
            }
        }
        try {
            $plan = (new \IPScope\LanScanPlanner())->plan($interface, $targets, $localIps, 32);
            if ($plan['target_count'] < 1) ipscopeRespond(422, ['ok'=>false, 'error'=>'No scan targets remain after excluding local addresses.']);
            $task = $taskStore->create($plan);
            ipscopeRespond(201, ['ok'=>true, 'task'=>$task]);
        } catch (InvalidArgumentException $error) {
            ipscopeRespond(422, ['ok'=>false, 'error'=>$error->getMessage()]);
        }
    }

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
        $activeScan = (new \IPScope\LanScanResultStore('/boot/config/plugins/ipscope/lan-scan-results.json'))->load();
        if ($activeScan !== null) {
            $merged = (new \IPScope\LanAddressMerger())->merge(
                $snapshot['networks'] ?? [],
                $snapshot['lan_discovery'] ?? [],
                $snapshot['containers'] ?? [],
                $activeScan
            );
            $snapshot['networks'] = $merged['networks'];
            $snapshot['lan_discovery'] = $merged['lan_discovery'];
            $snapshot['active_lan_scan'] = [
                'interface'=>$activeScan['interface'],
                'scanned_at'=>$activeScan['scanned_at'],
                'target_count'=>$activeScan['target_count'],
                'availability_inferred'=>false,
            ];
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

        // Apply only recent explicit LAN scan evidence to recommendations.
        $activeScan = (new \IPScope\LanScanResultStore('/boot/config/plugins/ipscope/lan-scan-results.json'))->load();
        if ($activeScan !== null) {
            try {
                $scanAt = new \DateTimeImmutable((string)($activeScan['scanned_at'] ?? ''));
                $scanAge = time() - $scanAt->getTimestamp();
                if ($scanAge >= 0 && $scanAge <= 900) {
                    $merged = (new \IPScope\LanAddressMerger())->merge(
                        $snapshot['networks'] ?? [],
                        $snapshot['lan_discovery'] ?? [],
                        $snapshot['containers'] ?? [],
                        $activeScan
                    );
                    $snapshot['networks'] = $merged['networks'];
                    $snapshot['lan_discovery'] = $merged['lan_discovery'];
                }
            } catch (\Throwable $error) {
                // Invalid optional scan evidence is ignored; base evidence gates remain.
            }
        }

        $network = isset($_GET['network']) ? trim((string)$_GET['network']) : null;
        $rawLimit = $_GET['limit'] ?? '20';
        $limit = filter_var($rawLimit, FILTER_VALIDATE_INT);
        $rawOffset = $_GET['offset'] ?? '0';
        $offset = filter_var($rawOffset, FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 100) {
            ipscopeRespond(422, ['ok' => false, 'error' => 'Recommendation limit must be an integer between 1 and 100.']);
        }
        if ($offset === false || $offset < 0 || $offset > 10000) {
            ipscopeRespond(422, ['ok' => false, 'error' => 'Recommendation offset must be an integer between 0 and 10000.']);
        }

        try {
            $recommendations = (new AddressRecommendationEngine())->recommend($snapshot, $network, $limit, 900, $offset);
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
