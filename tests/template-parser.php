<?php
declare(strict_types=1);

require_once __DIR__ . '/../src/lib/TemplateParser.php';

use IPScope\TemplateParser;

$parser = new TemplateParser();
$templates = $parser->collect(__DIR__ . '/fixtures/templates');

if (count($templates) !== 1) {
    fwrite(STDERR, "Expected one parsed template.\n");
    exit(1);
}

$template = $templates[0];
$checks = [
    $template['name'] === 'plex',
    $template['network'] === 'bond0',
    $template['ips'] === ['192.168.50.100'],
    $template['mac'] === '02:42:c0:a8:32:64',
    $template['source'] === 'unraid_template',
];

foreach ($checks as $index => $passed) {
    if (!$passed) {
        fwrite(STDERR, 'Template parser test failed at assertion ' . ($index + 1) . PHP_EOL);
        exit(1);
    }
}

echo 'Template parser tests passed (' . count($checks) . ' assertions).' . PHP_EOL;
