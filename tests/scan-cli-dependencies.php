<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../src/ipscope-scan.php');
if (!is_string($source)) {
    fwrite(STDERR, "Could not read scan CLI entrypoint.\n");
    exit(1);
}

$exclusionRequire = "require_once __DIR__ . '/lib/AddressExclusionStore.php';";
$builderRequire = "require_once __DIR__ . '/lib/SnapshotBuilder.php';";

$checks = [
    str_contains($source, $exclusionRequire),
    str_contains($source, $builderRequire),
    strpos($source, $exclusionRequire) < strpos($source, $builderRequire),
];

foreach ($checks as $index => $passed) {
    if (!$passed) {
        fwrite(STDERR, 'Scan CLI dependency test failed at assertion ' . ($index + 1) . PHP_EOL);
        exit(1);
    }
}

echo 'Scan CLI dependency tests passed (' . count($checks) . " assertions).\n";
