<?php
declare(strict_types=1);

namespace IPScope;

final class SnapshotBuilder
{
    private DockerClient $docker;
    private string $templateDirectory;

    public function __construct(DockerClient $docker, string $templateDirectory)
    {
        $this->docker = $docker;
        $this->templateDirectory = $templateDirectory;
    }

    /** @return array<string,mixed> */
    public function build(): array
    {
        $networks = (new NetworkCollector($this->docker))->collect();
        $containers = (new ContainerCollector($this->docker))->collect();
        $templates = (new TemplateParser())->collect($this->templateDirectory);
        $inventory = (new AddressInventory())->build($networks, $containers, $templates);

        return [
            'schema_version' => 1,
            'generated_at' => date(DATE_ATOM),
            'source' => [
                'docker' => 'docker-cli',
                'templates' => $this->templateDirectory,
            ],
            'summary' => [
                'network_count' => count($inventory['networks']),
                'container_count' => count($containers),
                'template_count' => count($templates),
                'conflict_count' => count($inventory['conflicts']),
            ],
            'networks' => $inventory['networks'],
            'containers' => $containers,
            'templates' => $templates,
            'conflicts' => $inventory['conflicts'],
        ];
    }
}
