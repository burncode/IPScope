<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$index = (string)file_get_contents($root . '/src/www/index.php');
$version = json_decode((string)file_get_contents($root . '/src/version.json'), true);
$english = json_decode((string)file_get_contents($root . '/src/i18n/langs/en.json'), true);
$chinese = json_decode((string)file_get_contents($root . '/src/i18n/langs/zh.json'), true);
$checks = [
    'About tab is registered' => str_contains($index, 'data-view="about"'),
    'About panel is registered' => str_contains($index, 'data-panel="about"'),
    'GitHub project link is present' => str_contains($index, 'https://github.com/burncode/IPScope'),
    'MIT license is identified' => str_contains($index, '>MIT<'),
    'version metadata is valid' => is_array($version) && isset($version['version'], $version['built_at']),
    'English About translations exist' => isset($english['ui.about'], $english['about.heading'], $english['about.safety_description']),
    'Chinese About translations exist' => isset($chinese['ui.about'], $chinese['about.heading'], $chinese['about.safety_description']),
];
foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}
echo "About page tests passed (" . count($checks) . " assertions).\n";
