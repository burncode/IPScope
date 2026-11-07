<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/lib/SnapshotStore.php';

use IPScope\SnapshotStore;

function assertSnapshotConcurrency(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
}

$directory = sys_get_temp_dir() . '/ipscope-snapshot-concurrency-' . getmypid();
$path = $directory . '/snapshot.json';
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Unable to create snapshot concurrency test directory.');
}

$worker = <<<'PHP'
require $argv[1];
$store = new \IPScope\SnapshotStore($argv[2]);
$writer = (int)$argv[3];
for ($i = 0; $i < 40; $i++) {
    $store->write([
        'writer' => $writer,
        'sequence' => $i,
        'payload' => str_repeat((string)$writer, 8192),
    ]);
    usleep(5000);
}
PHP;

$processes = [];
$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

try {
    foreach ([1, 2] as $writerId) {
        $process = proc_open(
            [PHP_BINARY, '-r', $worker, __DIR__ . '/../src/lib/SnapshotStore.php', $path, (string)$writerId],
            $descriptors,
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start concurrent snapshot writer.');
        }
        fclose($pipes[0]);
        $processes[] = ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
    }

    $store = new SnapshotStore($path);
    $reads = 0;
    $invalidReads = 0;
    do {
        $contents = @file_get_contents($path);
        if ($contents !== false) {
            $decoded = json_decode($contents, true);
            $reads++;
            if (!is_array($decoded) ||
                !isset($decoded['writer'], $decoded['sequence'], $decoded['payload']) ||
                strlen((string)$decoded['payload']) !== 8192) {
                $invalidReads++;
            }
        }

        $running = false;
        foreach ($processes as $entry) {
            $status = proc_get_status($entry['process']);
            $running = $running || $status['running'];
        }
        if ($running) {
            usleep(1000);
        }
    } while ($running);

    $exitCodes = [];
    foreach ($processes as $entry) {
        stream_get_contents($entry['stdout']);
        stream_get_contents($entry['stderr']);
        fclose($entry['stdout']);
        fclose($entry['stderr']);
        $exitCodes[] = proc_close($entry['process']);
    }

    $final = $store->read();
    assertSnapshotConcurrency($reads > 0, 'reader observed snapshots during concurrent writes');
    assertSnapshotConcurrency($invalidReads === 0, 'concurrent readers never observe partial or invalid JSON');
    assertSnapshotConcurrency($final !== null && in_array($final['writer'] ?? null, [1, 2], true), 'final snapshot is a complete writer payload');
    assertSnapshotConcurrency($final !== null && strlen((string)($final['payload'] ?? '')) === 8192, 'final snapshot payload is complete');
    assertSnapshotConcurrency(count(glob($directory . '/*') ?: []) === 1, 'concurrent writes leave no temporary files');

    echo "Snapshot store concurrency tests passed (5 assertions)." . PHP_EOL;
} finally {
    foreach ($processes as $entry) {
        if (is_resource($entry['process'])) {
            @proc_terminate($entry['process']);
            @proc_close($entry['process']);
        }
        if (is_resource($entry['stdout'])) @fclose($entry['stdout']);
        if (is_resource($entry['stderr'])) @fclose($entry['stderr']);
    }
    @unlink($path);
    foreach (glob($directory . '/*') ?: [] as $file) @unlink($file);
    @rmdir($directory);
}
