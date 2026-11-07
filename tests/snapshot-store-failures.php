<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/lib/SnapshotStore.php';

use IPScope\SnapshotStore;

function assertSnapshotFailure(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
}

$directory = sys_get_temp_dir() . '/ipscope-snapshot-failure-' . getmypid();
$path = $directory . '/snapshot.json';
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Unable to create snapshot failure test directory.');
}

$store = new SnapshotStore($path);
$original = ['version' => 1, 'payload' => 'known-good'];
$temporaryPath = $path . '.tmp.' . getmypid();

try {
    $store->write($original);

    $encodingFailed = false;
    try {
        $store->write(['invalid' => INF]);
    } catch (JsonException $error) {
        $encodingFailed = true;
    }

    assertSnapshotFailure($encodingFailed, 'JSON encoding failure is surfaced to the caller');
    assertSnapshotFailure($store->read() === $original, 'JSON encoding failure leaves the previous snapshot intact');
    assertSnapshotFailure(!file_exists($temporaryPath), 'JSON encoding failure does not leave a temporary file');

    unlink($path);
    if (!mkdir($path, 0700)) {
        throw new RuntimeException('Unable to create destination directory for rename failure test.');
    }

    $renameFailed = false;
    try {
        $store->write(['version' => 2, 'payload' => 'cannot-publish']);
    } catch (RuntimeException $error) {
        $renameFailed = str_contains($error->getMessage(), 'Unable to publish snapshot file.');
    }

    assertSnapshotFailure($renameFailed, 'atomic publish failure is reported to the caller');
    assertSnapshotFailure(is_dir($path), 'failed publish does not replace the existing destination directory');
    assertSnapshotFailure(!file_exists($temporaryPath), 'failed publish removes its temporary file');

    echo "Snapshot store failure tests passed (6 assertions)." . PHP_EOL;
} finally {
    @unlink($temporaryPath);
    if (is_dir($path)) @rmdir($path);
    @unlink($path);
    @rmdir($directory);
}
