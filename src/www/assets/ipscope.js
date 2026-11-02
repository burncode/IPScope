(function () {
  "use strict";

  var apiUrl = "api.php";
  var scanButton = document.getElementById("scan-button");
  var statusNode = document.getElementById("scan-status");
  var noticeNode = document.getElementById("notice");

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

  function render(snapshot) {
    var summary = snapshot.summary || {};
    setMetric("metric-networks", summary.network_count || 0);
    setMetric("metric-containers", summary.container_count || 0);
    setMetric("metric-reservations", (snapshot.networks || []).reduce(function (total, network) {
      return total + (network.known_address_count || 0);
    }, 0));
    setMetric("metric-conflicts", summary.conflict_count || 0);

    var networks = snapshot.networks || [];
    document.getElementById("network-list").innerHTML = networks.length ? networks.map(function (network) {
      var addresses = network.known_addresses || [];
      var subnet = (network.ipv4 || []).map(function (item) { return item.subnet; }).filter(Boolean).join(", ");
      var chips = addresses.length ? addresses.map(function (address) {
        var status = address.status || "reserved";
        return '<span class="address-chip status-' + escapeHtml(status) + '">' +
          '<i class="dot"></i><span>' + escapeHtml(address.ip) + '</span>' +
          '<span class="state-pill">' + escapeHtml(status) + '</span></span>';
      }).join("") : '<span class="network-meta">No known addresses</span>';
      return '<article class="network-card"><div class="network-head"><div>' +
        '<div class="network-name">' + escapeHtml(network.name) + '</div>' +
        '<div class="network-meta">' + escapeHtml(network.driver || "unknown driver") +
        (subnet ? " · " + escapeHtml(subnet) : "") + '</div></div>' +
        '<span class="network-count">' + addresses.length + ' known</span></div>' +
        '<div class="address-list">' + chips + '</div></article>';
    }).join("") : '<div class="empty-state">No Docker networks were discovered.</div>';

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
  requestSnapshot();
}());
