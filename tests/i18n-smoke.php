<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root . '/src/www/index.php');
$script = file_get_contents($root . '/src/www/assets/ipscope.js');
$api = file_get_contents($root . '/src/www/api.php');
$english = file_get_contents($root . '/translations/ipscope.txt');
$chinese = file_get_contents($root . '/translations/zh_CN/ipscope.txt');

function ipscopeTranslationKeys(string $contents): array
{
    $keys = [];
    foreach (preg_split('/\R/', $contents) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, ';')) continue;
        $separator = strpos($line, '=');
        if ($separator === false) continue;
        $key = trim(substr($line, 0, $separator));
        if ($key !== '') {
            if (isset($keys[$key])) throw new RuntimeException('Duplicate translation key: ' . $key);
            $keys[$key] = true;
        }
    }
    return $keys;
}

$englishKeys = ipscopeTranslationKeys($english);
$chineseKeys = ipscopeTranslationKeys($chinese);
preg_match_all('/_\("([^"]+)"\)/', $page, $phpMatches);
preg_match_all('/t\("([^"]+)"\)/', $script, $jsMatches);
$usedKeys = array_unique(array_merge($phpMatches[1] ?? [], $jsMatches[1] ?? []));
$missingEnglish = array_values(array_diff($usedKeys, array_keys($englishKeys)));
$missingChinese = array_values(array_diff($usedKeys, array_keys($chineseKeys)));

$checks = [
    'page keeps the active Unraid locale as the document language' => str_contains($page, '$locale ?? "en-US"'),
    'PHP-rendered page labels use the native Unraid translation function' => str_contains($page, '_("Docker IPv4 inventory")') && str_contains($page, '_("IP Manager")'),
    'JavaScript uses the native Unraid translator with an identity fallback' => str_contains($script, 'typeof window._ === "function" ? window._(text) : text'),
    'JavaScript scan status labels pass through the translation helper' => str_contains($script, 't("Scanning…")') && str_contains($script, 't("Scan now")') && str_contains($script, 't("Loading snapshot…")'),
    'English master and Simplified Chinese translation files exist' => is_string($english) && is_string($chinese),
    'every translated PHP and JavaScript key exists in both language files' => $missingEnglish === [] && $missingChinese === [],
    'English and Simplified Chinese files use the same key set' => array_keys($englishKeys) === array_keys($chineseKeys),
    'API remains independent from WebGUI translation bootstrap' => !str_contains($api, 'Translations.php') && !str_contains($api, 'function _('),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo 'IPScope i18n smoke checks passed (' . count($checks) . " assertions).\n";
