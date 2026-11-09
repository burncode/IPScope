<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../src/www/assets/docker-ip-assistant.js');
$css = file_get_contents(__DIR__ . '/../src/www/assets/docker-ip-assistant.css');
if (!is_string($source) || !is_string($css)) {
    fwrite(STDERR, "Could not read Docker IP assistant assets.\n");
    exit(1);
}

$englishCatalog = json_decode((string)file_get_contents(__DIR__ . '/../src/i18n/langs/en.json'), true);
$chineseCatalog = json_decode((string)file_get_contents(__DIR__ . '/../src/i18n/langs/zh.json'), true);

$checks = [
    'helper is appended inside the native Fixed IP input cell' => str_contains($source, 'anchor.appendChild(panel)'),
    'helper does not become a sibling grid cell' => !str_contains($source, 'anchor.parentElement.insertBefore(panel, anchor.nextSibling)'),
    'freshness requires a valid timestamp and live-clock expiry' => str_contains($source, 'snapshotTimeStatus === "valid"') && str_contains($source, 'now - snapshotGeneratedAt <= 300000'),
    'stale candidates are disabled' => str_contains($source, 'useButton.disabled = !snapshotFresh'),
    'candidate action rechecks freshness at click time' => str_contains($source, 'if (!isSnapshotFresh()) {'),
    'helper width follows the actual Fixed IP input width' => str_contains($source, 'ipInput.getBoundingClientRect().width') && str_contains($source, 'panel.style.width = Math.round(inputWidth) + "px";'),
    'disabled candidates communicate unavailable state' => str_contains($css, 'button.ipscope-docker-ip-candidate:disabled'),
    'manual Fixed IP values outside suggestions are marked unverified' => str_contains($source, 'js.fixed_ip_ip_is_not_in_the_current_ipscope_suggestions_i') && ($englishCatalog['js.fixed_ip_ip_is_not_in_the_current_ipscope_suggestions_i'] ?? null) === 'Fixed IP {ip} is not in the current IPScope suggestions. Its availability has not been verified by this snapshot.' && isset($chineseCatalog['js.fixed_ip_ip_is_not_in_the_current_ipscope_suggestions_i']),
    'stale matching suggestions are not styled as verified selections' => str_contains($source, 'selected && buttonSnapshotFresh') && str_contains($source, 'js.fixed_ip_ip_matches_an_old_suggestion_but_the_inventory') && isset($englishCatalog['js.fixed_ip_ip_matches_an_old_suggestion_but_the_inventory'], $chineseCatalog['js.fixed_ip_ip_matches_an_old_suggestion_but_the_inventory']),
    'manual value status updates when the input changes' => str_contains($source, 'ipInput.addEventListener("input", syncSelectedCandidate)') && str_contains($source, 'ipInput.addEventListener("change", syncSelectedCandidate)'),
    'network changes invalidate the previous suggestion state' => str_contains($source, 'networkSelect.addEventListener("change", function () {') && str_contains($source, 'suggestionsLoaded = false;'),
    'unverified manual values use a distinct warning style' => str_contains($css, '.ipscope-docker-ip-message.is-warning'),
    'warning state is applied and cleared independently from errors' => str_contains($source, 'message.classList.toggle("is-warning", !!warning && !error);'),
    'snapshot freshness is recalculated from the live clock' => str_contains($source, 'function isSnapshotFresh()') && str_contains($source, 'var now = Date.now();') && str_contains($source, 'now - snapshotGeneratedAt <= 300000'),
    'candidate selection rechecks freshness at click time' => str_contains($source, 'if (!isSnapshotFresh()) {') && str_contains($source, 'js.this_inventory_snapshot_is_not_fresh_enough_rescan_in_ip'),
    'selected candidate styling uses current freshness instead of a frozen button flag' => str_contains($source, 'candidateButtons.length > 0 && isSnapshotFresh()') && !str_contains($source, 'candidateButtons[0].dataset.snapshotFresh === "true"'),
    'each recommendation request receives a monotonically increasing request id' => str_contains($source, 'var requestSequence = 0;') && str_contains($source, 'var requestId = ++requestSequence;'),
    'stale recommendation responses are ignored even if the network changes back' => str_contains($source, 'if (requestId !== requestSequence) return;'),
    'stale request errors cannot overwrite the current UI' => str_contains($source, '.catch(function (error) {') && str_contains($source, 'if (requestId !== requestSequence) return;'),
    'stale request completion cannot re-enable a newer request button' => str_contains($source, '.finally(function () {') && str_contains($source, 'if (requestId !== requestSequence) return;'),
    'network changes invalidate in-flight recommendation requests' => str_contains($source, 'requestSequence += 1;') && str_contains($source, 'networkSelect.addEventListener("change", function () {'),
    'recommendation payload requires an object response with ok true' => str_contains($source, 'function validateRecommendationPayload(body, expectedNetwork)') && str_contains($source, 'body.ok !== true'),
    'recommendation network collection must be an array' => str_contains($source, '!Array.isArray(recommendation.networks)'),
    'snapshot timestamp status is restricted to engine-defined values' => str_contains($source, '["valid", "missing", "future", "invalid"].indexOf(timeStatus) === -1'),
    'snapshot age must agree with timestamp validity' => str_contains($source, '(timeStatus === "valid" && typeof age !== "number")') && str_contains($source, '(timeStatus !== "valid" && age !== null)'),
    'candidate records must be valid objects before rendering' => str_contains($source, 'candidate that is not an object') && str_contains($source, 'if (!candidate || typeof candidate !== "object" || Array.isArray(candidate))'),
    'candidate IPv4 addresses reject invalid and non-canonical octets' => str_contains($source, 'function isValidIPv4Address(value)') && str_contains($source, 'number <= 255') && str_contains($source, 'octet.charAt(0) === "0"'),
    'duplicate candidate IPs are rejected' => str_contains($source, 'seenCandidates[ip]'),
    'candidate records must be valid objects' => str_contains($source, 'candidate that is not an object') && str_contains($source, 'if (!candidate || typeof candidate !== "object" || Array.isArray(candidate))'),
    'response must contain at most one matching network record' => str_contains($source, 'matches.length > 1'),
    'API failures clear candidate and snapshot state' => str_contains($source, 'function clearCandidateState()') && str_contains($source, 'candidateButtons = [];') && str_contains($source, 'snapshotGeneratedAt = null;'),
    'malformed payloads are validated before rendering candidates' => str_contains($source, 'var validated = validateRecommendationPayload(body, network);') && str_contains($source, 'var selected = validated.selected;'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo 'Docker IP assistant UI and freshness checks passed (' . count($checks) . " assertions).\n";