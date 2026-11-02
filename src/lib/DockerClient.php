<?php
declare(strict_types=1);

namespace IPScope;

final class DockerClient
{
    private ProcessRunner $runner;
    private string $binary;

    public function __construct(?ProcessRunner $runner = null, ?string $binary = null)
    {
        $this->runner = $runner ?? new ProcessRunner();
        $this->binary = $binary ?? (getenv('IPSCOPE_DOCKER_BIN') ?: '/usr/bin/docker');
    }

    public function run(array $arguments, int $timeoutSeconds = 20): string
    {
        $result = $this->runner->run(array_merge([$this->binary], $arguments), $timeoutSeconds);
        if ($result['exit_code'] !== 0) {
            $message = trim($result['stderr']) ?: trim($result['stdout']);
            throw new \RuntimeException('Docker command failed: ' . $message);
        }
        return $result['stdout'];
    }

    /** @return list<string> */
    public function networkNames(): array
    {
        $output = $this->run(['network', 'ls', '--format', '{{.Name}}']);
        $names = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', trim($output)) ?: [])));
        sort($names, SORT_NATURAL | SORT_FLAG_CASE);
        return $names;
    }

    /** @return list<array<string,mixed>> */
    public function inspectNetworks(): array
    {
        $networks = [];
        foreach ($this->networkNames() as $name) {
            $decoded = json_decode($this->run(['network', 'inspect', $name]), true, 512, JSON_THROW_ON_ERROR);
            if (isset($decoded[0]) && is_array($decoded[0])) {
                $networks[] = $decoded[0];
            }
        }
        return $networks;
    }

    /** @return list<array<string,mixed>> */
    public function inspectAllContainers(): array
    {
        $idsOutput = trim($this->run(['container', 'ls', '-a', '-q', '--no-trunc']));
        if ($idsOutput === '') {
            return [];
        }

        $ids = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $idsOutput) ?: [])));
        if ($ids === []) {
            return [];
        }

        $json = $this->run(array_merge(['inspect', '--type', 'container'], $ids), 40);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }
}
