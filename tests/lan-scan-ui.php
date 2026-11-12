<?php
declare(strict_types=1);

$index = file_get_contents(__DIR__ . '/../src/www/index.php');
$js = file_get_contents(__DIR__ . '/../src/www/assets/ipscope.js');
$css = file_get_contents(__DIR__ . '/../src/www/assets/ipscope.css');
$api = file_get_contents(__DIR__ . '/../src/www/api.php');
$en = json_decode((string)file_get_contents(__DIR__ . '/../src/i18n/langs/en.json'), true);
$zh = json_decode((string)file_get_contents(__DIR__ . '/../src/i18n/langs/zh.json'), true);
if (!is_string($index) || !is_string($js) || !is_string($css) || !is_string($api) || !is_array($en) || !is_array($zh)) {
    fwrite(STDERR, "Could not read LAN scan UI sources.\n");
    exit(1);
}
$checks = [
    'LAN scan navigation exists' => str_contains($index, 'data-view="lan-scan"'),
    'workspace reserves scrollbar gutter to prevent tab layout shift' => str_contains($css, 'html { scrollbar-gutter: stable; }') && str_contains($css, '@supports not (scrollbar-gutter: stable)') && str_contains($css, 'html { overflow-y: scroll; }'),
    'LAN scan panel exists' => str_contains($index, 'data-panel="lan-scan"'),
    'LAN scan API does not depend on persistent device identity cache' => !str_contains($api, "LanDeviceIdentityCache.php") && !str_contains($api, "device-identity-cache.json") && !str_contains($api, "LanDeviceIdentityCache("),
    'interface is selected from discovered options' => str_contains($index, '<select id="lan-scan-interface"') && str_contains($js, '?action=laninterfaces'),
    'API exposes active interface discovery' => str_contains(file_get_contents(__DIR__ . '/../src/www/api.php'), "'laninterfaces'"),
    'target field is explicit and bounded by help text' => str_contains($index, 'id="lan-scan-targets"') && str_contains($index, 'lan_scan.targets_help'),
    'start and cancel controls exist' => str_contains($index, 'id="lan-scan-start"') && str_contains($index, 'id="lan-scan-cancel"'),
    'task progress and results regions exist' => str_contains($index, 'id="lan-scan-progress-bar"') && str_contains($index, 'id="lan-scan-results"'),
    'UI creates explicit target tasks' => str_contains($js, 'operation: "create"') && str_contains($js, 'targets: targets'),
    'UI advances task one step at a time' => str_contains($js, 'operation: "step"'),
    'UI polls task status' => str_contains($js, '?action=lanscan&id='),
    'LAN scan mutation requests share Unraid CSRF header helper' => str_contains($js, 'function lanScanRequestHeaders(includeJson)') && str_contains($js, 'headers["X-CSRF-Token"] = csrf_token;'),
    'create, step and cancel requests use CSRF-aware headers' => substr_count($js, 'headers: lanScanRequestHeaders(true)') >= 3,
    'LAN scan results prioritize device names and fall back to MAC addresses' => str_contains($js, 'item.device_name || item.mac || t("lan_scan.unknown_device")') && str_contains($js, 't("lan_scan.device")') && !str_contains($js, '<th>MAC</th>'),
    'LAN scan labels MAC fallback without exposing MAC for named devices' => str_contains($js, 'lan_scan.mac_fallback') && str_contains($js, ': (item.mac ? t("lan_scan.mac_fallback")'),
    'LAN scan UI labels name lookup sources and unknown devices' => str_contains($js, 'lan_scan.name_source_') && str_contains($js, 'lan_scan.name_not_found'),
    'LAN scan defaults to online devices' => str_contains($js, 'var lanScanFilter = "responding"'),
    'LAN scan provides online, no-response, error and all filters' => str_contains($js, 'data-lan-filter') && str_contains($js, 'filter_no_response') && str_contains($js, 'filter_errors') && str_contains($js, 'filter_all'),
    'LAN scan summarizes result categories' => str_contains($js, 'lan-scan-summary-card') && str_contains($js, 'responding.length') && str_contains($js, 'noResponse.length'),
    'LAN scan sorts filtered results by IPv4 address' => str_contains($js, 'function lanScanIpSort') && str_contains($js, '.sort(lanScanIpSort)'),
    'LAN scan classifies recognizable device types without changing probe status' => str_contains($js, 'function lanScanDeviceType') && str_contains($js, 'return "router"') && str_contains($js, 'return "nas"') && str_contains($js, 'return "smart"') && str_contains($js, 'return "mobile"') && str_contains($js, 'return "computer"') && str_contains($js, 't("lan_scan.type_" + type)') && str_contains($js, 'var statusLabel = t("lan_scan." + item.status) || item.status'),
    'LAN scan keeps no-response distinct from free addresses' => str_contains($js, 'item.status === "no_response"') && str_contains($zh['lan_scan.safety_description'], '无响应') && str_contains($en['lan_scan.safety_description'], 'No response'),
    'API loads the device name resolver' => str_contains(file_get_contents(__DIR__ . '/../src/www/api.php'), "LanDeviceNameResolver.php"),
    'scan feedback follows completed task state' => str_contains($js, 't("lan_scan.completed_notice")') && str_contains($js, 't("lan_scan.progress_notice")'),
    'scan feedback includes progress placeholders' => str_contains($js, 'replace("{completed}"') && str_contains($js, 'replace("{total}"'),
    'UI supports cancellation' => str_contains($js, 'operation: "cancel"'),
    'UI restores task id after refresh' => str_contains($js, 'sessionStorage.getItem("ipscope.lanscan.task")'),
    'UI persists active task id' => str_contains($js, 'sessionStorage.setItem("ipscope.lanscan.task"'),
    'target parser accepts whitespace and commas' => str_contains($js, 'rawTargets.split(/[\\s,;]+/)'),
    'target parser accepts CIDR prefixes' => str_contains($js, '(?:\\/(?:[0-9]|[12][0-9]|3[0-2]))?'),
    'target help documents subnet expansion and cap' => str_contains($zh['lan_scan.targets_help'], '192.168.50.0/24') && str_contains($zh['lan_scan.targets_help'], '256'),

    'LAN scan result summary and filter controls are styled' => str_contains($css, '.lan-scan-summary') && str_contains($css, '.lan-scan-filter-bar') && str_contains($css, '.lan-device-icon'),
    'responsive scan controls are styled' => str_contains($css, '.lan-scan-form') && str_contains($css, '@media(max-width:600px)'),
];
$keys = ['ui.lan_scan','lan_scan.heading','lan_scan.description','lan_scan.device','lan_scan.unknown_device','lan_scan.name_not_found','lan_scan.mac_fallback','lan_scan.name_source_reverse_dns','lan_scan.name_source_mdns','lan_scan.name_source_netbios','lan_scan.filter_online','lan_scan.filter_no_response','lan_scan.filter_errors','lan_scan.filter_all','lan_scan.filter_results','lan_scan.filter_empty_title','lan_scan.filter_empty_help','lan_scan.filter_waiting_online','lan_scan.type_router','lan_scan.type_nas','lan_scan.type_smart','lan_scan.type_mobile','lan_scan.type_computer','lan_scan.type_other','lan_scan.interface','lan_scan.targets','lan_scan.targets_help','lan_scan.start','lan_scan.cancel','lan_scan.task','lan_scan.empty','lan_scan.safety_title','lan_scan.safety_description','lan_scan.queued','lan_scan.running','lan_scan.completed','lan_scan.cancelled','lan_scan.failed','lan_scan.responding','lan_scan.no_response','lan_scan.probe_error','lan_scan.response_unparsed','lan_scan.errors','lan_scan.result','lan_scan.waiting_results','lan_scan.validation','lan_scan.invalid_ip','lan_scan.creating','lan_scan.started','lan_scan.restoring','lan_scan.completed_notice','lan_scan.failed_notice','lan_scan.progress_notice','lan_scan.loading_interfaces','lan_scan.no_interfaces','lan_scan.no_interfaces_help','lan_scan.default_route','lan_scan.interface_load_failed'];
foreach ($keys as $key) $checks['translation parity: ' . $key] = isset($en[$key], $zh[$key]);
foreach ($checks as $label=>$passed) {
    if (!$passed) {
        fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL);
        exit(1);
    }
}
echo 'LAN scan UI tests passed (' . count($checks) . ' assertions).' . PHP_EOL;
