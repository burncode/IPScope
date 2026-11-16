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

$checks = [
    'container MAC is resolved from the matching Docker network record' =>
        str_contains($js, 'containersByName[owner.container]') && str_contains($js, 'networkDetails.mac'),
    'duplicate reservation/runtime claims are collapsed by container identity' =>
        str_contains($js, 'detailOwnersByName[identity]') && str_contains($js, "owner.kind === \"occupied\""),
    'occupied addresses render a dedicated single-container information panel' =>
        str_contains($js, 'status === "occupied"') && str_contains($js, 'js.container_information'),
    'conflict addresses render all distinct claiming containers' =>
        str_contains($js, 'status === "conflict"') && str_contains($js, 'js.occupied_containers'),
    'reserved addresses render reservation source and reason' =>
        str_contains($js, 'status === "reserved"') && str_contains($js, 'js.reservation_source') && str_contains($js, 'js.reservation_reason'),
    'system addresses render infrastructure purpose and subnet scope' =>
        str_contains($js, 'status === "system"') && str_contains($js, 'js.system_usage') && str_contains($js, 'js.reservation_scope'),
    'address owners are rendered as separate visual chips' =>
        str_contains($js, 'address-owner-chip') && str_contains($js, 'ownerNames.map'),
    'row detail controls expose expanded state accessibly' =>
        str_contains($js, 'aria-expanded="false"') && str_contains($js, 'detailsButton.setAttribute("aria-expanded"'),
    'exclusion rule actions remain available in the detail panel' =>
        str_contains($js, 'detail-exclusion-wrap') && str_contains($js, 'data-detail-exclusion-form'),
    'two-column detail panels align and collapse responsively' =>
        str_contains($css, '.address-detail-layout') && str_contains($css, 'align-items: stretch') && str_contains($css, '@media (max-width: 900px)'),
    'conflict rows use a restrained red treatment' =>
        str_contains($css, '.status-row-conflict') && str_contains($css, '#fff8f7'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

$detailKeys = [
    'js.address_information', 'js.container_information', 'js.reservation_information',
    'js.system_information', 'js.address_status_information', 'js.ip_address',
    'js.conflict_count', 'js.occupied_containers', 'js.container_ip', 'js.running',
    'js.paused', 'js.stopped', 'js.no_container_details', 'js.reservation_source',
    'js.related_container', 'js.reservation_type', 'js.reservation_reason',
    'js.container_template', 'js.template_reservation', 'js.docker_ipam_reservation',
    'js.stopped_container_keeps_ip', 'js.saved_container_ip_reservation',
    'js.saved_ip_reservation', 'js.system_usage', 'js.usage_details',
    'js.reservation_scope', 'js.system_gateway', 'js.system_auxiliary_address',
    'js.system_network_address', 'js.system_broadcast_address',
    'js.docker_network_infrastructure_address', 'js.no_docker_owner_detected',
    'js.address_has_no_runtime_owner', 'js.not_available'
];
foreach ($detailKeys as $key) {
    if (!isset($zh[$key], $en[$key])) {
        fwrite(STDERR, "FAIL: missing bilingual IP detail translation {$key}\n");
        exit(1);
    }
}

echo 'IP management UI tests passed (' . count($checks) . ' assertions + ' . count($detailKeys) . " translation pairs).\n";
