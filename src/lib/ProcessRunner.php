<?php
declare(strict_types=1);

namespace IPScope;

/**
 * Executes a fixed argument vector without invoking a shell.
 * Requires PHP 7.4+ (array command support in proc_open).
 */
final class ProcessRunner
{
    /** @return array{exit_code:int,stdout:string,stderr:string} */
    public function run(array $command, int $timeoutSeconds = 20): array
    {
        if ($command === [] || !is_string($command[0]) || $command[0] === '') {
            throw new \InvalidArgumentException('Command must contain an executable.');
        }

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process)) {
            throw new \RuntimeException('Unable to start process: ' . $command[0]);
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startedAt = microtime(true);
        $timedOut = false;
        $observedExitCode = null;

        do {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            $status = proc_get_status($process);

            if (!$status['running']) {
                $observedExitCode = is_int($status['exitcode']) ? $status['exitcode'] : null;
                break;
            }
            if ((microtime(true) - $startedAt) > $timeoutSeconds) {
                $timedOut = true;
                proc_terminate($process, 15);
                usleep(150000);
                $status = proc_get_status($process);
                if ($status['running']) {
                    proc_terminate($process, 9);
                }
                break;
            }
            usleep(20000);
        } while (true);

        $stdout .= stream_get_contents($pipes[1]) ?: '';
        $stderr .= stream_get_contents($pipes[2]) ?: '';
        fclose($pipes[1]);
        fclose($pipes[2]);

        $closeCode = proc_close($process);
        if ($timedOut) {
            throw new \RuntimeException('Command timed out after ' . $timeoutSeconds . ' seconds: ' . $command[0]);
        }

        return [
            'exit_code' => ($closeCode === -1 && $observedExitCode !== null) ? $observedExitCode : $closeCode,
            'stdout' => $stdout,
            'stderr' => $stderr,
        ];
    }
}
