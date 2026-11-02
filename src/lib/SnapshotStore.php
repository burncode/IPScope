<?php
declare(strict_types=1);

namespace IPScope;

final class SnapshotStore
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /** @param array<string,mixed> $snapshot */
    public function write(array $snapshot): void
    {
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create snapshot directory.');
        }

        $json = json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $temporary = $this->path . '.tmp.' . getmypid();
        $written = file_put_contents($temporary, $json . PHP_EOL, LOCK_EX);

        if ($written === false) {
            @unlink($temporary);
            throw new \RuntimeException('Unable to write snapshot temporary file.');
        }

        @chmod($temporary, 0640);
        if (!rename($temporary, $this->path)) {
            @unlink($temporary);
            throw new \RuntimeException('Unable to publish snapshot file.');
        }
    }

    /** @return array<string,mixed>|null */
    public function read(): ?array
    {
        if (!is_file($this->path) || !is_readable($this->path)) {
            return null;
        }

        $contents = file_get_contents($this->path);
        if ($contents === false || trim($contents) === '') {
            return null;
        }

        $decoded = json_decode($contents, true);
        return is_array($decoded) ? $decoded : null;
    }

    public function path(): string
    {
        return $this->path;
    }
}
