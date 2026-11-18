<?php
declare(strict_types=1);

$js = file_get_contents(__DIR__ . '/../src/www/assets/ipscope.js');
$css = file_get_contents(__DIR__ . '/../src/www/assets/ipscope.css');
$zh = json_decode((string)file_get_contents(__DIR__ . '/../src/i18n/langs/zh.json'), true);
$en = json_decode((string)file_get_contents(__DIR__ . '/../src/i18n/langs/en.json'), true);

if (!is_string($js) || !is_string($css) || !is_array($zh) || !is_array($en)) {
    fwrite(STDERR, "Could not read IP management UI assets or locale catalogs.\n");
    exit(1);
}

$html = file_get_contents(__DIR__ . '/../src/www/index.php');

$checks = [

    'IP management retains the same outer panel card as other tabs' =>
        str_contains($html ?? '', 'class="panel inventory-panel"') &&
        str_contains($css, '.inventory-panel') &&
        str_contains($css, 'border: 1px solid var(--line)'),
    'network groups show driver but omit repetitive subnet metadata' =>
        str_contains($js, 'network-driver-tag') && !str_contains($js, '(subnet ? " · " + escapeHtml(subnet) : "")'),
    'IP inventory uses restrained row-level conflict emphasis' =>
        str_contains($css, '.status-row-conflict > td:first-child') && str_contains($css, 'background: #fff;'),
    'container MAC is resolved from the matching Docker network record' =>
        str_contains($js, 'containersByName[owner.container]') && str_contains($js, 'networkDetails.mac'),
    'duplicate reservation/runtime claims are collapsed by container identity' =>
        str_contains($js, 'detailOwnersByName[identity]') && str_contains($js, "owner.kind === \"occupied\""),
    'occupied addresses render a dedicated single-container information panel' =>
        str_contains($js, 'status === "occupied"') && str_contains($js, 'js.container_information'),
    'conflict addresses render all distinct claiming containers' =>
        str_contains($js, 'status === "conflict"') && str_contains($js, 'js.occupied_containers'),
    'reserved addresses render reservation source and purpose' =>
        str_contains($js, 'status === "reserved"') &&
        str_contains($js, 'js.template_source') && str_contains($js, 'js.ipam_source') &&
        str_contains($js, 'js.template_ip_purpose') && str_contains($js, 'js.ipam_ip_purpose'),
    'system addresses render infrastructure purpose and subnet scope' =>
        str_contains($js, 'status === "system"') && str_contains($js, 'js.system_usage') &&
        str_contains($js, 'js.reservation_scope') && str_contains($js, 'var subnetLabel ='),
    'address owners are rendered as separate visual chips' =>
        str_contains($js, 'address-owner-chip') && str_contains($js, 'ownerNames.map'),
    'row detail controls expose expanded state accessibly' =>
        str_contains($js, 'aria-expanded="false"') && str_contains($js, 'detailsButton.setAttribute("aria-expanded"'),
    'exclusion rule actions remain available in the detail panel' =>
        str_contains($js, 'detail-exclusion-wrap') && str_contains($js, 'data-detail-exclusion-form'),
    'exclusion action is initialized before detail content references it' =>
        strpos($js, 'var exclusionAction =') !== false &&
        strpos($js, 'var exclusionAction =') < strpos($js, "' + exclusionAction + '") &&
        str_contains($js, 'data-add-exclusion') && str_contains($js, 'data-enable-exclusion'),
    'two-column detail panels align and collapse responsively' =>
        str_contains($css, '.address-detail-layout') && str_contains($css, 'align-items: stretch') && str_contains($css, '@media (max-width: 900px)'),
    'conflict rows use a restrained red treatment' =>
        str_contains($css, '.status-row-conflict') && str_contains($css, 'background: #fff;') && str_contains($css, 'inset 3px 0 0 #d92d20'),
    'conflict detail includes an explicit warning banner' =>
        str_contains($js, 'detail-conflict-notice') && str_contains($js, 'js.ip_conflict_notice'),
    'container details use compact horizontal information rows' =>
        str_contains($js, 'detail-owner-row') && str_contains($js, 'detail-source-pill') && str_contains($js, 'detail-owner-purpose'),
    'container purpose distinguishes template, IPAM and runtime sources' =>
        str_contains($js, 'js.template_ip_purpose') && str_contains($js, 'js.ipam_ip_purpose') && str_contains($js, 'js.runtime_ip_purpose'),
    'exclusion form is collapsed until explicitly requested' =>
        str_contains($js, '<details class="detail-exclusion-disclosure">') && str_contains($js, 'data-detail-exclusion-form'),
    'detail panels use aligned card columns without forced minimum height' =>
        str_contains($css, 'grid-template-columns: minmax(260px, .36fr) minmax(0, .64fr)') &&
        str_contains($css, 'align-items: stretch') &&
        str_contains($css, '.address-detail-panel {') &&
        str_contains($css, 'border: 1px solid #e3e8ed') &&
        str_contains($css, 'min-height: 0;'),
    'container detail rows use a stable five-column alignment grid' =>
        str_contains($css, 'minmax(150px, 1.15fr)') &&
        str_contains($css, 'minmax(160px, 1.25fr)'),
    'exclusion controls align as a compact detail footer' =>
        str_contains($css, '.detail-exclusion-copy') &&
        str_contains($css, '.detail-exclusion-action') &&
        str_contains($css, 'align-items: end;'),
    'inventory status and owner chips use compact consistent dimensions' =>
        str_contains($css, '.address-table .status-pill') &&
        str_contains($css, 'min-height: 20px') &&
        str_contains($css, '.address-table .address-owner-chip') &&
        str_contains($css, 'min-height: 22px'),
    'expanded detail cards use compact spacing and restrained borders' =>
        str_contains($css, '.inventory-panel .address-detail-panel') &&
        str_contains($css, 'padding: 13px 15px') &&
        str_contains($css, 'border-color: #e6ebef'),
    'expanded container information uses compact aligned rows' =>
        str_contains($css, '.inventory-panel .detail-owner-row') &&
        str_contains($css, 'min-height: 43px') &&
        str_contains($css, '.inventory-panel .detail-owner-purpose'),
    'detail refinements retain responsive layouts' =>
        str_contains($css, '@media (max-width: 900px)') &&
        str_contains($css, '@media (max-width: 650px)'),
    'detail cards use equal columns with aligned three-row information' =>
        str_contains($css, 'grid-template-columns: minmax(0, 1fr) minmax(0, 1fr)') &&
        str_contains($css, '.address-summary-panel .address-detail-list > div') &&
        str_contains($css, '.system-detail-line'),
    'exclusion rules render as a compact vertically centered action strip' =>
        str_contains($css, '.detail-exclusion-wrap') &&
        str_contains($css, 'justify-content: space-between') &&
        str_contains($css, 'align-items: center') &&
        str_contains($css, 'min-height: 30px'),
    'network groups no longer render as nested bordered cards' =>
        str_contains($css, '.network-card {') && str_contains($css, 'border: 0;') && str_contains($css, 'background: transparent;'),
    'conflict rows keep neutral backgrounds and use a left severity marker' =>
        str_contains($css, '.status-row-conflict:hover > td') && str_contains($css, 'inset 3px 0 0 #d92d20'),
    'system address collision owners use localized infrastructure labels' =>
        str_contains($js, 'systemOwnerLabels') &&
        str_contains($js, 't("js.system_gateway")') &&
        str_contains($js, 't("js.system_auxiliary_address")'),
    'template runtime mismatch conflicts use a localized label' =>
        str_contains($js, 'item.type === "template_runtime_mismatch"') &&
        str_contains($js, 't("js.template_runtime_mismatch"'),
    'system address collisions have an explicit localized label and participant details' =>
        str_contains($js, 'item.type === "system_address_collision"') &&
        str_contains($js, 'js.system_address_collision') &&
        str_contains($js, 'js.system_address') &&
        str_contains($js, 'item.system_owners'),
    'overlapping exclusion rules are summarized without duplicating the conflict row' =>
        str_contains($js, 'js.matching_rules') && str_contains($js, 'item.rule_ids.length > 1'),
    'exclusion action has a compact explanatory toolbar and explicit confirmation' =>
        str_contains($js, 'detail-exclusion-toolbar') && str_contains($js, 'js.exclude_ip_helper') && str_contains($js, 'js.confirm_exclusion'),
    'exclusion rule creation remains collapsed until requested' =>
        str_contains($js, '<details class="detail-exclusion-disclosure">') && str_contains($js, 'data-detail-exclusion-form'),

];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

$detailKeys = [
    'js.basic_information', 'js.network', 'js.address_state', 'js.conflict_count',
    'js.containers_unit', 'js.container_information', 'js.occupied_containers',
    'js.reservation_information', 'js.system_information', 'js.address_status_information',
    'js.running', 'js.paused', 'js.stopped', 'js.unknown_state', 'js.container_ip',
    'js.no_container_details', 'js.template_source', 'js.ipam_source', 'js.runtime_source',
    'js.template_ip_purpose', 'js.ipam_ip_purpose', 'js.runtime_ip_purpose',
    'js.system_usage', 'js.usage_details', 'js.reservation_scope',
    'js.system_gateway', 'js.system_auxiliary_address', 'js.system_network_address',
    'js.system_broadcast_address', 'js.docker_network_infrastructure_address',
    'js.no_docker_owner_detected', 'js.address_has_no_runtime_owner',
    'js.ip_conflict_notice', 'js.exclusion_reason_label', 'js.exclude_this_ip', 'js.exclusion_rules', 'js.exclude_ip_helper', 'js.confirm_exclusion',
    'js.reason_e_g_dhcp_reservation', 'js.add_rule', 'js.excluded_by_rule',
    'js.view_rule', 'js.a_matching_rule_is_disabled', 'js.enable_rule'
];
foreach ($detailKeys as $key) {
    if (!isset($zh[$key], $en[$key])) {
        fwrite(STDERR, "FAIL: missing bilingual IP detail translation {$key}\n");
        exit(1);
    }
}

echo 'IP management UI tests passed (' . count($checks) . ' assertions + ' . count($detailKeys) . " translation pairs).\n";
