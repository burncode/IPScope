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
          <p>Docker IPv4 inventory</p>
        </div>
      </div>
      <div class="top-actions">
        <span id="scan-status" class="status-text">Snapshot not loaded</span>
        <button id="scan-button" class="button button-primary" type="button">Scan now</button>
      </div>
    </header>

    <nav class="workspace-nav" aria-label="IPScope sections">
      <button class="workspace-tab is-active" type="button" data-view="overview">Overview</button>
      <button class="workspace-tab" type="button" data-view="addresses">IP Manager</button>
      <button class="workspace-tab" type="button" data-view="templates">Templates</button>
      <button class="workspace-tab" type="button" data-view="conflicts">Conflicts</button>
      <button class="workspace-tab" type="button" data-view="containers">Containers</button>
      <button class="workspace-tab" type="button" data-view="exclusions">Exclusions</button>
      <button class="workspace-tab" type="button" data-view="recommendations">IP Suggestions</button>
    </nav>

    <section class="view-panel is-active" data-panel="overview">
    <section class="summary-grid" aria-label="Inventory summary">
      <article class="summary-card">
        <span class="summary-label">Networks</span>
        <strong id="metric-networks">—</strong>
        <span class="summary-note">Docker networks</span>
      </article>
      <article class="summary-card">
        <span class="summary-label">Containers</span>
        <strong id="metric-containers">—</strong>
        <span class="summary-note">Running and stopped</span>
      </article>
      <article class="summary-card">
        <span class="summary-label">Known IPs</span>
        <strong id="metric-reservations">—</strong>
        <span class="summary-note">Occupied and reserved addresses</span>
      </article>
      <article class="summary-card summary-card-alert">
        <span class="summary-label">Conflicts</span>
        <strong id="metric-conflicts">—</strong>
        <span class="summary-note">Duplicate or mismatched claims</span>
      </article>
    </section>

    <section id="notice" class="notice" hidden></section>

    <section class="panel overview-panel">
      <div class="panel-heading">
        <div><h2>Network overview</h2><p>Managed address pools and current inventory health</p></div>
      </div>
      <div id="network-overview" class="network-overview"></div>
    </section>
    </section>

    <section class="view-panel" data-panel="addresses">
    <section class="panel">
      <div class="panel-heading">
        <div>
          <h2>Network inventory</h2>
          <p>Known addresses grouped by Docker network</p>
        </div>
      </div>
      <div class="network-toolbar">
        <div class="address-controls">
          <input id="address-filter" class="address-filter" type="search" placeholder="Search IP or container…" autocomplete="off">
          <select id="status-filter" class="status-filter" aria-label="Filter by address status">
            <option value="all">All statuses</option>
            <option value="available">Available</option>
            <option value="occupied">Occupied</option>
            <option value="reserved">Reserved</option>
            <option value="system">System</option>
            <option value="conflict">Conflict</option>
            <option value="excluded">Excluded</option>
          </select>
        </div>
        <div class="address-list-tools">
          <label class="page-size-label">Rows
            <select id="page-size" class="status-filter"><option value="25">25</option><option value="50">50</option><option value="100">100</option></select>
          </label>
          <span id="address-count" class="network-count">—</span>
        </div>
      </div>
      <div id="network-list" class="network-list">
        <div class="empty-state">Run a scan to collect Docker network data.</div>
      </div>
    </section>

    </section>

    <section class="view-panel" data-panel="templates">
    <section class="panel">
      <div class="panel-heading">
        <div>
          <h2>Template library audit</h2>
          <p>Saved templates without a matching Docker container, plus duplicate template IPs</p>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Template</th><th>Network</th><th>Saved IPv4</th><th>Audit</th></tr></thead>
          <tbody id="template-list"><tr><td colspan="4" class="table-empty">No snapshot loaded.</td></tr></tbody>
        </table>
      </div>
    </section>

    </section>

    <section class="view-panel" data-panel="conflicts">
    <section class="panel">
      <div class="panel-heading">
        <div>
          <h2>Conflicts</h2>
          <p>Address ownership issues requiring review</p>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Type</th><th>Network</th><th>Address</th><th>Details</th></tr></thead>
          <tbody id="conflict-list"><tr><td colspan="4" class="table-empty">No snapshot loaded.</td></tr></tbody>
        </table>
      </div>
    </section>

    </section>

    <section class="view-panel" data-panel="containers">
    <section class="panel">
      <div class="panel-heading">
        <div>
          <h2>Containers</h2>
          <p>Current Docker container state and attached addresses</p>
        </div>
      </div>
      <div class="table-wrap">
        <table>
          <thead><tr><th>Container</th><th>State</th><th>Network</th><th>IPv4</th></tr></thead>
          <tbody id="container-list"><tr><td colspan="4" class="table-empty">No snapshot loaded.</td></tr></tbody>
        </table>
      </div>
    </section>

    </section>


    <section class="view-panel" data-panel="exclusions">
      <section class="panel exclusion-panel">
        <div class="panel-heading">
          <div>
            <h2>IP exclusion rules</h2>
            <p>Keep selected IPv4 addresses or subnets out of the available-address suggestions</p>
          </div>
        </div>
        <form id="exclusion-form" class="exclusion-form">
          <label>Docker network
            <select id="exclusion-network" required>
              <option value="*">All networks</option>
            </select>
          </label>
          <label>Rule type
            <select id="exclusion-type">
              <option value="ip">Single IPv4</option>
              <option value="cidr">IPv4 subnet (CIDR)</option>
            </select>
          </label>
          <label class="exclusion-value-field">Address / subnet
            <input id="exclusion-value" type="text" placeholder="192.168.50.20" maxlength="32" required>
          </label>
          <label class="exclusion-reason-field">Reason
            <input id="exclusion-reason" type="text" placeholder="e.g. DHCP reservation" maxlength="160" required>
          </label>
          <button id="exclusion-submit" class="button button-primary" type="submit">Add rule</button>
        </form>
        <div id="exclusion-feedback" class="exclusion-feedback" role="status" aria-live="polite" hidden></div>
        <div class="exclusion-summary">
          <span><strong id="exclusion-total">0</strong> rules</span>
          <span><strong id="exclusion-active">0</strong> enabled</span>
          <span><strong id="exclusion-hit-count">0</strong> rule matches</span>
          <span><strong id="exclusion-in-use-count">0</strong> in-use matches</span>
          <span><strong id="exclusion-overlap-count">0</strong> overlapping pairs</span>
        </div>
        <div class="exclusion-bulk-toolbar">
          <label class="exclusion-select-all"><input id="exclusion-select-all" type="checkbox"> Select all</label>
          <span id="exclusion-selected-count">0 selected</span>
          <div class="exclusion-bulk-actions">
            <button type="button" class="exclusion-action" data-exclusion-bulk="enable" disabled>Enable</button>
            <button type="button" class="exclusion-action" data-exclusion-bulk="disable" disabled>Disable</button>
            <button type="button" class="exclusion-action is-danger" data-exclusion-bulk="delete" disabled>Delete selected</button>
          </div>
        </div>
        <div class="table-wrap exclusion-table-wrap">
          <table class="exclusion-table">
            <thead><tr><th class="exclusion-check-cell"></th><th>Network</th><th>Match</th><th>Reason</th><th>Coverage</th><th>Audit</th><th>State</th><th>Actions</th></tr></thead>
            <tbody id="exclusion-list"><tr><td colspan="8" class="table-empty">Loading exclusion rules…</td></tr></tbody>
          </table>
        </div>
        <div class="exclusion-footnote">Excluded addresses remain visible in the inventory. If an excluded address is already occupied, reserved or assigned to a system service, IPScope reports it as a conflict instead of hiding the owner.</div>
      </section>
    </section>


    <section class="view-panel" data-panel="recommendations">
      <section class="panel recommendation-panel">
        <div class="panel-heading">
          <div>
            <h2>Available IP suggestions</h2>
            <p>Available addresses from the latest Docker inventory and enabled exclusion rules</p>
          </div>
        </div>
        <div class="recommendation-toolbar">
          <label>Docker network
            <select id="recommendation-network">
              <option value="*">All managed networks</option>
            </select>
          </label>
          <label>Suggestions per network
            <select id="recommendation-limit">
              <option value="10">10</option>
              <option value="20" selected>20</option>
              <option value="50">50</option>
              <option value="100">100</option>
            </select>
          </label>
          <button id="recommendation-run" class="button recommendation-run" type="button">Find available IPs</button>
        </div>
        <div id="recommendation-summary" class="recommendation-summary">
          Select a network and request suggestions based on the latest snapshot.
        </div>
        <div id="recommendation-results" class="recommendation-results">
          <div class="recommendation-empty">No suggestions loaded yet.</div>
        </div>
        <div class="recommendation-notice">
          Suggestions are advisory only and are listed in numeric IP order, not safety rank. IPScope can identify addresses not claimed by Docker, saved templates, system addresses or enabled exclusions, but cannot see DHCP leases, offline devices or hosts outside Docker. Verify an address on your LAN before assigning it.
        </div>
      </section>
    </section>

    <footer class="footer">
      <span id="generated-at">No snapshot</span>
      <span>Read-only · No Docker configuration changes</span>
    </footer>
    <div id="copy-toast" class="copy-toast" role="status" aria-live="polite" hidden></div>
  </main>
  <script src="/plugins/ipscope/src/www/assets/ipscope.js" defer></script>
</body>
</html>
