<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/lib/LanScanTaskStore.php';

use IPScope\LanScanTaskStore;

$dir = sys_get_temp_dir() . '/ipscope-scan-tasks-' . getmypid();
$store = new LanScanTaskStore($dir);
$plan = [
    'interface'=>'bond0',
    'target_count'=>3,
    'batches'=>[
        ['index'=>0,'targets'=>['192.168.50.2','192.168.50.3']],
        ['index'=>1,'targets'=>['192.168.50.4']],
    ],
];
$task = $store->create($plan);
$checks = [
    $task['status'] === 'queued',
    $task['target_count'] === 3,
    $task['progress_percent'] === 0,
    !array_key_exists('plan', $task),
];
$id = $task['id'];
$claimed = $store->claimNextBatch($id);
$checks[] = $claimed['status'] === 'claimed';
$checks[] = $claimed['batch']['targets'] === ['192.168.50.2','192.168.50.3'];
$checks[] = $claimed['task']['status'] === 'running';
$afterFirst = $store->appendBatchResults($id, [
    ['ip'=>'192.168.50.2','status'=>'responding','mac'=>'aa:bb:cc:dd:ee:02'],
    ['ip'=>'192.168.50.3','status'=>'no_response','mac'=>null],
]);
$checks[] = $afterFirst['completed_count'] === 2;
$checks[] = $afterFirst['progress_percent'] === 66;
$checks[] = $afterFirst['counts']['responding'] === 1;
$next = $store->claimNextBatch($id);
$checks[] = $next['batch']['targets'] === ['192.168.50.4'];
$done = $store->appendBatchResults($id, [
    ['ip'=>'192.168.50.4','status'=>'probe_error','mac'=>null],
]);
$checks[] = $done['status'] === 'completed';
$checks[] = $done['progress_percent'] === 100;
$checks[] = $done['counts']['probe_error'] === 1;
$checks[] = $store->claimNextBatch($id)['status'] === 'completed';

$cancelTask = $store->create($plan);
$cancelled = $store->cancel($cancelTask['id']);
$checks[] = $cancelled['status'] === 'cancelled';
$checks[] = $store->claimNextBatch($cancelTask['id'])['status'] === 'cancelled';
$checks[] = $store->get($cancelTask['id'])['cancel_requested'] === true;

$missing = $store->get(str_repeat('a', 24));
$checks[] = $missing === null;
$badIdRejected = false;
try { $store->get('../invalid'); } catch (InvalidArgumentException $error) { $badIdRejected = true; }
$checks[] = $badIdRejected;

foreach ($checks as $index=>$passed) {
    if (!$passed) {
        fwrite(STDERR, 'LAN scan task store test failed at assertion ' . ($index+1) . PHP_EOL);
        exit(1);
    }
}
foreach (glob($dir . '/*') ?: [] as $file) @unlink($file);
@rmdir($dir);
echo 'LAN scan task store tests passed (' . count($checks) . ' assertions).' . PHP_EOL;
