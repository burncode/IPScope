<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/lib/SnapshotStore.php';

use IPScope\SnapshotStore;

$directory = sys_get_temp_dir() . '/ipscope-test-' . getmypid();
$path = $directory . '/snapshot.json';
$store = new SnapshotStore($path);
$payload = [
    'schema_version' => 1,
    'summary' => ['network_count' => 2, 'conflict_count' => 1],
];

try {
    $store->write($payload);
    $loaded = $store->read();

    if ($loaded !== $payload) {
        fwrite(STDERR, "Snapshot store round-trip failed.\n");
        exit(1);
    }

    $files = glob($directory . '/*') ?: [];
    if (count($files) !== 1 || basename($files[0]) !== 'snapshot.json') {
        fwrite(STDERR, "Snapshot store left unexpected temporary files.\n");
        exit(1);
    }

    echo "Snapshot store tests passed (2 assertions)." . PHP_EOL;
} finally {
    @unlink($path);
    @rmdir($directory);
}
