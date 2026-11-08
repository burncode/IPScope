<?php
declare(strict_types=1);

?><!doctype html>
<html lang="en">
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
          <p><?= _("Docker IPv4 inventory") ?></p>
        </div>
      </div>
      <div class="top-actions">
        <span id="scan-status" class="status-text"><?= _("Snapshot not loaded") ?></span>
        <button id="scan-button" class="button button-primary" type="button"><?= _("Scan now") ?></button>
      </div>
    </header>

    <nav class="workspace-nav" aria-label="<?= htmlspecialchars(_("IPScope sections"), ENT_QUOTES, "UTF-8") ?>">
      <button class="workspace-tab is-active" type="button" data-view="overview"><?= _("Overview") ?></button>
      <button class="workspace-tab" type="button" data-view="addresses"><?= _("IP Manager") ?></button>
      <button class="workspace-tab" type="button" data-view="templates"><?= _("Templates") ?></button>
      <button class="workspace-tab" type="button" data-view="conflicts"><?= _("Conflicts") ?></button>
      <button class="workspace-tab" type="button" data-view="containers"><?= _("Containers") ?></button>
      <button class="workspace-tab" type="button" data-view="exclusions"><?= _("Exclusions") ?></button>
      <button class="workspace-tab" type="button" data-view="recommendations"><?= _("IP Suggestions") ?></button>
    </nav>

    <section class="view-panel is-active" data-panel="overview">
    <section class="summary-grid" aria-label="<?= htmlspecialchars(_("Inventory summary"), ENT_QUOTES, "UTF-8") ?>">
      <article class="summary-card">
        <span class="summary-label"><?= _("Networks") ?></span>
        <strong id="metric-networks">—</strong>
        <span class="summary-note"><?= _("Docker networks") ?></span>
      </article>
      <article class="summary-card">
        <span class="summary-label"><?= _("Containers") ?></span>
        <strong id="metric-containers">—</strong>
        <span class="summary-note"><?= _("Running and stopped") ?></span>
      </article>
      <article class="summary-card">
        <span class="summary-label"><?= _("Known IPs") ?></span>
        <strong id="metric-reservations">—</strong>
        <span class="summary-note"><?= _("Occupied and reserved addresses") ?></span>
      </article>
      <article class="summary-card summary-card-alert">
        <span class="summary-label"><?= _("Conflicts") ?></span>
        <strong id="metric-conflicts">—</strong>
        <span class="summary-note"><?= _("Duplicate or mismatched claims") ?></span>
      </article>
    </section>

    <section id="notice" class="notice" hidden></section>

    <section class="panel overview-panel">
      <div class="panel-heading">
        <div><h2><?= _("Network overview") ?></h2><p><?= _("Managed address pools and current inventory health") ?></p></div>
      </div>
      <div id="network-overview" class="network-overview"></div>
    </section>
    </section>

    <section class="view-panel" data-panel="addresses">
    <section class="panel">
      <div class="panel-heading">
        <div>
          <h2><?= _("Network inventory") ?></h2>
          <p><?= _("Known addresses grouped by Docker network") ?></p>
        </div>
      </div>
      <div class="network-toolbar">
        <div class="address-controls">
          <input id="address-filter" class="address-filter" type="search" placeholder="<?= htmlspecialchars(_("Search IP or container…"), ENT_QUOTES, "UTF-8") ?>" autocomplete="off">
          <select id="status-filter" class="status-filter" aria-label="<?= htmlspecialchars(_("Filter by address status"), ENT_QUOTES, "UTF-8") ?>">
            <option value="all"><?= _("All statuses") ?></option>
            <option value="available"><?= _("Available") ?></option>
            <option value="occupied"><?= _("Occupied") ?></option>
            <option value="reserved"><?= _("Reserved") ?></option>
            <option value="system"><?= _("System") ?></option>
            <option value="conflict"><?= _("Conflict") ?></option>
            <option value="excluded"><?= _("Excluded") ?></option>
          </select>
        </div>
        <div class="address-list-tools">
          <label class="page-size-label"><?= _("Rows") ?>
            <select id="page-size" class="status-filter"><option value="25">25</option><option value="50">50</option><option value="100">100</option></select>
          </label>
          <span id="address-count" class="network-count">—</span>
        </div>
      </div>
      <div id="network-list" class="network-list">
        <div class="empty-state"><?= _("Run a scan to collect Docker network data.") ?></div>
      </div>
    </section>

    </section>

    <section class="view-panel" data-panel="templates">
    <section class="panel">
      <div class="panel-heading">
        <div>
          <h2><?= _("Template library audit") ?></h2>
          <p><?= _("Saved templates without a matching Docker container, plus duplicate template IPs") ?></p>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th><?= _("Template") ?></th><th><?= _("Network") ?></th><th><?= _("Saved IPv4") ?></th><th><?= _("Audit") ?></th></tr></thead>
          <tbody id="template-list"><tr><td colspan="4" class="table-empty"><?= _("No snapshot loaded.") ?></td></tr></tbody>
        </table>
      </div>
    </section>

    </section>

    <section class="view-panel" data-panel="conflicts">
    <section class="panel">
      <div class="panel-heading">
        <div>
          <h2><?= _("Conflicts") ?></h2>
          <p><?= _("Address ownership issues requiring review") ?></p>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th><?= _("Type") ?></th><th><?= _("Network") ?></th><th><?= _("Address") ?></th><th><?= _("Details") ?></th></tr></thead>
          <tbody id="conflict-list"><tr><td colspan="4" class="table-empty">No snapshot loaded.</td></tr></tbody>
        </table>
      </div>
    </section>

    </section>

    <section class="view-panel" data-panel="containers">
    <section class="panel">
      <div class="panel-heading">
        <div>
          <h2><?= _("Containers") ?></h2>
          <p><?= _("Current Docker container state and attached addresses") ?></p>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th><?= _("Container") ?></th><th><?= _("State") ?></th><th><?= _("Network") ?></th><th><?= _("IPv4") ?></th></tr></thead>
          <tbody id="container-list"><tr><td colspan="4" class="table-empty">No snapshot loaded.</td></tr></tbody>
        </table>
      </div>
    </section>

    </section>


    <section class="view-panel" data-panel="exclusions">
      <section class="panel exclusion-panel">
        <div class="panel-heading">
          <div>
            <h2><?= _("IP exclusion rules") ?></h2>
            <p><?= _("Keep selected IPv4 addresses or subnets out of the available-address suggestions") ?></p>
          </div>
        </div>
        <form id="exclusion-form" class="exclusion-form">
          <label><?= _("Docker network") ?>
            <select id="exclusion-network" required>
              <option value="*"><?= _("All networks") ?></option>
            </select>
          </label>
          <label><?= _("Rule type") ?>
            <select id="exclusion-type">
              <option value="ip"><?= _("Single IPv4") ?></option>
              <option value="cidr"><?= _("IPv4 subnet (CIDR)") ?></option>
            </select>
          </label>
          <label class="exclusion-value-field"><?= _("Address / subnet") ?>
            <input id="exclusion-value" type="text" placeholder="192.168.50.20" maxlength="32" required>
          </label>
          <label class="exclusion-reason-field"><?= _("Reason") ?>
            <input id="exclusion-reason" type="text" placeholder="<?= htmlspecialchars(_("e.g. DHCP reservation"), ENT_QUOTES, "UTF-8") ?>" maxlength="160" required>
          </label>
          <button id="exclusion-submit" class="button button-primary" type="submit"><?= _("Add rule") ?></button>
        </form>
        <div id="exclusion-feedback" class="exclusion-feedback" role="status" aria-live="polite" hidden></div>
        <div class="exclusion-summary">
          <span><strong id="exclusion-total">0</strong> <?= _("rules") ?></span>
          <span><strong id="exclusion-active">0</strong> <?= _("enabled") ?></span>
          <span><strong id="exclusion-hit-count">0</strong> <?= _("rule matches") ?></span>
          <span><strong id="exclusion-in-use-count">0</strong> <?= _("in-use matches") ?></span>
          <span><strong id="exclusion-overlap-count">0</strong> <?= _("overlapping pairs") ?></span>
        </div>
        <div class="exclusion-bulk-toolbar">
          <label class="exclusion-select-all"><input id="exclusion-select-all" type="checkbox"> <?= _("Select all") ?></label>
          <span id="exclusion-selected-count">0 <?= _("selected") ?></span>
          <div class="exclusion-bulk-actions">
            <button type="button" class="exclusion-action" data-exclusion-bulk="enable" disabled><?= _("Enable") ?></button>
            <button type="button" class="exclusion-action" data-exclusion-bulk="disable" disabled><?= _("Disable") ?></button>
            <button type="button" class="exclusion-action is-danger" data-exclusion-bulk="delete" disabled><?= _("Delete selected") ?></button>
          </div>
        </div>
        <div class="table-wrap exclusion-table-wrap">
          <table class="exclusion-table">
            <thead><tr><th class="exclusion-check-cell"></th><th><?= _("Network") ?></th><th><?= _("Match") ?></th><th><?= _("Reason") ?></th><th><?= _("Coverage") ?></th><th><?= _("Audit") ?></th><th><?= _("State") ?></th><th><?= _("Actions") ?></th></tr></thead>
            <tbody id="exclusion-list"><tr><td colspan="8" class="table-empty"><?= _("Loading exclusion rules…") ?></td></tr></tbody>
          </table>
        </div>
        <div class="exclusion-footnote"><?= _("Excluded addresses remain visible in the inventory. If an excluded address is already occupied, reserved or assigned to a system service, IPScope reports it as a conflict instead of hiding the owner.") ?></div>
      </section>
    </section>


    <section class="view-panel" data-panel="recommendations">
      <section class="panel recommendation-panel">
        <div class="panel-heading">
          <div>
            <h2><?= _("Available IP suggestions") ?></h2>
            <p><?= _("Available addresses from the latest Docker inventory and enabled exclusion rules") ?></p>
          </div>
        </div>
        <div class="recommendation-toolbar">
          <label>Docker network
            <select id="recommendation-network">
              <option value="*"><?= _("All managed networks") ?></option>
            </select>
          </label>
          <label><?= _("Suggestions per network") ?>
            <select id="recommendation-limit">
              <option value="10">10</option>
              <option value="20" selected>20</option>
              <option value="50">50</option>
              <option value="100">100</option>
            </select>
          </label>
          <button id="recommendation-run" class="button recommendation-run" type="button"><?= _("Find available IPs") ?></button>
        </div>
        <div id="recommendation-summary" class="recommendation-summary">
          <?= _("Select a network and request suggestions based on the latest snapshot.") ?>
        </div>
        <div id="recommendation-results" class="recommendation-results">
          <div class="recommendation-empty"><?= _("No suggestions loaded yet.") ?></div>
        </div>
        <div class="recommendation-notice">
          <?= _("Suggestions are advisory only and are listed in numeric IP order, not safety rank. IPScope can identify addresses not claimed by Docker, saved templates, system addresses or enabled exclusions, but cannot see DHCP leases, offline devices or hosts outside Docker. Verify an address on your LAN before assigning it.") ?>
        </div>
      </section>
    </section>

    <footer class="footer">
      <span id="generated-at"><?= _("No snapshot") ?></span>
      <span><?= _("Read-only · No Docker configuration changes") ?></span>
    </footer>
    <div id="copy-toast" class="copy-toast" role="status" aria-live="polite" hidden></div>
  </main>
  <script src="/plugins/ipscope/src/www/assets/ipscope.js" defer></script>
</body>
</html>
