<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/src/i18n/I18n.php';

$root = dirname(__DIR__);
$engine = new IPScopeI18n('zh_CN', $root . '/src/i18n');
$english = new IPScopeI18n('', $root . '/src/i18n');
$unsupported = new IPScopeI18n('fr_FR', $root . '/src/i18n');

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
if (array_diff(array_keys($englishCatalog), array_keys($chineseCatalog)) ||
    array_diff(array_keys($chineseCatalog), array_keys($englishCatalog))) {
    fwrite(STDERR, "FAIL: English and Chinese catalog keys must match\n");
    exit(1);
}
echo "IPScope independent i18n engine tests passed (" . count($checks) . " assertions + catalog parity).\n";
