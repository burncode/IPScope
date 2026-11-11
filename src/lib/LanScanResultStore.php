<?php
declare(strict_types=1);

namespace IPScope;

/** Atomically stores the most recent completed explicit LAN scan. */
final class LanScanResultStore
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    public function save(array $task): array
    {
        if (($task['status'] ?? '') !== 'completed' || !is_array($task['results'] ?? null)) {
            throw new InvalidArgumentException('Only completed LAN scan tasks can be persisted.');
        }
        $payload = [
            'schema_version'=>1,
            'task_id'=>(string)($task['id'] ?? ''),
            'interface'=>(string)($task['interface'] ?? ''),
            'scanned_at'=>(string)($task['completed_at'] ?? date(DATE_ATOM)),
            'target_count'=>(int)($task['target_count'] ?? count($task['results'])),
            'counts'=>is_array($task['counts'] ?? null) ? $task['counts'] : [],
            'results'=>array_values($task['results']),
            'availability_inferred'=>false,
        ];
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create LAN scan result directory.');
        }
        $lock = fopen($this->path . '.lock', 'c');
        if ($lock === false) throw new RuntimeException('Unable to open LAN scan result lock.');
        try {
            if (!flock($lock, LOCK_EX)) throw new RuntimeException('Unable to lock LAN scan result store.');
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            $tmp = $this->path . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
            if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
                @unlink($tmp);
                throw new RuntimeException('Unable to write LAN scan results.');
            }
            @chmod($tmp, 0640);
            if (!rename($tmp, $this->path)) {
                @unlink($tmp);
                throw new RuntimeException('Unable to publish LAN scan results.');
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $payload;
    }

    public function load(): ?array
    {
        if (!is_file($this->path) || !is_readable($this->path)) return null;
        $raw = file_get_contents($this->path);
        if ($raw === false || trim($raw) === '') return null;
        $data = json_decode($raw, true);
        if (!is_array($data) || (int)($data['schema_version'] ?? 0) !== 1 || !is_array($data['results'] ?? null)) return null;
        return $data;
    }
}
