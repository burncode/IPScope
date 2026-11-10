<?php
declare(strict_types=1);

$index = file_get_contents(__DIR__ . '/../src/www/index.php');
$js = file_get_contents(__DIR__ . '/../src/www/assets/ipscope.js');
$css = file_get_contents(__DIR__ . '/../src/www/assets/ipscope.css');
$api = file_get_contents(__DIR__ . '/../src/www/api.php');
$en = json_decode((string)file_get_contents(__DIR__ . '/../src/i18n/langs/en.json'), true);
$zh = json_decode((string)file_get_contents(__DIR__ . '/../src/i18n/langs/zh.json'), true);
if (!is_string($index) || !is_string($js) || !is_string($css) || !is_string($api) || !is_array($en) || !is_array($zh)) {
    fwrite(STDERR, "Could not read DHCP UI sources or language catalogs.\n");
    exit(1);
}
$checks = [
    'DHCP workspace navigation exists' => str_contains($index, 'data-view="dhcp"'),
    'DHCP panel exists' => str_contains($index, 'data-panel="dhcp"'),
    'DHCP mode offers unknown, disabled and configured states' => str_contains($index, 'value="unknown"') && str_contains($index, 'value="disabled"') && str_contains($index, 'value="configured"'),
    'DHCP pool and reservation editors exist' => str_contains($index, 'id="dhcp-pools"') && str_contains($index, 'id="dhcp-reservations"'),
    'DHCP config is loaded from the dedicated API action' => str_contains($js, '?action=dhcp'),
    'DHCP config is saved with POST' => str_contains($js, 'method: "POST"') && str_contains($js, 'JSON.stringify(payload)'),
    'CSRF token is forwarded on save' => str_contains($js, 'X-CSRF-Token'),
    'multiple DHCP pools can be added and removed' => str_contains($js, 'dhcp-add-pool') && str_contains($js, 'data-dhcp-remove-pool'),
    'multiple DHCP reservations can be added and removed' => str_contains($js, 'dhcp-add-reservation') && str_contains($js, 'data-dhcp-remove-reservation'),
    'non-configured modes hide pool editor' => str_contains($js, 'dhcpFields.hidden = dhcpMode.value !== "configured"'),
    'API supports DHCP GET and POST' => str_contains($api, "if (\\$method === 'GET')") && str_contains($api, "if (\\$method !== 'POST')"),
    'DHCP form has responsive layout' => str_contains($css, '.dhcp-entry') && str_contains($css, '@media (max-width: 560px)'),
];
foreach (['ui.dhcp','dhcp.heading','dhcp.description','dhcp.mode','dhcp.mode_unknown','dhcp.mode_disabled','dhcp.mode_configured','dhcp.pools','dhcp.pools_help','dhcp.add_pool','dhcp.reservations','dhcp.reservations_help','dhcp.add_reservation','dhcp.safety_title','dhcp.safety_description','dhcp.network','dhcp.subnet','dhcp.start_ip','dhcp.end_ip','dhcp.ip','dhcp.label','dhcp.remove','dhcp.saved','dhcp.save_failed','dhcp.load_failed'] as $key) {
    $checks['translation exists in both catalogs: ' . $key] = isset($en[$key], $zh[$key]);
}
foreach ($checks as $label=>$passed) {
    if (!$passed) {
        fwrite(STDERR, 'FAIL: ' . $label . PHP_EOL);
        exit(1);
    }
}
echo 'DHCP UI tests passed (' . count($checks) . ' assertions).' . PHP_EOL;
