<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/lib/ProcessRunner.php';
require_once __DIR__ . '/../src/lib/DockerClient.php';

use IPScope\DockerClient;
use IPScope\ProcessRunner;

function assertDockerClient(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL);
        exit(1);
    }
}

$directory = sys_get_temp_dir() . '/ipscope-docker-client-' . getmypid();
if (!mkdir($directory, 0700, true) && !is_dir($directory)) {
    throw new RuntimeException('Unable to create DockerClient test directory.');
}
$binary = $directory . '/docker';
$log = $directory . '/calls.log';
$script = <<<'SH'
#!/bin/sh
printf '%s\n' "$*" >> "$IPSCOPE_DOCKER_TEST_LOG"
if [ "$1" = "network" ] && [ "$2" = "ls" ]; then
  if [ "$IPSCOPE_DOCKER_TEST_MODE" = "empty" ]; then exit 0; fi
  printf 'bridge\nbond0\n'
  exit 0
fi
if [ "$1" = "network" ] && [ "$2" = "inspect" ]; then
  if [ "$IPSCOPE_DOCKER_TEST_MODE" = "fail" ]; then
    echo "network inspect failed" >&2
    exit 1
  fi
  shift 2
  printf '['
  separator=''
  for name in "$@"; do
    printf '%s{"Name":"%s"}' "$separator" "$name"
    separator=','
  done
  printf ']'
  exit 0
fi
echo "unexpected docker arguments" >&2
exit 2
SH;
if (file_put_contents($binary, $script) === false || !chmod($binary, 0700)) {
    throw new RuntimeException('Unable to create fake Docker executable.');
}

putenv('IPSCOPE_DOCKER_TEST_LOG=' . $log);
putenv('IPSCOPE_DOCKER_TEST_MODE=normal');
$client = new DockerClient(new ProcessRunner(), $binary);
$networks = $client->inspectNetworks();
assertDockerClient(count($networks) === 2, 'batch inspect returns both networks');
assertDockerClient(($networks[0]['Name'] ?? null) === 'bridge', 'first network data is preserved');
assertDockerClient(($networks[1]['Name'] ?? null) === 'bond0', 'second network data is preserved');
$calls = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
assertDockerClient(count($calls) === 2, 'multiple networks require one list and one inspect process');
assertDockerClient(($calls[1] ?? '') === 'network inspect bridge bond0', 'network inspect receives all names in one command');

file_put_contents($log, '');
putenv('IPSCOPE_DOCKER_TEST_MODE=empty');
assertDockerClient($client->inspectNetworks() === [], 'empty network list returns an empty array');
$calls = file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
assertDockerClient(count($calls) === 1, 'empty network list skips inspect process');

file_put_contents($log, '');
putenv('IPSCOPE_DOCKER_TEST_MODE=fail');
$failed = false;
try {
    $client->inspectNetworks();
} catch (RuntimeException $error) {
    $failed = str_contains($error->getMessage(), 'network inspect failed');
}
assertDockerClient($failed, 'batch inspect errors remain visible to callers');

@unlink($binary);
@unlink($log);
@rmdir($directory);

echo "Docker client tests passed (8 assertions)." . PHP_EOL;
