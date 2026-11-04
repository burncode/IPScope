(function () {
  "use strict";

  var apiUrl = "/plugins/ipscope/api.php";
  var scanButton = document.getElementById("scan-button");
  var statusNode = document.getElementById("scan-status");
  var noticeNode = document.getElementById("notice");
  var addressFilter = document.getElementById("address-filter");
  var statusFilter = document.getElementById("status-filter");
  var currentSnapshot = null;
  var exclusionRules = [];

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
        var numberHost = pager.querySelector(".pager-numbers");
        if (numberHost) {
          numberHost.innerHTML = Array.from({ length: pageCount }, function (_, index) {
            return '<button type="button" class="pager-button pager-number' +
              (index === page ? ' is-current' : '') + '" data-page-index="' + index +
              '" aria-label="Page ' + (index + 1) + '"' +
              (index === page ? ' aria-current="page"' : '') + '>' + (index + 1) + '</button>';
          }).join("");
        }
      }
    });
    var total = document.querySelectorAll(".address-row").length;
    var countNode = document.getElementById("address-count");
    if (countNode) countNode.textContent = totalVisible + " / " + total + " addresses";
  }

  function render(snapshot) {
    currentSnapshot = snapshot;
    populateExclusionNetworks(snapshot);
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
        var exclusionReasons = (address.exclusions || []).map(function (rule) { return rule.reason; }).filter(Boolean);
        var reasonText = [reason].concat(exclusionReasons).filter(Boolean).join(" · ");
        var searchText = [address.ip, status, ownerText, reasonText, network.name].join(" ").toLowerCase();
        var label = {
          available: "Available",
          occupied: "Occupied",
          reserved: "Reserved",
          system: "System",
          conflict: "Conflict",
          excluded: "Excluded"
        }[status] || status;
        var detailKey = network.name + "|" + address.ip;
        var ownerDetails = (address.owners || []).map(function (owner) {
          return '<div class="detail-owner"><strong>' + escapeHtml(owner.container) + '</strong>' +
            '<span>' + escapeHtml(owner.source || "unknown source") + '</span>' +
            '<span>' + escapeHtml(owner.kind || "unknown") + '</span>' +
            '<span>' + escapeHtml(owner.state || "unknown state") + '</span></div>';
        }).join("");
        var matchingExclusions = address.exclusions || [];
        var disabledRule = exclusionRules.find(function (rule) {
          return !rule.enabled && (rule.network === "*" || rule.network === network.name) &&
            (rule.match_type === "ip" ? rule.value === address.ip : cidrContains(rule.value, address.ip));
        });
        var exclusionAction = matchingExclusions.length ?
          '<div class="detail-exclusion-state"><span class="status-pill status-excluded">Excluded by rule</span>' +
          '<span>' + escapeHtml(matchingExclusions.map(function (rule) { return rule.reason; }).filter(Boolean).join(" · ")) + '</span>' +
          '<button type="button" class="detail-link-button" data-open-view="exclusions">View rules</button></div>' :
          (disabledRule ?
            '<div class="detail-exclusion-action"><span class="muted-inline">A matching rule is disabled.</span>' +
            '<button type="button" class="detail-exclude-button" data-enable-exclusion="' + escapeHtml(disabledRule.id) + '">Enable rule</button></div>' :
            '<form class="detail-exclusion-action" data-detail-exclusion-form>' +
            '<label class="detail-exclusion-reason"><span class="detail-label">Exclude this IP</span>' +
            '<input type="text" data-exclusion-reason maxlength="160" placeholder="Reason (e.g. DHCP reservation)" required></label>' +
            '<button type="submit" class="detail-exclude-button" data-add-exclusion data-ip="' + escapeHtml(address.ip) + '" data-network="' + escapeHtml(network.name) + '">Add rule</button></form>');
        var detailContent = '<div class="address-detail-content">' +
          '<div><span class="detail-label">Network</span><strong>' + escapeHtml(network.name) + '</strong></div>' +
          '<div><span class="detail-label">Address state</span><strong>' + escapeHtml(label) + '</strong></div>' +
          '<div><span class="detail-label">Runtime owners</span><strong>' + escapeHtml(address.runtime_owner_count || 0) + '</strong></div>' +
          '<div><span class="detail-label">Reservation owners</span><strong>' + escapeHtml(address.reservation_owner_count || 0) + '</strong></div>' +
          (reason ? '<div><span class="detail-label">System reason</span><strong>' + escapeHtml(reason) + '</strong></div>' : '') +
          (exclusionReasons.length ? '<div><span class="detail-label">Exclusion reason</span><strong>' + escapeHtml(exclusionReasons.join(", ")) + '</strong></div>' : '') +
          '<div class="detail-exclusion-wrap">' + exclusionAction + '</div>' +
          '<div class="detail-owner-list">' + (ownerDetails || '<span class="muted-inline">No known owner. Candidate is based on Docker and saved-template data only.</span>') + '</div>' +
          '</div>';
        return '<tr class="address-row" data-status="' + escapeHtml(status) + '" data-search="' + escapeHtml(searchText) + '">' +
          '<td class="ip-cell">' + escapeHtml(address.ip) + '</td>' +
          '<td><span class="status-pill status-' + escapeHtml(status) + '">' + escapeHtml(label) + '</span></td>' +
          '<td title="' + escapeHtml(ownerText + (reason ? ' · ' + reason : '') + (exclusionReasons.length ? ' · Exclusion: ' + exclusionReasons.join(', ') : '')) + '">' + escapeHtml(ownerText) + (reason ? ' <span class="muted-inline">· ' + escapeHtml(reason) + '</span>' : '') + (exclusionReasons.length ? ' <span class="exclusion-match-tag" title="' + escapeHtml(exclusionReasons.join(', ')) + '">Rule match</span>' : '') + '</td>' +
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
        '<div class="address-pager"><span class="pager-label">—</span><div class="pager-controls"><button type="button" class="pager-button" data-page-prev disabled>Previous</button><span class="pager-numbers"></span><button type="button" class="pager-button" data-page-next>Next</button></div></div>' +
        '</article>';
    }).join("") || '<div class="empty-state">No Docker networks were discovered.</div>';
    filterAddressRows(true);

    document.getElementById("network-overview").innerHTML = networks.map(function (network) {
      var addresses = network.known_addresses || [];
      var counts = { available: 0, occupied: 0, reserved: 0, system: 0, conflict: 0, excluded: 0 };
      addresses.forEach(function (address) { counts[address.status] = (counts[address.status] || 0) + 1; });
      var subnet = (network.ipv4 || []).map(function (item) { return item.subnet; }).filter(Boolean).join(", ");
      return '<article class="overview-network"><div><strong>' + escapeHtml(network.name) + '</strong><span>' +
        escapeHtml(subnet || network.driver || "No IPv4 subnet") + '</span></div>' +
        '<div class="overview-counts"><span><b>' + counts.available + '</b> available</span>' +
        '<span><b>' + counts.occupied + '</b> occupied</span><span><b>' + counts.reserved + '</b> reserved</span>' +
        '<span><b>' + counts.conflict + '</b> conflicts</span><span><b>' + counts.excluded + '</b> excluded</span></div>' +
        '<button type="button" class="text-action" data-open-view="addresses" data-open-network="' + escapeHtml(network.name) + '">Manage IPs</button></article>';
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
      } else if (item.type === "excluded_address_in_use") {
        details = (item.reason || "Address matches an exclusion rule") +
          (item.owners && item.owners.length ? " · Owners: " + item.owners.join(", ") : "");
      } else {
        details = (item.container || "") + " · template " + (item.template_ip || "") + " → runtime " + (item.runtime_ip || "");
      }
      var conflictLabel = item.type === "excluded_address_in_use" ? "Excluded address in use" :
        (item.type === "reservation_conflict" ? "Reservation conflict" :
        (item.type === "active_reservation_collision" ? "Active / reserved collision" :
        (item.type === "multiple_runtime_owners" ? "Multiple runtime owners" : item.type)));
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


  function ipToNumber(ip) {
    var parts = String(ip || "").split(".");
    if (parts.length !== 4 || parts.some(function (part) { return !/^\\d{1,3}$/.test(part) || Number(part) > 255; })) return null;
    return parts.reduce(function (value, part) { return value * 256 + Number(part); }, 0);
  }

  function cidrContains(cidr, ip) {
    var parts = String(cidr || "").split("/");
    if (parts.length !== 2) return false;
    var base = ipToNumber(parts[0]);
    var candidate = ipToNumber(ip);
    var prefix = Number(parts[1]);
    if (base === null || candidate === null || !Number.isInteger(prefix) || prefix < 0 || prefix > 32) return false;
    var blockSize = Math.pow(2, 32 - prefix);
    return Math.floor(base / blockSize) === Math.floor(candidate / blockSize);
  }

  function setExclusionFeedback(message, failed) {
    var node = document.getElementById("exclusion-feedback");
    if (!node) return;
    node.textContent = message || "";
    node.classList.toggle("is-error", !!failed);
    node.hidden = !message;
  }

  function exclusionRequest(method, rules) {
    var headers = { "Accept": "application/json" };
    var options = { method: method, credentials: "same-origin", headers: headers };
    if (method === "POST") {
      headers["Content-Type"] = "application/json";
      if (typeof csrf_token !== "undefined" && csrf_token) headers["X-CSRF-Token"] = csrf_token;
      options.body = JSON.stringify({ rules: rules });
    }
    return fetch(apiUrl + "?action=exclusions", options).then(readApiResponse);
  }

  function renderExclusionRules() {
    var list = document.getElementById("exclusion-list");
    if (!list) return;
    var active = exclusionRules.filter(function (rule) { return rule.enabled; }).length;
    document.getElementById("exclusion-total").textContent = String(exclusionRules.length);
    document.getElementById("exclusion-active").textContent = String(active);
    list.innerHTML = exclusionRules.length ? exclusionRules.map(function (rule) {
      var match = rule.match_type === "cidr" ? "CIDR · " + rule.value : "IP · " + rule.value;
      return '<tr><td>' + escapeHtml(rule.network === "*" ? "All networks" : rule.network) + '</td>' +
        '<td class="ip-cell">' + escapeHtml(match) + '</td>' +
        '<td>' + escapeHtml(rule.reason) + '</td>' +
        '<td><span class="status-pill ' + (rule.enabled ? "status-available" : "status-system") + '">' +
          (rule.enabled ? "Enabled" : "Disabled") + '</span></td>' +
        '<td class="exclusion-actions"><button type="button" class="exclusion-action" data-exclusion-toggle="' +
          escapeHtml(rule.id) + '">' + (rule.enabled ? "Disable" : "Enable") + '</button>' +
          '<button type="button" class="exclusion-action is-danger" data-exclusion-delete="' +
          escapeHtml(rule.id) + '">Delete</button></td></tr>';
    }).join("") : '<tr><td colspan="5" class="table-empty">No exclusion rules yet. Add an IP or CIDR range above.</td></tr>';
  }

  function loadExclusionRules() {
    exclusionRequest("GET").then(function (body) {
      exclusionRules = body.rules || [];
      renderExclusionRules();
      setExclusionFeedback("", false);
    }).catch(function (error) {
      setExclusionFeedback("Could not load rules: " + error.message, true);
      var list = document.getElementById("exclusion-list");
      if (list) list.innerHTML = '<tr><td colspan="5" class="table-empty">Rules could not be loaded.</td></tr>';
    });
  }

  function saveExclusionRules(nextRules, successMessage, triggerButton) {
    var submit = document.getElementById("exclusion-submit");
    if (submit) submit.disabled = true;
    if (triggerButton) triggerButton.disabled = true;
    exclusionRequest("POST", nextRules).then(function (body) {
      exclusionRules = body.rules || [];
      renderExclusionRules();
      setExclusionFeedback(successMessage, false);
      // Refresh the inventory so exclusions are reflected immediately.
      return fetch(apiUrl + "?action=scan", {
        method: "POST",
        credentials: "same-origin",
        headers: (function () {
          var headers = { "Accept": "application/json" };
          if (typeof csrf_token !== "undefined" && csrf_token) headers["X-CSRF-Token"] = csrf_token;
          return headers;
        }())
      }).then(readApiResponse).then(function (scan) {
        render(scan.snapshot);
      }).catch(function (error) {
        setExclusionFeedback(successMessage + " Inventory refresh failed: " + error.message, true);
      });
    }).catch(function (error) {
      setExclusionFeedback(error.message, true);
    }).finally(function () {
      if (submit) submit.disabled = false;
      if (triggerButton) triggerButton.disabled = false;
    });
  }

  function populateExclusionNetworks(snapshot) {
    var select = document.getElementById("exclusion-network");
    if (!select) return;
    var selected = select.value || "*";
    var options = ['<option value="*">All networks</option>'];
    (snapshot.networks || []).forEach(function (network) {
      options.push('<option value="' + escapeHtml(network.name) + '">' + escapeHtml(network.name) + '</option>');
    });
    select.innerHTML = options.join("");
    select.value = options.some(function (option) { return option.indexOf('value="' + selected + '"') !== -1; }) ? selected : "*";
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
  loadExclusionRules();
  var exclusionForm = document.getElementById("exclusion-form");
  if (exclusionForm) exclusionForm.addEventListener("submit", function (event) {
    event.preventDefault();
    var rule = {
      network: document.getElementById("exclusion-network").value,
      match_type: document.getElementById("exclusion-type").value,
      value: document.getElementById("exclusion-value").value.trim(),
      reason: document.getElementById("exclusion-reason").value.trim(),
      enabled: true
    };
    saveExclusionRules(exclusionRules.concat([rule]), "Exclusion rule added.");
    exclusionForm.reset();
  });
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
  document.addEventListener("click", function (event) {
    var viewButton = event.target.closest("[data-view], [data-open-view]");
    if (!viewButton) return;
    var viewName = viewButton.getAttribute("data-view") || viewButton.getAttribute("data-open-view");
    activateView(viewName);
    var networkName = viewButton.getAttribute("data-open-network");
    if (networkName) {
      window.setTimeout(function () {
        var target = Array.from(document.querySelectorAll(".network-card")).find(function (card) {
          var heading = card.querySelector(".network-name");
          return heading && heading.textContent === networkName;
        });
        if (target) target.scrollIntoView({ behavior: "smooth", block: "start" });
      }, 0);
    }
  });
  document.addEventListener("submit", function (event) {
    if (event.target.matches("[data-detail-exclusion-form]")) {
      event.preventDefault();
      var form = event.target;
      var button = form.querySelector("[data-add-exclusion]");
      var reason = form.querySelector("[data-exclusion-reason]");
      if (!reason || !reason.value.trim()) { if (reason) reason.focus(); return; }
      var ip = button.getAttribute("data-ip");
      var network = button.getAttribute("data-network");
      saveExclusionRules(exclusionRules.concat([{
        network: network, match_type: "ip", value: ip, reason: reason.value.trim(), enabled: true
      }]), "Exclusion rule added for " + ip + ".", button);
    }
  });
  document.addEventListener("click", function (event) {
    var previous = event.target.closest("[data-page-prev]");
    var next = event.target.closest("[data-page-next]");
    var numbered = event.target.closest("[data-page-index]");
    if (!previous && !next && !numbered) return;
    var card = event.target.closest(".network-card");
    if (!card) return;
    if (numbered) card.dataset.page = String(Number(numbered.getAttribute("data-page-index")));
    else card.dataset.page = String(Math.max(0, Number(card.dataset.page || 0) + (next ? 1 : -1)));
    filterAddressRows(false);
  });
  document.addEventListener("click", function (event) {
    var enableDetailRule = event.target.closest("[data-enable-exclusion]");
    if (enableDetailRule) {
      var enableId = enableDetailRule.getAttribute("data-enable-exclusion");
      saveExclusionRules(exclusionRules.map(function (rule) {
        return rule.id === enableId ? Object.assign({}, rule, { enabled: true }) : rule;
      }), "Exclusion rule enabled.", enableDetailRule);
      return;
    }
    var toggleExclusion = event.target.closest("[data-exclusion-toggle]");
    var deleteExclusion = event.target.closest("[data-exclusion-delete]");
    if (toggleExclusion) {
      var toggleId = toggleExclusion.getAttribute("data-exclusion-toggle");
      saveExclusionRules(exclusionRules.map(function (rule) {
        return rule.id === toggleId ? Object.assign({}, rule, { enabled: !rule.enabled }) : rule;
      }), "Exclusion rule updated.");
      return;
    }
    if (deleteExclusion) {
      var deleteId = deleteExclusion.getAttribute("data-exclusion-delete");
      saveExclusionRules(exclusionRules.filter(function (rule) { return rule.id !== deleteId; }), "Exclusion rule deleted.");
      return;
    }
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
        var copied = fallbackCopy();
        showCopyNotice(copied ? "Copied " + ip : "Copy failed. Select the IP manually.", !copied);
      });
    } else {
      var copied = fallbackCopy();
      showCopyNotice(copied ? "Copied " + ip : "Copy failed. Select the IP manually.", !copied);
    }
  });
  requestSnapshot();
}());
