<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root . '/src/www/index.php');
$script = file_get_contents($root . '/src/www/assets/ipscope.js');
$api = file_get_contents($root . '/src/www/api.php');
$english = file_get_contents($root . '/translations/ipscope.txt');
$chinese = file_get_contents($root . '/translations/zh_CN/ipscope.txt');

$checks = [
    'page keeps the active Unraid locale as the document language' => str_contains($page, '$locale ?? "en-US"'),
    'PHP-rendered page labels use the native Unraid translation function' => str_contains($page, '_("Docker IPv4 inventory")') && str_contains($page, '_("IP Manager")'),
    'JavaScript uses the native Unraid translator with an identity fallback' => str_contains($script, 'typeof window._ === "function" ? window._(text) : text'),
    'JavaScript UI labels pass through the translation helper' => str_contains($script, 't("Scanning…")') && str_contains($script, 't("Scan now")') && str_contains($script, 't("Loading snapshot…")'),
    'English master and Simplified Chinese translations both exist' => is_string($english) && is_string($chinese) && str_contains($english, 'Docker IPv4 inventory=') && str_contains($chinese, 'Docker IPv4 inventory='),
    'API remains independent from WebGUI translation bootstrap' => !str_contains($api, 'Translations.php') && !str_contains($api, 'function _('),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo 'IPScope i18n smoke checks passed (' . count($checks) . " assertions).\n";
