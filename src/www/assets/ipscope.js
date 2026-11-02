(function () {
  "use strict";

  var apiUrl = "api.php";
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

  function filterAddressRows() {
    var query = addressFilter ? addressFilter.value.trim().toLowerCase() : "";
    var selectedStatus = statusFilter ? statusFilter.value : "all";
    var rows = document.querySelectorAll(".address-row");
    var visible = 0;
    rows.forEach(function (row) {
      var matchesQuery = query === "" || row.getAttribute("data-search").indexOf(query) !== -1;
      var matchesStatus = selectedStatus === "all" || row.getAttribute("data-status") === selectedStatus;
      var show = matchesQuery && matchesStatus;
      row.hidden = !show;
      var detailRow = row.nextElementSibling;
      if (detailRow && detailRow.classList.contains("address-detail-row") && !show) {
        detailRow.hidden = true;
        var detailButton = row.querySelector("[data-toggle-details]");
        if (detailButton) detailButton.textContent = "Details";
      }
      if (show) visible += 1;
    });
    var total = rows.length;
    var countNode = document.getElementById("address-count");
    if (countNode) countNode.textContent = visible + " / " + total + " addresses";
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
        var owners = (address.owners || []).map(function (owner) {
          return owner.container + (owner.state && owner.state !== "system" ? " · " + owner.state : "");
        });
        var ownerText = owners.length ? owners.join(", ") : "—";
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
      return '<article class="network-card">' +
        '<div class="network-head"><div><div class="network-name">' + escapeHtml(network.name) + '</div>' +
        '<div class="network-meta">' + escapeHtml(network.driver || "unknown driver") +
        (subnet ? " · " + escapeHtml(subnet) : "") +
        (managed ? "" : " · built-in network, address pool not managed") + '</div></div>' +
        '<span class="network-count">' + addresses.length + ' addresses</span></div>' +
        '<div class="table-wrap"><table class="address-table"><thead><tr><th>IPv4 address</th><th>Status</th><th>Owner / reason</th><th></th></tr></thead>' +
        '<tbody>' + (rows || '<tr><td colspan="4" class="table-empty">No address records.</td></tr>') + '</tbody></table></div>' +
        '</article>';
    }).join("") || '<div class="empty-state">No Docker networks were discovered.</div>';
    filterAddressRows();

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
      .then(function (response) {
        return response.json().then(function (body) {
          if (!response.ok) throw new Error(body.error || "Unable to load snapshot.");
          return body;
        });
      })
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

    fetch(apiUrl + "?action=scan", {
      method: "POST",
      credentials: "same-origin",
      headers: { "Accept": "application/json" }
    }).then(function (response) {
      return response.json().then(function (body) {
        if (!response.ok) throw new Error(body.error || "Scan failed.");
        return body;
      });
    }).then(function (body) {
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
  if (addressFilter) addressFilter.addEventListener("input", filterAddressRows);
  if (statusFilter) statusFilter.addEventListener("change", filterAddressRows);
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
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(ip).then(function () {
        button.textContent = "Copied";
        window.setTimeout(function () { button.textContent = "Copy"; }, 1200);
      }).catch(function () { button.textContent = ip; });
    } else {
      button.textContent = ip;
    }
  });
  requestSnapshot();
}());
