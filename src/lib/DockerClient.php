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
        $names = $this->networkNames();
        if ($names === []) {
            return [];
        }

        $decoded = json_decode(
            $this->run(array_merge(['network', 'inspect'], $names), 40),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        if (!is_array($decoded)) {
            throw new \\RuntimeException('Docker network inspect returned an invalid response.');
        }

        $networks = [];
        foreach ($decoded as $network) {
            if (is_array($network)) {
                $networks[] = $network;
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
