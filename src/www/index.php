<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/i18n/I18n.php';
$ipscopeVersionPath = dirname(__DIR__) . '/version.json';
$ipscopeVersion = is_file($ipscopeVersionPath)
    ? json_decode((string)file_get_contents($ipscopeVersionPath), true)
    : ['version' => 'Development', 'built_at' => 'development'];
if (!is_array($ipscopeVersion)) {
    $ipscopeVersion = ['version' => 'Development', 'built_at' => 'development'];
}
$ipscopeLocale = isset($locale) && is_string($locale) ? $locale : 'en';
$ipscopeI18n = new IPScopeI18n($ipscopeLocale, dirname(__DIR__, 2));
$ipscopeEnglish = new IPScopeI18n('en', dirname(__DIR__, 2));
$ipscopeI18nConfig = [
    'locale' => $ipscopeI18n->locale(),
    'messages' => $ipscopeI18n->catalog(),
    'fallback' => $ipscopeEnglish->catalog(),
];
?><!doctype html>
<html lang="<?= htmlspecialchars($ipscopeI18n->locale() === 'zh' ? 'zh-CN' : 'en-US', ENT_QUOTES, 'UTF-8') ?>">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>IPScope</title>
  <link rel="stylesheet" href="/plugins/ipscope/src/www/assets/ipscope.css">
</head>
<body>
  <main class="ipscope">
    <header class="topbar">
      <div class="brand">
        <div class="brand-mark">IP</div>
        <div>
          <h1>IPScope</h1>
          <p><?= htmlspecialchars($ipscopeI18n->translate("app.title"), ENT_QUOTES, "UTF-8") ?></p>
        </div>
      </div>
      <div class="top-actions">
        <span id="scan-status" class="status-text"><?= $ipscopeI18n->translate("ui.snapshot_not_loaded") ?></span>
        <button id="scan-button" class="button button-primary" type="button"><?= htmlspecialchars($ipscopeI18n->translate("common.scan_now"), ENT_QUOTES, "UTF-8") ?></button>
      </div>
    </header>

    <nav class="workspace-nav" aria-label="<?= htmlspecialchars($ipscopeI18n->translate("ui.ipscope_sections"), ENT_QUOTES, "UTF-8") ?>">
      <button class="workspace-tab is-active" type="button" data-view="overview"><?= $ipscopeI18n->translate("ui.overview") ?></button>
      <button class="workspace-tab" type="button" data-view="addresses"><?= $ipscopeI18n->translate("ui.ip_manager") ?></button>
      <button class="workspace-tab" type="button" data-view="templates"><?= $ipscopeI18n->translate("ui.templates") ?></button>
      <button class="workspace-tab" type="button" data-view="conflicts"><?= $ipscopeI18n->translate("ui.conflicts") ?></button>
      <button class="workspace-tab" type="button" data-view="containers"><?= $ipscopeI18n->translate("ui.containers") ?></button>
      <button class="workspace-tab" type="button" data-view="exclusions"><?= $ipscopeI18n->translate("ui.exclusions") ?></button>
      <button class="workspace-tab" type="button" data-view="recommendations"><?= $ipscopeI18n->translate("ui.ip_suggestions") ?></button>
      <button class="workspace-tab" type="button" data-view="about"><?= $ipscopeI18n->translate("ui.about") ?></button>
    </nav>

    <section class="view-panel is-active" data-panel="overview">
    <section class="summary-grid" aria-label="<?= htmlspecialchars($ipscopeI18n->translate("ui.inventory_summary"), ENT_QUOTES, "UTF-8") ?>">
      <article class="summary-card">
        <span class="summary-label"><?= $ipscopeI18n->translate("ui.networks") ?></span>
        <strong id="metric-networks">—</strong>
        <span class="summary-note"><?= $ipscopeI18n->translate("ui.docker_networks") ?></span>
      </article>
      <article class="summary-card">
        <span class="summary-label"><?= $ipscopeI18n->translate("ui.containers") ?></span>
        <strong id="metric-containers">—</strong>
        <span class="summary-note"><?= $ipscopeI18n->translate("ui.running_and_stopped") ?></span>
      </article>
      <article class="summary-card">
        <span class="summary-label"><?= $ipscopeI18n->translate("ui.known_ips") ?></span>
        <strong id="metric-reservations">—</strong>
        <span class="summary-note"><?= $ipscopeI18n->translate("ui.occupied_and_reserved_addresses") ?></span>
      </article>
      <article class="summary-card summary-card-alert">
        <span class="summary-label"><?= $ipscopeI18n->translate("ui.conflicts") ?></span>
        <strong id="metric-conflicts">—</strong>
        <span class="summary-note"><?= $ipscopeI18n->translate("ui.duplicate_or_mismatched_claims") ?></span>
      </article>
    </section>

    <section id="notice" class="notice" hidden></section>

    <section class="panel overview-panel">
      <div class="panel-heading">
        <div><h2><?= $ipscopeI18n->translate("ui.network_overview") ?></h2><p><?= $ipscopeI18n->translate("ui.managed_address_pools_and_current_inventory_health") ?></p></div>
      </div>
      <div id="network-overview" class="network-overview"></div>
    </section>
    </section>

    <section class="view-panel" data-panel="addresses">
    <section class="panel">
      <div class="panel-heading">
        <div>
          <h2><?= $ipscopeI18n->translate("ui.network_inventory") ?></h2>
          <p><?= $ipscopeI18n->translate("ui.known_addresses_grouped_by_docker_network") ?></p>
        </div>
      </div>
      <div class="network-toolbar">
        <div class="address-controls">
          <input id="address-filter" class="address-filter" type="search" placeholder="<?= htmlspecialchars($ipscopeI18n->translate("ui.search_ip_or_container"), ENT_QUOTES, "UTF-8") ?>" autocomplete="off">
          <select id="status-filter" class="status-filter" aria-label="<?= htmlspecialchars($ipscopeI18n->translate("ui.filter_by_address_status"), ENT_QUOTES, "UTF-8") ?>">
            <option value="all"><?= $ipscopeI18n->translate("ui.all_statuses") ?></option>
            <option value="available"><?= $ipscopeI18n->translate("ui.available") ?></option>
            <option value="occupied"><?= $ipscopeI18n->translate("ui.occupied") ?></option>
            <option value="reserved"><?= $ipscopeI18n->translate("ui.reserved") ?></option>
            <option value="system"><?= $ipscopeI18n->translate("ui.system") ?></option>
            <option value="conflict"><?= $ipscopeI18n->translate("ui.conflict") ?></option>
            <option value="excluded"><?= $ipscopeI18n->translate("ui.excluded") ?></option>
          </select>
        </div>
        <div class="address-list-tools">
          <label class="page-size-label"><?= $ipscopeI18n->translate("ui.rows") ?>
            <select id="page-size" class="status-filter"><option value="25">25</option><option value="50">50</option><option value="100">100</option></select>
          </label>
          <span id="address-count" class="network-count">—</span>
        </div>
      </div>
      <div id="network-list" class="network-list">
        <div class="empty-state"><?= $ipscopeI18n->translate("ui.run_a_scan_to_collect_docker_network_data") ?></div>
      </div>
    </section>

    </section>

    <section class="view-panel" data-panel="templates">
    <section class="panel">
      <div class="panel-heading">
        <div>
          <h2><?= $ipscopeI18n->translate("ui.template_library_audit") ?></h2>
          <p><?= $ipscopeI18n->translate("ui.saved_templates_without_a_matching_docker_container_") ?></p>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th><?= $ipscopeI18n->translate("ui.template") ?></th><th><?= $ipscopeI18n->translate("ui.network") ?></th><th><?= $ipscopeI18n->translate("ui.saved_ipv4") ?></th><th><?= $ipscopeI18n->translate("ui.audit") ?></th></tr></thead>
          <tbody id="template-list"><tr><td colspan="4" class="table-empty"><?= $ipscopeI18n->translate("ui.no_snapshot_loaded") ?></td></tr></tbody>
        </table>
      </div>
    </section>

    </section>

    <section class="view-panel" data-panel="conflicts">
    <section class="panel">
      <div class="panel-heading">
        <div>
          <h2><?= $ipscopeI18n->translate("ui.conflicts") ?></h2>
          <p><?= $ipscopeI18n->translate("ui.address_ownership_issues_requiring_review") ?></p>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th><?= $ipscopeI18n->translate("ui.type") ?></th><th><?= $ipscopeI18n->translate("ui.network") ?></th><th><?= $ipscopeI18n->translate("ui.address") ?></th><th><?= $ipscopeI18n->translate("ui.details") ?></th></tr></thead>
          <tbody id="conflict-list"><tr><td colspan="4" class="table-empty">No snapshot loaded.</td></tr></tbody>
        </table>
      </div>
    </section>

    </section>

    <section class="view-panel" data-panel="containers">
    <section class="panel">
      <div class="panel-heading">
        <div>
          <h2><?= $ipscopeI18n->translate("ui.containers") ?></h2>
          <p><?= $ipscopeI18n->translate("ui.current_docker_container_state_and_attached_addresse") ?></p>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th><?= $ipscopeI18n->translate("ui.container") ?></th><th><?= $ipscopeI18n->translate("ui.state") ?></th><th><?= $ipscopeI18n->translate("ui.network") ?></th><th><?= $ipscopeI18n->translate("ui.ipv4") ?></th></tr></thead>
          <tbody id="container-list"><tr><td colspan="4" class="table-empty">No snapshot loaded.</td></tr></tbody>
        </table>
      </div>
    </section>

    </section>


    <section class="view-panel" data-panel="exclusions">
      <section class="panel exclusion-panel">
        <div class="panel-heading">
          <div>
            <h2><?= $ipscopeI18n->translate("ui.ip_exclusion_rules") ?></h2>
            <p><?= $ipscopeI18n->translate("ui.keep_selected_ipv4_addresses_or_subnets_out_of_the_a") ?></p>
          </div>
        </div>
        <form id="exclusion-form" class="exclusion-form">
          <label><?= $ipscopeI18n->translate("ui.docker_network") ?>
            <select id="exclusion-network" required>
              <option value="*"><?= $ipscopeI18n->translate("ui.all_networks") ?></option>
            </select>
          </label>
          <label><?= $ipscopeI18n->translate("ui.rule_type") ?>
            <select id="exclusion-type">
              <option value="ip"><?= $ipscopeI18n->translate("ui.single_ipv4") ?></option>
              <option value="cidr"><?= $ipscopeI18n->translate("ui.ipv4_subnet_cidr") ?></option>
            </select>
          </label>
          <label class="exclusion-value-field"><?= $ipscopeI18n->translate("ui.address_subnet") ?>
            <input id="exclusion-value" type="text" placeholder="192.168.50.20" maxlength="32" required>
          </label>
          <label class="exclusion-reason-field"><?= $ipscopeI18n->translate("ui.reason") ?>
            <input id="exclusion-reason" type="text" placeholder="<?= htmlspecialchars($ipscopeI18n->translate("ui.e_g_dhcp_reservation"), ENT_QUOTES, "UTF-8") ?>" maxlength="160" required>
          </label>
          <button id="exclusion-submit" class="button button-primary" type="submit"><?= $ipscopeI18n->translate("ui.add_rule") ?></button>
        </form>
        <div id="exclusion-feedback" class="exclusion-feedback" role="status" aria-live="polite" hidden></div>
        <div class="exclusion-summary">
          <span><strong id="exclusion-total">0</strong> <?= $ipscopeI18n->translate("ui.rules") ?></span>
          <span><strong id="exclusion-active">0</strong> <?= $ipscopeI18n->translate("ui.enabled") ?></span>
          <span><strong id="exclusion-hit-count">0</strong> <?= $ipscopeI18n->translate("ui.rule_matches") ?></span>
          <span><strong id="exclusion-in-use-count">0</strong> <?= $ipscopeI18n->translate("ui.in_use_matches") ?></span>
          <span><strong id="exclusion-overlap-count">0</strong> <?= $ipscopeI18n->translate("ui.overlapping_pairs") ?></span>
        </div>
        <div class="exclusion-bulk-toolbar">
          <label class="exclusion-select-all"><input id="exclusion-select-all" type="checkbox"> <?= $ipscopeI18n->translate("ui.select_all") ?></label>
          <span id="exclusion-selected-count">0 <?= $ipscopeI18n->translate("ui.selected") ?></span>
          <div class="exclusion-bulk-actions">
            <button type="button" class="exclusion-action" data-exclusion-bulk="enable" disabled><?= $ipscopeI18n->translate("ui.enable") ?></button>
            <button type="button" class="exclusion-action" data-exclusion-bulk="disable" disabled><?= $ipscopeI18n->translate("ui.disable") ?></button>
            <button type="button" class="exclusion-action is-danger" data-exclusion-bulk="delete" disabled><?= $ipscopeI18n->translate("ui.delete_selected") ?></button>
          </div>
        </div>
        <div class="table-wrap exclusion-table-wrap">
          <table class="exclusion-table">
            <thead><tr><th class="exclusion-check-cell"></th><th><?= $ipscopeI18n->translate("ui.network") ?></th><th><?= $ipscopeI18n->translate("ui.match") ?></th><th><?= $ipscopeI18n->translate("ui.reason") ?></th><th><?= $ipscopeI18n->translate("ui.coverage") ?></th><th><?= $ipscopeI18n->translate("ui.audit") ?></th><th><?= $ipscopeI18n->translate("ui.state") ?></th><th><?= $ipscopeI18n->translate("ui.actions") ?></th></tr></thead>
            <tbody id="exclusion-list"><tr><td colspan="8" class="table-empty"><?= $ipscopeI18n->translate("ui.loading_exclusion_rules") ?></td></tr></tbody>
          </table>
        </div>
        <div class="exclusion-footnote"><?= $ipscopeI18n->translate("ui.excluded_addresses_remain_visible_in_the_inventory_i") ?></div>
      </section>
    </section>


    <section class="view-panel" data-panel="recommendations">
      <section class="panel recommendation-panel">
        <div class="panel-heading">
          <div>
            <h2><?= $ipscopeI18n->translate("ui.available_ip_suggestions") ?></h2>
            <p><?= $ipscopeI18n->translate("ui.available_addresses_from_the_latest_docker_inventory") ?></p>
          </div>
        </div>
        <div class="recommendation-toolbar">
          <label>Docker network
            <select id="recommendation-network">
              <option value="*"><?= $ipscopeI18n->translate("ui.all_managed_networks") ?></option>
            </select>
          </label>
          <label><?= $ipscopeI18n->translate("ui.suggestions_per_network") ?>
            <select id="recommendation-limit">
              <option value="10">10</option>
              <option value="20" selected>20</option>
              <option value="50">50</option>
              <option value="100">100</option>
            </select>
          </label>
          <button id="recommendation-run" class="button recommendation-run" type="button"><?= $ipscopeI18n->translate("ui.find_available_ips") ?></button>
        </div>
        <div id="recommendation-summary" class="recommendation-summary">
          <?= $ipscopeI18n->translate("ui.select_a_network_and_request_suggestions_based_on_th") ?>
        </div>
        <div id="recommendation-results" class="recommendation-results">
          <div class="recommendation-empty"><?= $ipscopeI18n->translate("ui.no_suggestions_loaded_yet") ?></div>
        </div>
        <div class="recommendation-notice">
          <?= $ipscopeI18n->translate("ui.suggestions_are_advisory_only_and_are_listed_in_nume") ?>
        </div>
      </section>
    </section>

    <section class="view-panel about-view" data-panel="about">
      <section class="panel about-hero">
        <div class="about-brand-mark">IP</div>
        <div class="about-hero-copy">
          <div class="about-eyebrow">UNRAID DOCKER TOOL</div>
          <h2><?= $ipscopeI18n->translate("about.heading") ?></h2>
          <p><?= $ipscopeI18n->translate("about.tagline") ?></p>
        </div>
        <div class="about-version">
          <span><?= $ipscopeI18n->translate("about.version") ?></span>
          <strong><?= htmlspecialchars((string)($ipscopeVersion["version"] ?? $ipscopeI18n->translate("about.development")), ENT_QUOTES, "UTF-8") ?></strong>
          <small><?= $ipscopeI18n->translate("about.build") ?> · <?= htmlspecialchars((string)($ipscopeVersion["built_at"] ?? "development"), ENT_QUOTES, "UTF-8") ?></small>
        </div>
      </section>
      <section class="panel about-description">
        <p><?= $ipscopeI18n->translate("about.description") ?></p>
      </section>
      <section class="panel about-section">
        <div class="panel-heading"><div><h2><?= $ipscopeI18n->translate("about.features") ?></h2></div></div>
        <div class="about-feature-grid">
          <?php foreach (["about.feature_inventory", "about.feature_ownership", "about.feature_conflicts", "about.feature_exclusions", "about.feature_suggestions"] as $featureKey): ?>
            <div class="about-feature"><span class="about-feature-mark">✓</span><span><?= $ipscopeI18n->translate($featureKey) ?></span></div>
          <?php endforeach; ?>
        </div>
      </section>
      <section class="panel about-safety">
        <div class="about-safety-mark">i</div>
        <div><h2><?= $ipscopeI18n->translate("about.safety_title") ?></h2><p><?= $ipscopeI18n->translate("about.safety_description") ?></p></div>
      </section>
      <section class="about-meta-grid">
        <article class="panel about-meta-card"><span><?= $ipscopeI18n->translate("about.project") ?></span><a href="https://github.com/burncode/IPScope" target="_blank" rel="noopener noreferrer">github.com/burncode/IPScope</a><small><?= $ipscopeI18n->translate("about.documentation") ?></small></article>
        <article class="panel about-meta-card"><span><?= $ipscopeI18n->translate("about.license") ?></span><strong>MIT</strong><small><?= $ipscopeI18n->translate("about.license_description") ?></small></article>
        <article class="panel about-meta-card"><span><?= $ipscopeI18n->translate("about.author") ?></span><strong>burncode</strong><small><?= $ipscopeI18n->translate("about.copyright") ?></small></article>
        <article class="panel about-meta-card"><span><?= $ipscopeI18n->translate("about.runtime") ?></span><strong>Unraid</strong><small><?= $ipscopeI18n->translate("about.runtime_description") ?></small></article>
      </section>
    </section>

    <footer class="footer">
      <span id="generated-at"><?= $ipscopeI18n->translate("ui.no_snapshot") ?></span>
      <span><?= $ipscopeI18n->translate("ui.read_only_no_docker_configuration_changes") ?></span>
    </footer>
    <div id="copy-toast" class="copy-toast" role="status" aria-live="polite" hidden></div>
  </main>
  <script>window.IPScopeI18nConfig = <?= json_encode($ipscopeI18nConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
  <script src="/plugins/ipscope/src/www/assets/i18n.js" defer></script>
  <script src="/plugins/ipscope/src/www/assets/ipscope.js" defer></script>
</body>
</html>
