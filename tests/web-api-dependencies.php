<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../src/www/api.php');
if (!is_string($source)) {
    fwrite(STDERR, "Could not read Web API entrypoint.\n");
    exit(1);
}
$required = [
    "require_once __DIR__ . '/../lib/ProcessRunner.php';",
    "require_once __DIR__ . '/../lib/LanInterfaceCollector.php';",
    "require_once __DIR__ . '/../lib/NeighborCollector.php';",
    "require_once __DIR__ . '/../lib/LanDiscoveryCollector.php';",
    "require_once __DIR__ . '/../lib/AddressRiskEngine.php';",
    "require_once __DIR__ . '/../lib/DhcpConfigStore.php';",
    "require_once __DIR__ . '/../lib/SnapshotBuilder.php';",
    "require_once __DIR__ . '/../lib/LanScanTaskStore.php';",
    "require_once __DIR__ . '/../lib/LanScanTaskRunner.php';",
    "\$allowedActions = ['snapshot', 'scan', 'exclusions', 'dhcp', 'recommendations', 'lanscan'];",
    "if (\$action === 'lanscan')",
    "if (\$operation === 'cancel')",
    "if (\$operation === 'step')",
];
$last = -1;
foreach ($required as $index => $line) {
    $position = strpos($source, $line);
    if ($position === false || $position <= $last) {
        fwrite(STDERR, 'Web API dependency test failed at assertion ' . ($index + 1) . PHP_EOL);
        exit(1);
    }
    $last = $position;
}
echo 'Web API dependency tests passed (' . count($required) . " assertions).\n";
