(function () {
  "use strict";

  var apiUrl = "/plugins/ipscope/api.php";
  var scanButton = document.getElementById("scan-button");
  var statusNode = document.getElementById("scan-status");
  var noticeNode = document.getElementById("notice");
  var addressFilter = document.getElementById("address-filter");
  var statusFilter = document.getElementById("status-filter");
  var currentSnapshot = null;

  function escapeHtml(value) {
    return String(value == null ? "" : value).replace(/[&<>"']/g, function (char) {
      return ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" })[char];
    });
  }

  function setNotice(message) {
    noticeNode.textContent = message || "";
    noticeNode.hidden = !message;
  }

  function setMetric(id, value) {
    document.getElementById(id).textContent = String(value);
  }

  function readApiResponse(response) {
    return response.text().then(function (raw) {
      var body;
      try {
        body = raw === "" ? null : JSON.parse(raw);
      } catch (error) {
        var preview = raw.trim().slice(0, 240);
        throw new Error("API returned invalid JSON (HTTP " + response.status + "). " +
          (preview ? "Response: " + preview : "The response body was empty. Check the PHP server terminal and Unraid logs."));
      }
      if (!response.ok) {
        throw new Error((body && body.error) || ("API request failed (HTTP " + response.status + ")."));
      }
      if (!body || typeof body !== "object") {
        throw new Error("API returned an empty response (HTTP " + response.status + ").");
      }
      return body;
    });
  }

  function filterAddressRows(resetPages) {
    var query = addressFilter ? addressFilter.value.trim().toLowerCase() : "";
    var selectedStatus = statusFilter ? statusFilter.value : "all";
    var pageSizeNode = document.getElementById("page-size");
    var pageSize = pageSizeNode ? Number(pageSizeNode.value) : 25;
    var totalVisible = 0;
    document.querySelectorAll(".network-card").forEach(function (card) {
      if (resetPages) card.dataset.page = "0";
      var rows = Array.from(card.querySelectorAll(".address-row"));
      var filtered = rows.filter(function (row) {
        var matchesQuery = query === "" || row.getAttribute("data-search").indexOf(query) !== -1;
        var matchesStatus = selectedStatus === "all" || row.getAttribute("data-status") === selectedStatus;
        return matchesQuery && matchesStatus;
      });
      var pageCount = Math.max(1, Math.ceil(filtered.length / pageSize));
      var page = Math.min(Number(card.dataset.page || 0), pageCount - 1);
      card.dataset.page = String(page);
      var first = page * pageSize;
      rows.forEach(function (row) {
        var index = filtered.indexOf(row);
        var show = index >= first && index < first + pageSize;
        row.hidden = !show;
        var detailRow = row.nextElementSibling;
        if (detailRow && detailRow.classList.contains("address-detail-row")) {
          detailRow.hidden = !show || detailRow.hidden;
          if (!show) {
            var detailButton = row.querySelector("[data-toggle-details]");
            if (detailButton) detailButton.textContent = "Details";
          }
        }
      });
      totalVisible += filtered.length;
      var pager = card.querySelector(".address-pager");
      if (pager) {
        pager.querySelector(".pager-label").textContent = filtered.length ?
          (first + 1) + "–" + Math.min(first + pageSize, filtered.length) + " of " + filtered.length : "No matching addresses";
        pager.querySelector("[data-page-prev]").disabled = page === 0;
        pager.querySelector("[data-page-next]").disabled = page >= pageCount - 1;
      }
    });
    var total = document.querySelectorAll(".address-row").length;
    var countNode = document.getElementById("address-count");
    if (countNode) countNode.textContent = totalVisible + " / " + total + " addresses";
  }

  function render(snapshot) {
    currentSnapshot = snapshot;
    var summary = snapshot.summary || {};
    setMetric("metric-networks", summary.network_count || 0);
    setMetric("metric-containers", summary.container_count || 0);
    setMetric("metric-reservations", (snapshot.networks || []).reduce(function (total, network) {
      return total + (network.known_address_count || 0);
    }, 0));
    setMetric("metric-conflicts", summary.conflict_count || 0);

    var networks = snapshot.networks || [];
    document.getElementById("network-list").innerHTML = networks.map(function (network) {
      var addresses = network.known_addresses || [];
      var subnet = (network.ipv4 || []).map(function (item) { return item.subnet; }).filter(Boolean).join(", ");
      var rows = addresses.map(function (address) {
        var status = address.status || "reserved";
        var ownerNames = Array.from(new Set((address.owners || []).map(function (owner) {
          return owner.container;
        }).filter(Boolean)));
        var ownerText = ownerNames.length ? ownerNames.join(", ") : "—";
        var reason = address.system_reason ? address.system_reason.replace(/_/g, " ") : "";
        var searchText = [address.ip, status, ownerText, reason, network.name].join(" ").toLowerCase();
        var label = {
          available: "Available",
          occupied: "Occupied",
          reserved: "Reserved",
          system: "System",
          conflict: "Conflict"
        }[status] || status;
        var detailKey = network.name + "|" + address.ip;
        var ownerDetails = (address.owners || []).map(function (owner) {
          return '<div class="detail-owner"><strong>' + escapeHtml(owner.container) + '</strong>' +
            '<span>' + escapeHtml(owner.source || "unknown source") + '</span>' +
            '<span>' + escapeHtml(owner.kind || "unknown") + '</span>' +
            '<span>' + escapeHtml(owner.state || "unknown state") + '</span></div>';
        }).join("");
        var detailContent = '<div class="address-detail-content">' +
          '<div><span class="detail-label">Network</span><strong>' + escapeHtml(network.name) + '</strong></div>' +
          '<div><span class="detail-label">Address state</span><strong>' + escapeHtml(label) + '</strong></div>' +
          '<div><span class="detail-label">Runtime owners</span><strong>' + escapeHtml(address.runtime_owner_count || 0) + '</strong></div>' +
          '<div><span class="detail-label">Reservation owners</span><strong>' + escapeHtml(address.reservation_owner_count || 0) + '</strong></div>' +
          (reason ? '<div><span class="detail-label">System reason</span><strong>' + escapeHtml(reason) + '</strong></div>' : '') +
          '<div class="detail-owner-list">' + (ownerDetails || '<span class="muted-inline">No known owner. Candidate is based on Docker and saved-template data only.</span>') + '</div>' +
          '</div>';
        return '<tr class="address-row" data-status="' + escapeHtml(status) + '" data-search="' + escapeHtml(searchText) + '">' +
          '<td class="ip-cell">' + escapeHtml(address.ip) + '</td>' +
          '<td><span class="status-pill status-' + escapeHtml(status) + '">' + escapeHtml(label) + '</span></td>' +
          '<td>' + escapeHtml(ownerText) + (reason ? ' <span class="muted-inline">· ' + escapeHtml(reason) + '</span>' : '') + '</td>' +
          '<td class="address-actions"><button class="copy-ip" type="button" data-copy-ip="' + escapeHtml(address.ip) + '" aria-label="Copy ' + escapeHtml(address.ip) + '">Copy</button>' +
          '<button class="details-ip" type="button" data-toggle-details="' + escapeHtml(detailKey) + '">Details</button></td>' +
          '</tr><tr class="address-detail-row" data-detail-for="' + escapeHtml(detailKey) + '" hidden><td colspan="4">' + detailContent + '</td></tr>';
      }).join("");
      var managed = network.address_space_managed !== false;
      return '<article class="network-card" data-page="0">' +
        '<div class="network-head"><div><div class="network-name">' + escapeHtml(network.name) + '</div>' +
        '<div class="network-meta">' + escapeHtml(network.driver || "unknown driver") +
        (subnet ? " · " + escapeHtml(subnet) : "") +
        (managed ? "" : " · built-in network, address pool not managed") + '</div></div>' +
        '<span class="network-count">' + addresses.length + ' addresses</span></div>' +
        '<div class="table-wrap address-table-wrap"><table class="address-table"><colgroup><col class="col-ip"><col class="col-status"><col class="col-owner"><col class="col-actions"></colgroup><thead><tr><th>IPv4 address</th><th>Status</th><th>Owner / reason</th><th class="actions-heading">Actions</th></tr></thead>' +
        '<tbody>' + (rows || '<tr><td colspan="4" class="table-empty">No address records.</td></tr>') + '</tbody></table></div>' +
        '<div class="address-pager"><span class="pager-label">—</span><div><button type="button" class="pager-button" data-page-prev disabled>Previous</button><button type="button" class="pager-button" data-page-next>Next</button></div></div>' +
        '</article>';
    }).join("") || '<div class="empty-state">No Docker networks were discovered.</div>';
    filterAddressRows(true);

    document.getElementById("network-overview").innerHTML = networks.map(function (network) {
      var addresses = network.known_addresses || [];
      var counts = { available: 0, occupied: 0, reserved: 0, system: 0, conflict: 0 };
      addresses.forEach(function (address) { counts[address.status] = (counts[address.status] || 0) + 1; });
      var subnet = (network.ipv4 || []).map(function (item) { return item.subnet; }).filter(Boolean).join(", ");
      return '<article class="overview-network"><div><strong>' + escapeHtml(network.name) + '</strong><span>' +
        escapeHtml(subnet || network.driver || "No IPv4 subnet") + '</span></div>' +
        '<div class="overview-counts"><span><b>' + counts.available + '</b> available</span>' +
        '<span><b>' + counts.occupied + '</b> occupied</span><span><b>' + counts.reserved + '</b> reserved</span>' +
        '<span><b>' + counts.conflict + '</b> conflicts</span></div>' +
        '<button type="button" class="text-action" data-open-view="addresses">Manage IPs</button></article>';
    }).join("") || '<div class="empty-state">No networks found.</div>';

    var templates = snapshot.templates || [];
    var liveNames = {};
    (snapshot.containers || []).forEach(function (container) {
      liveNames[container.name] = true;
    });
    var templateIpOwners = {};
    templates.forEach(function (template) {
      (template.ips || []).forEach(function (ip) {
        var key = (template.network || "") + "|" + ip;
        if (!templateIpOwners[key]) templateIpOwners[key] = [];
        templateIpOwners[key].push(template.name);
      });
    });
    document.getElementById("template-list").innerHTML = templates.length ? templates.map(function (template) {
      var ips = template.ips || [];
      var isOrphan = !liveNames[template.name];
      var duplicateIps = ips.filter(function (ip) {
        return (templateIpOwners[(template.network || "") + "|" + ip] || []).length > 1;
      });
      var audit = [];
      if (isOrphan) audit.push('<span class="status-pill status-reserved">No Docker container</span>');
      if (duplicateIps.length) audit.push('<span class="status-pill status-conflict">Duplicate template IP</span>');
      if (!audit.length) audit.push('<span class="status-pill status-available">Matched</span>');
      return "<tr>" +
        "<td>" + escapeHtml(template.name) + "</td>" +
        "<td>" + escapeHtml(template.network || "—") + "</td>" +
        '<td class="ip-cell">' + escapeHtml(ips.join(", ") || "—") + "</td>" +
        "<td>" + audit.join(" ") + "</td></tr>";
    }).join("") : '<tr><td colspan="4" class="table-empty">No saved templates found.</td></tr>';

    var conflicts = snapshot.conflicts || [];
    document.getElementById("conflict-list").innerHTML = conflicts.length ? conflicts.map(function (item) {
      var details;
      if (item.type === "reservation_conflict") {
        details = "Stopped reservations: " + (item.reserved_containers || item.containers || []).join(", ");
      } else if (item.type === "active_reservation_collision") {
        details = "Running: " + (item.running_containers || []).join(", ") +
          " · Reserved: " + (item.reserved_containers || []).join(", ");
      } else if (item.type === "multiple_runtime_owners") {
        details = "Running containers: " + (item.running_containers || item.containers || []).join(", ");
      } else {
        details = (item.container || "") + " · template " + (item.template_ip || "") + " → runtime " + (item.runtime_ip || "");
      }
      var conflictLabel = item.type === "reservation_conflict" ? "Reservation conflict" :
        (item.type === "active_reservation_collision" ? "Active / reserved collision" :
        (item.type === "multiple_runtime_owners" ? "Multiple runtime owners" : item.type));
      return "<tr><td>" + escapeHtml(conflictLabel) + "</td><td>" + escapeHtml(item.network) +
        "</td><td>" + escapeHtml(item.ip || item.template_ip || "—") +
        "</td><td>" + escapeHtml(details) + "</td></tr>";
    }).join("") : '<tr><td colspan="4" class="table-empty">No known conflicts.</td></tr>';

    var containers = snapshot.containers || [];
    document.getElementById("container-list").innerHTML = containers.length ? containers.map(function (container) {
      var entries = Object.keys(container.networks || {}).map(function (name) {
        var item = container.networks[name] || {};
        return { name: name, ip: item.ipv4 || item.ipam_ipv4 || "—" };
      });
      if (!entries.length) entries.push({ name: "—", ip: "—" });
      return entries.map(function (entry, index) {
        return "<tr>" + (index === 0 ? '<td rowspan="' + entries.length + '">' + escapeHtml(container.name) + "</td>" +
          '<td rowspan="' + entries.length + '">' + escapeHtml(container.state) + "</td>" : "") +
          "<td>" + escapeHtml(entry.name) + "</td><td>" + escapeHtml(entry.ip) + "</td></tr>";
      }).join("");
    }).join("") : '<tr><td colspan="4" class="table-empty">No containers were discovered.</td></tr>';

    document.getElementById("generated-at").textContent = "Last scan: " + (snapshot.generated_at || "unknown");
    statusNode.textContent = "Snapshot loaded";
  }

  function requestSnapshot() {
    statusNode.textContent = "Loading snapshot…";
    fetch(apiUrl + "?action=snapshot", { credentials: "same-origin", headers: { "Accept": "application/json" } })
      .then(readApiResponse)
      .then(function (body) { render(body.snapshot); setNotice(""); })
      .catch(function (error) {
        statusNode.textContent = "Snapshot unavailable";
        setNotice(error.message);
      });
  }

  function runScan() {
    scanButton.disabled = true;
    scanButton.textContent = "Scanning…";
    statusNode.textContent = "Collecting Docker data…";
    setNotice("");

    var scanHeaders = { "Accept": "application/json" };
    // Unraid injects csrf_token globally into plugin pages. The auto-prepend
    // security check accepts it through the X-CSRF-Token request header.
    if (typeof csrf_token !== "undefined" && csrf_token) {
      scanHeaders["X-CSRF-Token"] = csrf_token;
    }

    fetch(apiUrl + "?action=scan", {
      method: "POST",
      credentials: "same-origin",
      headers: scanHeaders
    }).then(readApiResponse).then(function (body) {
      render(body.snapshot);
    }).catch(function (error) {
      statusNode.textContent = "Scan failed";
      setNotice(error.message);
    }).finally(function () {
      scanButton.disabled = false;
      scanButton.textContent = "Scan now";
    });
  }

  scanButton.addEventListener("click", runScan);
  if (addressFilter) addressFilter.addEventListener("input", function () { filterAddressRows(true); });
  if (statusFilter) statusFilter.addEventListener("change", function () { filterAddressRows(true); });
  var pageSizeNode = document.getElementById("page-size");
  if (pageSizeNode) pageSizeNode.addEventListener("change", function () { filterAddressRows(true); });

  function activateView(viewName) {
    document.querySelectorAll(".workspace-tab").forEach(function (tab) {
      tab.classList.toggle("is-active", tab.getAttribute("data-view") === viewName);
    });
    document.querySelectorAll(".view-panel").forEach(function (panel) {
      panel.classList.toggle("is-active", panel.getAttribute("data-panel") === viewName);
    });
  }
  document.querySelectorAll("[data-view], [data-open-view]").forEach(function (button) {
    button.addEventListener("click", function () {
      activateView(button.getAttribute("data-view") || button.getAttribute("data-open-view"));
    });
  });
  document.addEventListener("click", function (event) {
    var previous = event.target.closest("[data-page-prev]");
    var next = event.target.closest("[data-page-next]");
    if (!previous && !next) return;
    var card = event.target.closest(".network-card");
    if (!card) return;
    card.dataset.page = String(Math.max(0, Number(card.dataset.page || 0) + (next ? 1 : -1)));
    filterAddressRows(false);
  });
  document.addEventListener("click", function (event) {
    var detailsButton = event.target.closest("[data-toggle-details]");
    if (detailsButton) {
      var detailKey = detailsButton.getAttribute("data-toggle-details");
      var detailRow = Array.from(document.querySelectorAll(".address-detail-row")).find(function (row) {
        return row.getAttribute("data-detail-for") === detailKey;
      });
      if (detailRow) {
        detailRow.hidden = !detailRow.hidden;
        detailsButton.textContent = detailRow.hidden ? "Details" : "Hide";
      }
      return;
    }
    var button = event.target.closest("[data-copy-ip]");
    if (!button) return;
    var ip = button.getAttribute("data-copy-ip");
    function showCopyNotice(message, failed) {
      var toast = document.getElementById("copy-toast");
      if (!toast) return;
      toast.textContent = message;
      toast.classList.toggle("is-error", !!failed);
      toast.hidden = false;
      window.clearTimeout(toast._hideTimer);
      toast._hideTimer = window.setTimeout(function () { toast.hidden = true; }, 2200);
    }
    function fallbackCopy() {
      var field = document.createElement("textarea");
      field.value = ip;
      field.setAttribute("readonly", "");
      field.style.position = "fixed";
      field.style.opacity = "0";
      document.body.appendChild(field);
      field.select();
      var copied = false;
      try { copied = document.execCommand("copy"); } catch (error) { copied = false; }
      document.body.removeChild(field);
      return copied;
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(ip).then(function () {
        showCopyNotice("Copied " + ip, false);
      }).catch(function () {
        showCopyNotice(fallbackCopy() ? "Copied " + ip : "Copy failed. Select the IP manually.", !fallbackCopy);
      });
    } else {
      showCopyNotice(fallbackCopy() ? "Copied " + ip : "Copy failed. Select the IP manually.", false);
    }
  });
  requestSnapshot();
}());
