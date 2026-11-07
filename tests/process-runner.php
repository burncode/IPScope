<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/lib/ProcessRunner.php';

use IPScope\ProcessRunner;

function assertProcessRunner(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
}

$runner = new ProcessRunner();

$result = $runner->run([PHP_BINARY, '-r', 'echo "stdout-ok";']);
assertProcessRunner($result['exit_code'] === 0, 'normal process returns exit code zero');
assertProcessRunner($result['stdout'] === 'stdout-ok', 'normal process captures stdout');

$result = $runner->run([PHP_BINARY, '-r', 'fwrite(STDERR, "stderr-ok");']);
assertProcessRunner($result['exit_code'] === 0, 'stderr process returns exit code zero');
assertProcessRunner($result['stderr'] === 'stderr-ok', 'stderr is captured independently');

$result = $runner->run([PHP_BINARY, '-r', 'fwrite(STDOUT, "out"); fwrite(STDERR, "err"); exit(7);']);
assertProcessRunner($result['exit_code'] === 7, 'non-zero exit code is preserved');
assertProcessRunner($result['stdout'] === 'out' && $result['stderr'] === 'err', 'stdout and stderr are both captured');

$largeOutput = '$chunk = str_repeat("x", 8192); for ($i = 0; $i < 32; $i++) { echo $chunk; fwrite(STDERR, $chunk); }';
$result = $runner->run([PHP_BINARY, '-r', $largeOutput]);
assertProcessRunner(strlen($result['stdout']) === 262144, 'large stdout is fully captured without blocking');
assertProcessRunner(strlen($result['stderr']) === 262144, 'large stderr is fully captured without blocking');

$startedAt = microtime(true);
$timedOut = false;
try {
    $runner->run([PHP_BINARY, '-r', 'sleep(3);'], 1);
} catch (RuntimeException $error) {
    $timedOut = str_contains($error->getMessage(), 'Command timed out after 1 seconds');
}
$elapsed = microtime(true) - $startedAt;
assertProcessRunner($timedOut, 'long-running process raises a timeout exception');
assertProcessRunner($elapsed < 2.5, 'timed-out process is terminated promptly');

$invalidRejected = false;
try {
    $runner->run([]);
} catch (InvalidArgumentException $error) {
    $invalidRejected = str_contains($error->getMessage(), 'Command must contain an executable.');
}
assertProcessRunner($invalidRejected, 'empty command is rejected before process launch');

echo "Process runner tests passed (12 assertions)." . PHP_EOL;
