<?php
declare(strict_types=1);

?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>IPScope</title>
  <link rel="stylesheet" href="assets/ipscope.css">
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
        <span class="summary-label">Reservations</span>
        <strong id="metric-reservations">—</strong>
        <span class="summary-note">Known configured addresses</span>
      </article>
      <article class="summary-card summary-card-alert">
        <span class="summary-label">Conflicts</span>
        <strong id="metric-conflicts">—</strong>
        <span class="summary-note">Duplicate or mismatched claims</span>
      </article>
    </section>

    <section id="notice" class="notice" hidden></section>

    <section class="panel">
      <div class="panel-heading">
        <div>
          <h2>Network inventory</h2>
          <p>Known addresses grouped by Docker network</p>
        </div>
      </div>
      <div id="network-list" class="network-list">
        <div class="empty-state">Run a scan to collect Docker network data.</div>
      </div>
    </section>

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

    <footer class="footer">
      <span id="generated-at">No snapshot</span>
      <span>Read-only · No Docker configuration changes</span>
    </footer>
  </main>
  <script src="assets/ipscope.js" defer></script>
</body>
</html>
