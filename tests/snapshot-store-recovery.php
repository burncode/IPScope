<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/lib/SnapshotStore.php';

use IPScope\SnapshotStore;

function assertSnapshotRecovery(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
}

$directory = sys_get_temp_dir() . '/ipscope-snapshot-recovery-' . getmypid();
$path = $directory . '/snapshot.json';
$store = new SnapshotStore($path);

try {
    assertSnapshotRecovery($store->read() === null, 'missing snapshot returns null without warnings');

    $store->write(['schema_version' => 1, 'state' => 'healthy']);
    clearstatcache(true, $path);
    $permissions = fileperms($path);
    assertSnapshotRecovery($permissions !== false && (($permissions & 0777) === 0640), 'published snapshot has mode 0640');

    file_put_contents($path, '');
    assertSnapshotRecovery($store->read() === null, 'empty snapshot is treated as unavailable');

    file_put_contents($path, '{invalid-json');
    assertSnapshotRecovery($store->read() === null, 'corrupt JSON snapshot is treated as unavailable');

    $store->write(['schema_version' => 1, 'state' => 'recovered']);
    assertSnapshotRecovery($store->read() === ['schema_version' => 1, 'state' => 'recovered'], 'valid write recovers after an invalid snapshot');

    $files = glob($directory . '/*') ?: [];
    assertSnapshotRecovery(count($files) === 1 && basename($files[0]) === 'snapshot.json', 'recovery leaves no temporary files');

    echo "Snapshot store recovery tests passed (6 assertions)." . PHP_EOL;
} finally {
    @unlink($path);
    foreach (glob($directory . '/*') ?: [] as $file) @unlink($file);
    @rmdir($directory);
}
