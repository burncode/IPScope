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
                'matched_template_count' => count($templates) - count($inventory['orphan_templates']),
                'unmatched_template_count' => count($inventory['orphan_templates']),
                'conflict_count' => count($inventory['conflicts']),
            ],
            'networks' => $inventory['networks'],
            'containers' => $containers,
            'templates' => $templates,
            'orphan_templates' => $inventory['orphan_templates'],
            'conflicts' => $inventory['conflicts'],
        ];
    }
}
