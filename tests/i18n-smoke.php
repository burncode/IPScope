<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$page = file_get_contents($root . '/src/www/index.php');
$script = file_get_contents($root . '/src/www/assets/ipscope.js');
$dockerScript = file_get_contents($root . '/src/www/assets/docker-ip-assistant.js');
$api = file_get_contents($root . '/src/www/api.php');
$english = file_get_contents($root . '/translations/ipscope.txt');
$chinese = file_get_contents($root . '/translations/zh_CN/ipscope.txt');
$englishJs = file_get_contents($root . '/translations/javascript.txt');
$chineseJs = file_get_contents($root . '/translations/zh_CN/javascript.txt');

function ipscopeNormalizeTranslationKey(string $text): string
{
    $text = trim($text);
    $text = str_replace(
        ['&amp;', '?', '{', '}', '|', '&', '~', '!', '[', ']', '(', ')', '/', '\\', ':', '*', '^', '.', '"', "'"],
        '',
        $text
    );
    $text = preg_replace('/<.+?\\/? >/', '', $text) ?? $text;
    $text = preg_replace('/  +/', ' ', $text) ?? $text;
    return trim($text);
}

function ipscopeTranslationKeys(string $contents): array
{
    $keys = [];
    foreach (preg_split('/\\R/', $contents) as $line) {
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
$englishJsKeys = ipscopeTranslationKeys($englishJs);
$chineseJsKeys = ipscopeTranslationKeys($chineseJs);
preg_match_all('/_\\((["\\'])(.*?)\\1\\)/', $page, $phpMatches);
preg_match_all('/\\bt\\("([^"]+)"\\)/', $script . "\\n" . $dockerScript, $jsMatches);
$phpSourceKeys = array_values(array_unique(array_map('ipscopeNormalizeTranslationKey', $phpMatches[2] ?? [])));
$jsSourceKeys = array_values(array_unique(array_map('ipscopeNormalizeTranslationKey', $jsMatches[1] ?? [])));
$missingEnglish = array_values(array_diff($phpSourceKeys, array_keys($englishKeys)));
$missingChinese = array_values(array_diff($phpSourceKeys, array_keys($chineseKeys)));
$missingEnglishJs = array_values(array_diff($jsSourceKeys, array_keys($englishJsKeys)));
$missingChineseJs = array_values(array_diff($jsSourceKeys, array_keys($chineseJsKeys)));
$allCatalogKeysNormalized = true;
foreach (array_merge(array_keys($englishKeys), array_keys($chineseKeys), array_keys($englishJsKeys), array_keys($chineseJsKeys)) as $key) {
    if (ipscopeNormalizeTranslationKey($key) !== $key) {
        $allCatalogKeysNormalized = false;
        break;
    }
}

$checks = [
    'page keeps the active Unraid locale as the document language' => str_contains($page, '$locale ?? "en-US"'),
    'PHP-rendered page labels use the native Unraid translation function' => str_contains($page, '_("Docker IPv4 inventory")') && str_contains($page, '_("IP Manager")'),
    'main and Docker JavaScript use the native Unraid translator with a safe fallback' => str_contains($script, 'typeof window._ === "function" ? window._(text) : text') && str_contains($dockerScript, 'typeof window._ === "function" ? window._(text) : text'),
    'PHP and JavaScript English and Chinese translation files exist' => is_string($english) && is_string($chinese) && is_string($englishJs) && is_string($chineseJs),
    'every PHP translation key exists in both page language files' => $missingEnglish === [] && $missingChinese === [],
    'every JavaScript translation key exists in both JavaScript language files' => $missingEnglishJs === [] && $missingChineseJs === [],
    'English and Simplified Chinese page files use the same key set' => array_keys($englishKeys) === array_keys($chineseKeys),
    'English and Simplified Chinese JavaScript files use the same key set' => array_keys($englishJsKeys) === array_keys($chineseJsKeys),
    'all translation catalog keys follow Unraid normalization rules' => $allCatalogKeysNormalized,
    'API remains independent from WebGUI translation bootstrap' => !str_contains($api, 'Translations.php') && !str_contains($api, 'function _('),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\\n");
        exit(1);
    }
}

echo 'IPScope i18n smoke checks passed (' . count($checks) . " assertions).\\n";
