<?php
declare(strict_types=1);

namespace IPScope;

final class SnapshotBuilder
{
    private DockerClient $docker;
    private string $templateDirectory;
    private string $exclusionPath;

    public function __construct(DockerClient $docker, string $templateDirectory, string $exclusionPath = '/boot/config/plugins/ipscope/exclusions.json')
    {
        $this->docker = $docker;
        $this->templateDirectory = $templateDirectory;
        $this->exclusionPath = $exclusionPath;
    }

    /** @return array<string,mixed> */
    public function build(): array
    {
        $networks = (new NetworkCollector($this->docker))->collect();
        $containers = (new ContainerCollector($this->docker))->collect();
        $templates = (new TemplateParser())->collect($this->templateDirectory);
        $exclusionRules = (new AddressExclusionStore($this->exclusionPath))->load();
        $inventory = (new AddressInventory())->build($networks, $containers, $templates, $exclusionRules);
        $lanDiscovery = null;
        try {
            $lanDiscovery = (new LanDiscoveryCollector(new ProcessRunner()))->collect();
        } catch (\Throwable $error) {
            $lanDiscovery = [
                'status' => 'unavailable',
                'error' => $error->getMessage(),
                'active_probe_performed' => false,
                'availability_inferred' => false,
            ];
        }

        $lanMerge = (new LanAddressMerger())->merge($inventory['networks'], $lanDiscovery, $containers);
        $inventory['networks'] = $lanMerge['networks'];
        $lanDiscovery = $lanMerge['lan_discovery'];
        $inventory['conflicts'] = array_merge($inventory['conflicts'], $lanMerge['conflicts']);

        return [
            'schema_version' => 1,
            'generated_at' => date(DATE_ATOM),
            'source' => [
                'docker' => 'docker-cli',
                'templates' => $this->templateDirectory,
                'exclusions' => $this->exclusionPath,
            ],
            'summary' => [
                'network_count' => count($inventory['networks']),
                'container_count' => count($containers),
                'template_count' => count($templates),
                'active_exclusion_rule_count' => count(array_filter($exclusionRules, static fn(array $rule): bool => $rule['enabled'])),
                'matched_template_count' => count($templates) - count($inventory['orphan_templates']),
                'unmatched_template_count' => count($inventory['orphan_templates']),
                'conflict_count' => count($inventory['conflicts']),
            ],
            'lan_discovery' => $lanDiscovery,
            'networks' => $inventory['networks'],
            'containers' => $containers,
            'templates' => $templates,
            'exclusion_rules' => $exclusionRules,
            'orphan_templates' => $inventory['orphan_templates'],
            'conflicts' => $inventory['conflicts'],
        ];
    }
}
