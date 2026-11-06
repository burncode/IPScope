<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../src/www/assets/docker-ip-assistant.js');
$css = file_get_contents(__DIR__ . '/../src/www/assets/docker-ip-assistant.css');
if (!is_string($source) || !is_string($css)) {
    fwrite(STDERR, "Could not read Docker IP assistant assets.\n");
    exit(1);
}

$checks = [
    'helper is appended inside the native Fixed IP input cell' => str_contains($source, 'anchor.appendChild(panel)'),
    'helper does not become a sibling grid cell' => !str_contains($source, 'anchor.parentElement.insertBefore(panel, anchor.nextSibling)'),
    'freshness requires a valid timestamp' => str_contains($source, 'timeStatus === \'valid\' && Number.isFinite(age) && age <= 300'),
    'stale candidates are disabled' => str_contains($source, 'useButton.disabled = !snapshotFresh'),
    'stale candidate action has a second safety guard' => str_contains($source, 'if (!snapshotFresh) {'),
    'helper uses the full width of its native input column' => str_contains($css, 'width: 100%;') && str_contains($css, 'max-width: 100%;'),
    'disabled candidates communicate unavailable state' => str_contains($css, 'button.ipscope-docker-ip-candidate:disabled'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo 'Docker IP assistant UI and freshness checks passed (' . count($checks) . " assertions).\n";