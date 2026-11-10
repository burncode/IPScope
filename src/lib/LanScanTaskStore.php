<?php
declare(strict_types=1);

namespace IPScope;

/**
 * Small file-backed task store for cooperative, one-batch-per-request scans.
 * All state transitions are serialized with flock and published atomically.
 */
final class LanScanTaskStore
{
    private string $directory;

    public function __construct(string $directory)
    {
        $this->directory = rtrim($directory, '/');
    }

    public function create(array $plan): array
    {
        $this->ensureDirectory();
        $id = bin2hex(random_bytes(12));
        $task = [
            'schema_version'=>1,
            'id'=>$id,
            'status'=>'queued',
            'interface'=>(string)$plan['interface'],
            'target_count'=>(int)$plan['target_count'],
            'batch_count'=>count($plan['batches']),
            'next_batch_index'=>0,
            'completed_batches'=>0,
            'completed_count'=>0,
            'counts'=>['responding'=>0,'no_response'=>0,'probe_error'=>0,'response_unparsed'=>0],
            'results'=>[],
            'plan'=>$plan,
            'cancel_requested'=>false,
            'created_at'=>date(DATE_ATOM),
            'updated_at'=>date(DATE_ATOM),
            'completed_at'=>null,
            'error'=>null,
        ];
        $this->withLock($id, function () use ($id, $task): void { $this->writeUnlocked($id, $task); });
        return $this->publicTask($task);
    }

    public function get(string $id): ?array
    {
        $this->assertId($id);
        return $this->withLock($id, function () use ($id): ?array {
            $task = $this->readUnlocked($id);
            return $task === null ? null : $this->publicTask($task);
        });
    }

    /** Atomically reserve exactly one batch for a worker. */
    public function claimNextBatch(string $id): array
    {
        $this->assertId($id);
        return $this->withLock($id, function () use ($id): array {
            $task = $this->readUnlocked($id);
            if ($task === null) throw new \RuntimeException('Scan task not found.');
            if ($task['cancel_requested'] || $task['status'] === 'cancelled') {
                $task['status'] = 'cancelled';
                $task['completed_at'] = date(DATE_ATOM);
                $this->writeUnlocked($id, $task);
                return ['status'=>'cancelled','task'=>$this->publicTask($task)];
            }
            if (in_array($task['status'], ['completed','failed'], true)) {
                return ['status'=>$task['status'],'task'=>$this->publicTask($task)];
            }
            $index = (int)$task['next_batch_index'];
            $batches = $task['plan']['batches'] ?? [];
            if (!isset($batches[$index])) {
                $task['status'] = 'completed';
                $task['completed_at'] = date(DATE_ATOM);
                $task['updated_at'] = date(DATE_ATOM);
                $this->writeUnlocked($id, $task);
                return ['status'=>'completed','task'=>$this->publicTask($task)];
            }
            $task['next_batch_index'] = $index + 1;
            $task['status'] = 'running';
            $task['updated_at'] = date(DATE_ATOM);
            $this->writeUnlocked($id, $task);
            return ['status'=>'claimed','batch'=>$batches[$index],'task'=>$this->publicTask($task)];
        });
    }

    public function appendBatchResults(string $id, array $results): array
    {
        $this->assertId($id);
        return $this->withLock($id, function () use ($id, $results): array {
            $task = $this->readUnlocked($id);
            if ($task === null) throw new \RuntimeException('Scan task not found.');
            if ($task['status'] === 'cancelled' || $task['cancel_requested']) {
                $task['status'] = 'cancelled';
                $task['completed_at'] = date(DATE_ATOM);
                $this->writeUnlocked($id, $task);
                return $this->publicTask($task);
            }
            foreach ($results as $result) {
                if (!is_array($result) || !isset($result['ip'], $result['status'])) {
                    throw new \InvalidArgumentException('Invalid scan result.');
                }
                $task['results'][] = $result;
                $task['completed_count']++;
                $status = (string)$result['status'];
                if (!isset($task['counts'][$status])) $task['counts'][$status] = 0;
                $task['counts'][$status]++;
            }
            $task['completed_batches']++;
            $task['updated_at'] = date(DATE_ATOM);
            if ($task['completed_batches'] >= $task['batch_count']) {
                $task['status'] = 'completed';
                $task['completed_at'] = date(DATE_ATOM);
            }
            $this->writeUnlocked($id, $task);
            return $this->publicTask($task);
        });
    }

    public function fail(string $id, string $message): array
    {
        $this->assertId($id);
        return $this->withLock($id, function () use ($id, $message): array {
            $task = $this->readUnlocked($id);
            if ($task === null) throw new \\RuntimeException('Scan task not found.');
            if (!in_array($task['status'], ['completed','cancelled'], true)) {
                $task['status'] = 'failed';
                $task['error'] = $message;
                $task['completed_at'] = date(DATE_ATOM);
                $task['updated_at'] = date(DATE_ATOM);
                $this->writeUnlocked($id, $task);
            }
            return $this->publicTask($task);
        });
    }

    public function cancel(string $id): array
    {
        $this->assertId($id);
        return $this->withLock($id, function () use ($id): array {
            $task = $this->readUnlocked($id);
            if ($task === null) throw new \RuntimeException('Scan task not found.');
            if (!in_array($task['status'], ['completed','failed','cancelled'], true)) {
                $task['cancel_requested'] = true;
                $task['status'] = 'cancelled';
                $task['completed_at'] = date(DATE_ATOM);
                $task['updated_at'] = date(DATE_ATOM);
                $this->writeUnlocked($id, $task);
            }
            return $this->publicTask($task);
        });
    }

    private function publicTask(array $task): array
    {
        unset($task['plan']);
        $task['progress_percent'] = $task['target_count'] > 0
            ? (int)floor(($task['completed_count'] / $task['target_count']) * 100)
            : 100;
        $task['availability_inferred'] = false;
        return $task;
    }

    private function assertId(string $id): void
    {
        if (!preg_match('/^[a-f0-9]{24}$/', $id)) throw new \InvalidArgumentException('Invalid scan task ID.');
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0750, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('Unable to create scan task directory.');
        }
    }

    private function withLock(string $id, callable $callback)
    {
        $this->ensureDirectory();
        $handle = fopen($this->directory . '/' . $id . '.lock', 'c');
        if ($handle === false) throw new \RuntimeException('Unable to open scan task lock.');
        try {
            if (!flock($handle, LOCK_EX)) throw new \RuntimeException('Unable to lock scan task.');
            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function readUnlocked(string $id): ?array
    {
        $path = $this->directory . '/' . $id . '.json';
        if (!is_file($path)) return null;
        $raw = file_get_contents($path);
        if ($raw === false) throw new \RuntimeException('Unable to read scan task.');
        $task = json_decode($raw, true);
        return is_array($task) ? $task : null;
    }

    private function writeUnlocked(string $id, array $task): void
    {
        $path = $this->directory . '/' . $id . '.json';
        $tmp = $path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
        $json = json_encode($task, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to write scan task.');
        }
        @chmod($tmp, 0640);
        if (!rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to publish scan task.');
        }
    }
}
