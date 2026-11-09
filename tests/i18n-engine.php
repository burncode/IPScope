<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/i18n/I18n.php';

$root = dirname(__DIR__);
$engine = new IPScopeI18n('zh_CN', $root);
$english = new IPScopeI18n('', $root);
$unsupported = new IPScopeI18n('fr_FR', $root);

$checks = [
    'Chinese locale resolves to zh' => $engine->locale() === 'zh',
    'English empty locale resolves to en' => $english->locale() === 'en',
    'unsupported locale safely falls back to en' => $unsupported->locale() === 'en',
    'Chinese catalog loads plugin-owned text' => $engine->translate('common.scan_now') === '立即扫描',
    'English catalog loads plugin-owned text' => $english->translate('common.scan_now') === 'Scan now',
    'missing Chinese key falls back to English' => $engine->translate('app.title') === 'IPScope',
    'unknown key falls back to key' => $engine->translate('missing.key') === 'missing.key',
    'named placeholders are replaced' => $engine->translate('missing {ip}', ['ip' => '192.168.1.2']) === 'missing 192.168.1.2',
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

$englishCatalog = json_decode((string)file_get_contents($root . '/src/i18n/langs/en.json'), true);
$chineseCatalog = json_decode((string)file_get_contents($root . '/src/i18n/langs/zh.json'), true);
if (!is_array($englishCatalog) || !is_array($chineseCatalog)) {
    fwrite(STDERR, "FAIL: locale catalogs must be valid JSON objects\n");
    exit(1);
}

$englishKeys = array_keys($englishCatalog);
$chineseKeys = array_keys($chineseCatalog);
if (array_diff($englishKeys, $chineseKeys) || array_diff($chineseKeys, $englishKeys)) {
    fwrite(STDERR, "FAIL: English and Chinese catalog keys must match\n");
    exit(1);
}

foreach ($englishCatalog as $key => $englishText) {
    preg_match_all('/\\{([a-zA-Z0-9_]+)\\}/', $englishText, $englishPlaceholders);
    preg_match_all('/\\{([a-zA-Z0-9_]+)\\}/', $chineseCatalog[$key], $chinesePlaceholders);
    $englishNames = $englishPlaceholders[1];
    $chineseNames = $chinesePlaceholders[1];
    sort($englishNames);
    sort($chineseNames);
    if ($englishNames !== $chineseNames) {
        fwrite(STDERR, "FAIL: placeholder mismatch for {$key}\n");
        exit(1);
    }
}

$sourceFiles = [
    $root . '/src/www/index.php',
    $root . '/src/www/assets/ipscope.js',
    $root . '/src/www/assets/docker-ip-assistant.js',
];
$source = implode("\n", array_map(
    static fn(string $path): string => (string)file_get_contents($path),
    $sourceFiles
));
preg_match_all('/(?:\\$ipscopeI18n->translate|\\bt)\\(\\s*["\\']([^"\\']+)["\\']\\s*\\)/', $source, $usedKeys);
$missingKeys = array_values(array_unique(array_filter(
    $usedKeys[1],
    static fn(string $key): bool => !array_key_exists($key, $englishCatalog) || !array_key_exists($key, $chineseCatalog)
)));
if ($missingKeys !== []) {
    fwrite(STDERR, "FAIL: source references missing locale keys: " . implode(', ', $missingKeys) . "\n");
    exit(1);
}

echo "IPScope independent i18n engine tests passed (" . count($checks) . " assertions + catalog parity + placeholder parity + source key coverage).\n";
