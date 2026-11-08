(function () {
  "use strict";

  var apiUrl = "/plugins/ipscope/api.php";

  function t(text) {
    return typeof window._ === "function" ? window._(text) : text;
  }

  var scanButton = document.getElementById("scan-button");
  var statusNode = document.getElementById("scan-status");
  var noticeNode = document.getElementById("notice");
  var addressFilter = document.getElementById("address-filter");
  var statusFilter = document.getElementById("status-filter");
  var currentSnapshot = null;
  var exclusionRules = [];
  var editingExclusionId = null;

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
            if (detailButton) detailButton.textContent = t("Details");
          }
        }
      });
      totalVisible += filtered.length;
      var pager = card.querySelector(".address-pager");
      if (pager) {
        pager.querySelector(".pager-label").textContent = filtered.length ?
          (first + 1) + "–" + Math.min(first + pageSize, filtered.length) + " " + t("of") + " " + filtered.length : t("No matching addresses");
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
    if (countNode) countNode.textContent = totalVisible + " / " + total + " " + t("addresses");
  }

  function render(snapshot) {
    currentSnapshot = snapshot;
    populateExclusionNetworks(snapshot);
    populateRecommendationNetworks(snapshot);
    renderExclusionRules();
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
          available: t("Available"),
          occupied: t("Occupied"),
          reserved: t("Reserved"),
          system: t("System"),
          conflict: t("Conflict"),
          excluded: t("Excluded")
        }[status] || status;
        var detailKey = network.name + "|" + address.ip;
        var ownerDetails = (address.owners || []).map(function (owner) {
          return '<div class="detail-owner"><strong>' + escapeHtml(owner.container) + '</strong>' +
            '<span>' + escapeHtml(owner.source || t("unknown source")) + '</span>' +
            '<span>' + escapeHtml(owner.kind || t("unknown")) + '</span>' +
            '<span>' + escapeHtml(owner.state || t("unknown state")) + '</span></div>';
        }).join("");
        var matchingExclusions = address.exclusions || [];
        var disabledRule = exclusionRules.find(function (rule) {
          return !rule.enabled && (rule.network === "*" || rule.network === network.name) &&
            (rule.match_type === "ip" ? rule.value === address.ip : cidrContains(rule.value, address.ip));
        });
        var exclusionAction = matchingExclusions.length ?
          '<div class="detail-exclusion-state"><span class="status-pill status-excluded">' + t("Excluded by rule") + '</span>' +
          '<span>' + escapeHtml(matchingExclusions.map(function (rule) { return rule.reason; }).filter(Boolean).join(" · ")) + '</span>' +
          '<button type="button" class="detail-link-button" data-open-view="exclusions" data-open-rule="' + escapeHtml(matchingExclusions[0].id || "") + '">' + t("View rule") + '</button></div>' :
          (disabledRule ?
            '<div class="detail-exclusion-action"><span class="muted-inline">' + t("A matching rule is disabled.") + '</span>' +
            '<button type="button" class="detail-exclude-button" data-enable-exclusion="' + escapeHtml(disabledRule.id) + '">' + t("Enable rule") + '</button></div>' :
            '<form class="detail-exclusion-action" data-detail-exclusion-form>' +
            '<label class="detail-exclusion-reason"><span class="detail-label">' + t("Exclude this IP") + '</span>' +
            '<input type="text" data-exclusion-reason maxlength="160" placeholder="' + t("Reason (e.g. DHCP reservation)") + '" required></label>' +
            '<button type="submit" class="detail-exclude-button" data-add-exclusion data-ip="' + escapeHtml(address.ip) + '" data-network="' + escapeHtml(network.name) + '">' + t("Add rule") + '</button></form>');
        var detailContent = '<div class="address-detail-content">' +
          '<div><span class="detail-label">' + t("Network") + '</span><strong>' + escapeHtml(network.name) + '</strong></div>' +
          '<div><span class="detail-label">' + t("Address state") + '</span><strong>' + escapeHtml(label) + '</strong></div>' +
          '<div><span class="detail-label">' + t("Runtime owners") + '</span><strong>' + escapeHtml(address.runtime_owner_count || 0) + '</strong></div>' +
          '<div><span class="detail-label">' + t("Reservation owners") + '</span><strong>' + escapeHtml(address.reservation_owner_count || 0) + '</strong></div>' +
          (reason ? '<div><span class="detail-label">' + t("System reason") + '</span><strong>' + escapeHtml(reason) + '</strong></div>' : '') +
          (exclusionReasons.length ? '<div><span class="detail-label">' + t("Exclusion reason") + '</span><strong>' + escapeHtml(exclusionReasons.join(", ")) + '</strong></div>' : '') +
          '<div class="detail-exclusion-wrap">' + exclusionAction + '</div>' +
          '<div class="detail-owner-list">' + (ownerDetails || '<span class="muted-inline">' + t("No known owner. Candidate is based on Docker and saved-template data only.") + '</span>') + '</div>' +
          '</div>';
        return '<tr class="address-row" data-status="' + escapeHtml(status) + '" data-search="' + escapeHtml(searchText) + '">' +
          '<td class="ip-cell">' + escapeHtml(address.ip) + '</td>' +
          '<td><span class="status-pill status-' + escapeHtml(status) + '">' + escapeHtml(label) + '</span></td>' +
          '<td title="' + escapeHtml(ownerText + (reason ? ' · ' + reason : '') + (exclusionReasons.length ? ' · Exclusion: ' + exclusionReasons.join(', ') : '')) + '">' + escapeHtml(ownerText) + (reason ? ' <span class="muted-inline">· ' + escapeHtml(reason) + '</span>' : '') + (exclusionReasons.length ? ' <span class="exclusion-match-tag" title="' + escapeHtml(exclusionReasons.join(', ')) + '">' + t("Rule match") + '</span>' : '') + '</td>' +
          '<td class="address-actions"><button class="copy-ip" type="button" data-copy-ip="' + escapeHtml(address.ip) + '" aria-label="Copy ' + escapeHtml(address.ip) + '">Copy</button>' +
          '<button class="details-ip" type="button" data-toggle-details="' + escapeHtml(detailKey) + '">' + t("Details") + '</button></td>' +
          '</tr><tr class="address-detail-row" data-detail-for="' + escapeHtml(detailKey) + '" hidden><td colspan="4">' + detailContent + '</td></tr>';
      }).join("");
      var managed = network.address_space_managed !== false;
      return '<article class="network-card" data-page="0">' +
        '<div class="network-head"><div><div class="network-name">' + escapeHtml(network.name) + '</div>' +
        '<div class="network-meta">' + escapeHtml(network.driver || t("unknown driver")) +
        (subnet ? " · " + escapeHtml(subnet) : "") +
        (managed ? "" : " · " + t("built-in network, address pool not managed")) + '</div></div>' +
        '<span class="network-count">' + addresses.length + ' ' + t("addresses") + '</span></div>' +
        '<div class="table-wrap address-table-wrap"><table class="address-table"><colgroup><col class="col-ip"><col class="col-status"><col class="col-owner"><col class="col-actions"></colgroup><thead><tr><th>' + t("IPv4 address") + '</th><th>' + t("Status") + '</th><th>' + t("Owner / reason") + '</th><th class="actions-heading">' + t("Actions") + '</th></tr></thead>' +
        '<tbody>' + (rows || '<tr><td colspan="4" class="table-empty">' + t("No address records.") + '</td></tr>') + '</tbody></table></div>' +
        '<div class="address-pager"><span class="pager-label">—</span><div class="pager-controls"><button type="button" class="pager-button" data-page-prev disabled>' + t("Previous") + '</button><span class="pager-numbers"></span><button type="button" class="pager-button" data-page-next>' + t("Next") + '</button></div></div>' +
        '</article>';
    }).join("") || '<div class="empty-state">' + t("No Docker networks were discovered.") + '</div>';
    filterAddressRows(true);

    document.getElementById("network-overview").innerHTML = networks.map(function (network) {
      var addresses = network.known_addresses || [];
      var counts = { available: 0, occupied: 0, reserved: 0, system: 0, conflict: 0, excluded: 0 };
      addresses.forEach(function (address) { counts[address.status] = (counts[address.status] || 0) + 1; });
      var subnet = (network.ipv4 || []).map(function (item) { return item.subnet; }).filter(Boolean).join(", ");
      return '<article class="overview-network"><div><strong>' + escapeHtml(network.name) + '</strong><span>' +
        escapeHtml(subnet || network.driver || t("No IPv4 subnet")) + '</span></div>' +
        '<div class="overview-counts"><span><b>' + counts.available + '</b> ' + t("available") + '</span>' +
        '<span><b>' + counts.occupied + '</b> ' + t("occupied") + '</span><span><b>' + counts.reserved + '</b> ' + t("reserved") + '</span>' +
        '<span><b>' + counts.conflict + '</b> ' + t("conflicts") + '</span><span><b>' + counts.excluded + '</b> ' + t("excluded") + '</span></div>' +
        '<button type="button" class="text-action" data-open-view="addresses" data-open-network="' + escapeHtml(network.name) + '">' + t("Manage IPs") + '</button></article>';
    }).join("") || '<div class="empty-state">' + t("No networks found.") + '</div>';

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
      if (isOrphan) audit.push('<span class="status-pill status-reserved">' + t("No Docker container") + '</span>');
      if (duplicateIps.length) audit.push('<span class="status-pill status-conflict">' + t("Duplicate template IP") + '</span>');
      if (!audit.length) audit.push('<span class="status-pill status-available">' + t("Matched") + '</span>');
      return "<tr>" +
        "<td>" + escapeHtml(template.name) + "</td>" +
        "<td>" + escapeHtml(template.network || "—") + "</td>" +
        '<td class="ip-cell">' + escapeHtml(ips.join(", ") || "—") + "</td>" +
        "<td>" + audit.join(" ") + "</td></tr>";
    }).join("") : '<tr><td colspan="4" class="table-empty">' + t("No saved templates found.") + '</td></tr>';

    var conflicts = snapshot.conflicts || [];
    document.getElementById("conflict-list").innerHTML = conflicts.length ? conflicts.map(function (item) {
      var details;
      if (item.type === "reservation_conflict") {
        details = t("Stopped reservations:") + " " + (item.reserved_containers || item.containers || []).join(", ");
      } else if (item.type === "active_reservation_collision") {
        details = t("Running:") + " " + (item.running_containers || []).join(", ") +
          " · " + t("Reserved:") + " " + (item.reserved_containers || []).join(", ");
      } else if (item.type === "multiple_runtime_owners") {
        details = t("Running containers:") + " " + (item.running_containers || item.containers || []).join(", ");
      } else if (item.type === "excluded_address_in_use") {
        details = (item.reason || t("Address matches an exclusion rule")) +
          (item.rule_id ? " · " + t("Rule ID:") + " " + item.rule_id : "") +
          (item.owners && item.owners.length ? " · " + t("Owners:") + " " + item.owners.join(", ") : "");
      } else {
        details = (item.container || "") + " · " + t("template") + " " + (item.template_ip || "") + " → " + t("runtime") + " " + (item.runtime_ip || "");
      }
      var conflictLabel = item.type === "excluded_address_in_use" ? t("Excluded address in use") :
        (item.type === "reservation_conflict" ? t("Reservation conflict") :
        (item.type === "active_reservation_collision" ? t("Active / reserved collision") :
        (item.type === "multiple_runtime_owners" ? t("Multiple runtime owners") : item.type)));
      return "<tr><td>" + escapeHtml(conflictLabel) + "</td><td>" + escapeHtml(item.network) +
        "</td><td>" + escapeHtml(item.ip || item.template_ip || "—") +
        "</td><td>" + escapeHtml(details) + "</td></tr>";
    }).join("") : '<tr><td colspan="4" class="table-empty">' + t("No known conflicts.") + '</td></tr>';

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
    }).join("") : '<tr><td colspan="4" class="table-empty">' + t("No containers were discovered.") + '</td></tr>';

    document.getElementById("generated-at").textContent = t("Last scan:") + " " + (snapshot.generated_at || t("unknown"));
    statusNode.textContent = t("Snapshot loaded");
  }


  function populateRecommendationNetworks(snapshot) {
    var select = document.getElementById("recommendation-network");
    if (!select) return;
    var selected = select.value || "*";
    var options = ['<option value="*">All managed networks</option>'];
    (snapshot.networks || []).forEach(function (network) {
      if (network.address_space_managed === false) return;
      options.push('<option value="' + escapeHtml(network.name) + '">' +
        escapeHtml(network.name) + (network.ipv4 && network.ipv4.length ? ' · ' +
        escapeHtml(network.ipv4.map(function (item) { return item.subnet; }).filter(Boolean).join(", ")) : '') +
        '</option>');
    });
    select.innerHTML = options.join("");
    var exists = Array.from(select.options).some(function (option) { return option.value === selected; });
    select.value = exists ? selected : "*";
  }

  function renderRecommendations(data) {
    var summary = document.getElementById("recommendation-summary");
    var results = document.getElementById("recommendation-results");
    if (!summary || !results) return;

    var networks = data.networks || [];
    var snapshotLabel = t("Snapshot time unavailable");
    if (data.generated_at) {
      snapshotLabel = t("Snapshot:") + " " + data.generated_at;
      if (data.snapshot_time_status === "valid" && Number.isFinite(Number(data.snapshot_age_seconds))) {
        var age = Number(data.snapshot_age_seconds);
        var ageLabel = age < 60 ? t("less than a minute ago") :
          (age < 3600 ? Math.floor(age / 60) + " " + t("min ago") :
          (age < 86400 ? Math.floor(age / 3600) + " " + t("hr ago") : Math.floor(age / 86400) + " " + t("days ago")));
        snapshotLabel += " · " + ageLabel;
      } else if (data.snapshot_time_status === "future") {
        snapshotLabel += " · " + t("system clock may be behind");
      } else {
        snapshotLabel += " · " + t("age unknown");
      }
    }
    summary.innerHTML = '<strong>' + escapeHtml(data.candidate_count || 0) +
      '</strong> ' + t("available candidates across") + '<strong>' + networks.length +
      '</strong> ' + t("managed networks") +
      '<span class="recommendation-generated">' + escapeHtml(snapshotLabel) + '</span>';

    if (!networks.length) {
      results.innerHTML = '<div class="recommendation-empty">' + t("No managed Docker network pools were found in this snapshot.") + '</div>';
      return;
    }

    results.innerHTML = networks.map(function (network) {
      var candidates = network.candidates || [];
      var candidateMarkup = candidates.length ? candidates.map(function (candidate) {
        return '<div class="recommendation-candidate" title="' + escapeHtml(candidate.reason || t("Inventory candidate; verify on your LAN before assigning.")) + '">' +
          '<code title="' + escapeHtml(candidate.reason || t("Inventory candidate; verify on your LAN before assigning.")) + '">' + escapeHtml(candidate.ip) + '</code>' +
          '<span class="recommendation-candidate-state" role="img" aria-label="' + t("Available") + '" title="' + t("Available") + '"></span>' +
          '<button type="button" class="copy-ip recommendation-copy" data-copy-ip="' +
          escapeHtml(candidate.ip) + '" aria-label="Copy ' + escapeHtml(candidate.ip) +
          '" title="' + t("Copy IP address") + '"><svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">' +
          '<rect x="8" y="8" width="12" height="13" rx="2"></rect>' +
          '<path d="M16 8V5a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h2"></path>' +
          '</svg></button></div>';
      }).join("") : '<div class="recommendation-empty">' + t("No known available addresses in this network.") + '</div>';

      var copyAllButton = candidates.length ? '<button type="button" class="recommendation-copy-all" data-copy-ips="' +
        escapeHtml(JSON.stringify(candidates.map(function (candidate) { return candidate.ip; }))) +
        '" aria-label="' + t("Copy all displayed IP addresses") + '">' + t("Copy shown") + '</button>' : '';
      return '<article class="recommendation-network">' +
        '<div class="recommendation-network-head"><div><h3>' + escapeHtml(network.network) +
        '</h3><p>' + escapeHtml(network.driver || t("unknown driver")) +
        (network.subnets && network.subnets.length ? ' · ' + escapeHtml(network.subnets.join(", ")) : '') +
        '</p></div><div class="recommendation-network-tools"><span><strong>' + escapeHtml(network.candidate_count || 0) +
        '</strong> ' + t("candidates") + '</span>' + copyAllButton + '</div></div>' +
        '<div class="recommendation-candidate-list">' + candidateMarkup + '</div></article>';
    }).join("");
  }

  function loadRecommendations() {
    var button = document.getElementById("recommendation-run");
    var results = document.getElementById("recommendation-results");
    var summary = document.getElementById("recommendation-summary");
    var networkSelect = document.getElementById("recommendation-network");
    var limitSelect = document.getElementById("recommendation-limit");
    if (!button || !results || !summary) return;
    if (!currentSnapshot) {
      summary.textContent = t("Run a scan before requesting IP suggestions.");
      return;
    }

    var network = networkSelect ? networkSelect.value : "*";
    var limit = limitSelect ? limitSelect.value : "20";
    var query = "?action=recommendations&limit=" + encodeURIComponent(limit);
    if (network && network !== "*") query += "&network=" + encodeURIComponent(network);

    button.disabled = true;
    button.textContent = t("Finding…");
    summary.textContent = t("Checking the latest inventory…");
    results.innerHTML = '<div class="recommendation-empty">' + t("Evaluating known address claims and exclusions…") + '</div>';

    fetch(apiUrl + query, {
      method: "GET",
      credentials: "same-origin",
      headers: { "Accept": "application/json" }
    }).then(readApiResponse).then(function (body) {
      renderRecommendations(body.recommendations || {});
    }).catch(function (error) {
      summary.textContent = t("Could not generate suggestions.");
      results.innerHTML = '<div class="recommendation-empty is-error">' + escapeHtml(error.message) + '</div>';
    }).finally(function () {
      button.disabled = false;
      button.textContent = t("Find available IPs");
    });
  }


  function ipToNumber(ip) {
    var parts = String(ip || "").split(".");
    if (parts.length !== 4 || parts.some(function (part) { return !/^\d{1,3}$/.test(part) || Number(part) > 255; })) return null;
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

  function exclusionRange(rule) {
    if (rule.match_type === "ip") {
      var value = ipToNumber(rule.value);
      return value === null ? null : { start: value, end: value };
    }
    var parts = String(rule.value || "").split("/");
    if (parts.length !== 2) return null;
    var base = ipToNumber(parts[0]);
    var prefix = Number(parts[1]);
    if (base === null || !Number.isInteger(prefix) || prefix < 0 || prefix > 32) return null;
    var size = Math.pow(2, 32 - prefix);
    var start = Math.floor(base / size) * size;
    return { start: start, end: start + size - 1 };
  }

  function exclusionRulesOverlap(left, right) {
    if (!left.enabled || !right.enabled) return false;
    if (left.network !== "*" && right.network !== "*" && left.network !== right.network) return false;
    var a = exclusionRange(left);
    var b = exclusionRange(right);
    return !!a && !!b && a.start <= b.end && b.start <= a.end;
  }

  function getExclusionAudit() {
    var result = { byId: {}, matched: 0, inUse: 0, overlaps: [] };
    exclusionRules.forEach(function (rule) {
      result.byId[rule.id] = { matched: 0, inUse: 0 };
    });
    var seen = {};
    if (currentSnapshot && Array.isArray(currentSnapshot.networks)) {
      currentSnapshot.networks.forEach(function (network) {
        (network.known_addresses || []).forEach(function (address) {
          (address.exclusions || []).forEach(function (match) {
            if (!result.byId[match.id]) return;
            var key = match.id + "|" + network.name + "|" + address.ip;
            if (seen[key]) return;
            seen[key] = true;
            result.byId[match.id].matched += 1;
            result.matched += 1;
            if (address.status !== "excluded") {
              result.byId[match.id].inUse += 1;
              result.inUse += 1;
            }
          });
        });
      });
    }
    for (var i = 0; i < exclusionRules.length; i += 1) {
      for (var j = i + 1; j < exclusionRules.length; j += 1) {
        if (exclusionRulesOverlap(exclusionRules[i], exclusionRules[j])) {
          result.overlaps.push([exclusionRules[i], exclusionRules[j]]);
        }
      }
    }
    return result;
  }

  function selectedExclusionIds() {
    return Array.from(document.querySelectorAll("[data-exclusion-select]:checked")).map(function (node) {
      return node.getAttribute("data-exclusion-select");
    });
  }

  function updateExclusionBulkToolbar() {
    var selected = selectedExclusionIds();
    var total = exclusionRules.length;
    var count = document.getElementById("exclusion-selected-count");
    var selectAll = document.getElementById("exclusion-select-all");
    if (count) count.textContent = selected.length + " selected";
    if (selectAll) {
      selectAll.checked = total > 0 && selected.length === total;
      selectAll.indeterminate = selected.length > 0 && selected.length < total;
    }
    document.querySelectorAll("[data-exclusion-bulk]").forEach(function (button) {
      button.disabled = selected.length === 0;
    });
  }

  function exclusionNetworkOptions(selected) {
    var names = currentSnapshot && Array.isArray(currentSnapshot.networks) ?
      currentSnapshot.networks.map(function (network) { return network.name; }) : [];
    if (selected !== "*" && names.indexOf(selected) === -1) names.push(selected);
    return ['<option value="*"' + (selected === "*" ? " selected" : "") + '>All networks</option>']
      .concat(names.map(function (name) {
        return '<option value="' + escapeHtml(name) + '"' + (selected === name ? " selected" : "") + '>' + escapeHtml(name) + '</option>';
      })).join("");
  }

  function renderExclusionRules() {
    var list = document.getElementById("exclusion-list");
    if (!list) return;
    var active = exclusionRules.filter(function (rule) { return rule.enabled; }).length;
    var audit = getExclusionAudit();
    document.getElementById("exclusion-total").textContent = String(exclusionRules.length);
    document.getElementById("exclusion-active").textContent = String(active);
    document.getElementById("exclusion-hit-count").textContent = String(audit.matched);
    document.getElementById("exclusion-in-use-count").textContent = String(audit.inUse);
    document.getElementById("exclusion-overlap-count").textContent = String(audit.overlaps.length);
    var overlapById = {};
    audit.overlaps.forEach(function (pair) {
      pair.forEach(function (rule) {
        overlapById[rule.id] = (overlapById[rule.id] || 0) + 1;
      });
    });
    list.innerHTML = exclusionRules.length ? exclusionRules.map(function (rule) {
      var match = rule.match_type === "cidr" ? t("CIDR") + " · " + rule.value : "IP · " + rule.value;
      var stats = audit.byId[rule.id] || { matched: 0, inUse: 0 };
      var warnings = overlapById[rule.id] || 0;
      var warningText = warnings ? '<span class="audit-warning" title="' + t("Overlaps another enabled rule") + '">' + t("Overlap") + ' · ' + warnings + '</span>' : '<span class="audit-clean">' + t("No overlap") + '</span>';
      var row = '<tr data-rule-id="' + escapeHtml(rule.id) + '">' +
        '<td class="exclusion-check-cell"><input type="checkbox" data-exclusion-select="' + escapeHtml(rule.id) + '" aria-label="' + t("Select rule") + '"></td>' +
        '<td>' + escapeHtml(rule.network === "*" ? t("All networks") : rule.network) + '</td>' +
        '<td class="ip-cell">' + escapeHtml(match) + '</td>' +
        '<td>' + escapeHtml(rule.reason) + '</td>' +
        '<td><strong>' + stats.matched + '</strong> ' + t("addresses") + '</td>' +
        '<td>' + warningText + (stats.inUse ? '<span class="audit-in-use">' + stats.inUse + ' in use</span>' : '') + '</td>' +
        '<td><span class="status-pill ' + (rule.enabled ? "status-available" : "status-system") + '">' + (rule.enabled ? t("Enabled") : t("Disabled")) + '</span></td>' +
        '<td class="exclusion-actions"><button type="button" class="exclusion-action" data-exclusion-edit="' + escapeHtml(rule.id) + '">' + t("Edit") + '</button>' +
        '<button type="button" class="exclusion-action" data-exclusion-toggle="' + escapeHtml(rule.id) + '">' + (rule.enabled ? "Disable" : "Enable") + '</button>' +
        '<button type="button" class="exclusion-action is-danger" data-exclusion-delete="' + escapeHtml(rule.id) + '">' + t("Delete") + '</button></td></tr>';
      if (editingExclusionId !== rule.id) return row;
      var editRow = '<tr class="exclusion-edit-row" data-edit-row="' + escapeHtml(rule.id) + '"><td colspan="8">' +
        '<form class="exclusion-edit-form" data-exclusion-edit-form="' + escapeHtml(rule.id) + '">' +
        '<label>' + t("Network") + '<select name="network">' + exclusionNetworkOptions(rule.network) + '</select></label>' +
        '<label>' + t("Type") + '<select name="match_type"><option value="ip"' + (rule.match_type === "ip" ? " selected" : "") + '>' + t("Single IPv4") + '</option><option value="cidr"' + (rule.match_type === "cidr" ? " selected" : "") + '>' + t("CIDR subnet") + '</option></select></label>' +
        '<label>' + t("Address / subnet") + '<input name="value" value="' + escapeHtml(rule.value) + '" maxlength="32" required></label>' +
        '<label>' + t("Reason") + '<input name="reason" value="' + escapeHtml(rule.reason) + '" maxlength="160" required></label>' +
        '<div class="exclusion-edit-actions"><button type="submit" class="exclusion-action is-save">' + t("Save changes") + '</button><button type="button" class="exclusion-action" data-exclusion-edit-cancel>' + t("Cancel") + '</button></div>' +
        '</form></td></tr>';
      return row + editRow;
    }).join("") : '<tr><td colspan="8" class="table-empty">' + t("No exclusion rules yet. Add an IP or CIDR range above.") + '</td></tr>';
    updateExclusionBulkToolbar();
  }

  function loadExclusionRules() {
    exclusionRequest("GET").then(function (body) {
      exclusionRules = body.rules || [];
      renderExclusionRules();
      setExclusionFeedback("", false);
    }).catch(function (error) {
      setExclusionFeedback(t("Could not load rules:") + " " + error.message, true);
      var list = document.getElementById("exclusion-list");
      if (list) list.innerHTML = '<tr><td colspan="8" class="table-empty">' + t("Rules could not be loaded.") + '</td></tr>';
    });
  }

  function saveExclusionRules(nextRules, successMessage, triggerButton) {
    var submit = document.getElementById("exclusion-submit");
    if (submit) submit.disabled = true;
    if (triggerButton) triggerButton.disabled = true;
    exclusionRequest("POST", nextRules).then(function (body) {
      editingExclusionId = null;
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
        statusNode.textContent = successMessage;
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
    statusNode.textContent = t("Loading snapshot…");
    fetch(apiUrl + "?action=snapshot", { credentials: "same-origin", headers: { "Accept": "application/json" } })
      .then(readApiResponse)
      .then(function (body) { render(body.snapshot); setNotice(""); })
      .catch(function (error) {
        statusNode.textContent = t("Snapshot unavailable");
        setNotice(error.message);
      });
  }

  function runScan() {
    scanButton.disabled = true;
    scanButton.textContent = t("Scanning…");
    statusNode.textContent = t("Collecting Docker data…");
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
      statusNode.textContent = t("Scan failed");
      setNotice(error.message);
    }).finally(function () {
      scanButton.disabled = false;
      scanButton.textContent = t("Scan now");
    });
  }

  scanButton.addEventListener("click", runScan);
  loadExclusionRules();
  var recommendationButton = document.getElementById("recommendation-run");
  if (recommendationButton) recommendationButton.addEventListener("click", loadRecommendations);
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
    saveExclusionRules(exclusionRules.concat([rule]), t("Exclusion rule added."));
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
    var ruleId = viewButton.getAttribute("data-open-rule");
    if (ruleId) {
      window.setTimeout(function () {
        var ruleRow = document.querySelector('[data-rule-id="' + ruleId + '"]');
        if (ruleRow) {
          ruleRow.scrollIntoView({ behavior: "smooth", block: "center" });
          ruleRow.classList.add("is-highlighted");
          window.setTimeout(function () { ruleRow.classList.remove("is-highlighted"); }, 1800);
        }
      }, 0);
    }
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
    var editForm = event.target.closest("[data-exclusion-edit-form]");
    if (editForm) {
      event.preventDefault();
      var editId = editForm.getAttribute("data-exclusion-edit-form");
      var values = new FormData(editForm);
      var updated = exclusionRules.map(function (rule) {
        if (rule.id !== editId) return rule;
        return Object.assign({}, rule, {
          network: String(values.get("network") || "*"),
          match_type: String(values.get("match_type") || "ip"),
          value: String(values.get("value") || "").trim(),
          reason: String(values.get("reason") || "").trim()
        });
      });
      editingExclusionId = null;
      saveExclusionRules(updated, t("Exclusion rule updated."));
      return;
    }
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
      }]), t("Exclusion rule added for") + " " + ip + ".", button);
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
  document.addEventListener("change", function (event) {
    if (event.target.matches("[data-exclusion-select]") || event.target.id === "exclusion-select-all") {
      if (event.target.id === "exclusion-select-all") {
        document.querySelectorAll("[data-exclusion-select]").forEach(function (node) {
          node.checked = event.target.checked;
        });
      }
      updateExclusionBulkToolbar();
    }
  });
  document.addEventListener("click", function (event) {
    var cancelEdit = event.target.closest("[data-exclusion-edit-cancel]");
    if (cancelEdit) {
      editingExclusionId = null;
      renderExclusionRules();
      return;
    }
    var editRule = event.target.closest("[data-exclusion-edit]");
    if (editRule) {
      editingExclusionId = editRule.getAttribute("data-exclusion-edit");
      renderExclusionRules();
      var editRow = document.querySelector('[data-edit-row="' + editingExclusionId + '"]');
      if (editRow) editRow.scrollIntoView({ behavior: "smooth", block: "nearest" });
      return;
    }
    var bulkAction = event.target.closest("[data-exclusion-bulk]");
    if (bulkAction) {
      var action = bulkAction.getAttribute("data-exclusion-bulk");
      var selected = selectedExclusionIds();
      if (!selected.length) return;
      if (action === "delete" && !window.confirm(t("Delete selected exclusion rules?"))) return;
      var selectedMap = {};
      selected.forEach(function (id) { selectedMap[id] = true; });
      var nextRules = action === "delete" ?
        exclusionRules.filter(function (rule) { return !selectedMap[rule.id]; }) :
        exclusionRules.map(function (rule) {
          return selectedMap[rule.id] ? Object.assign({}, rule, { enabled: action === "enable" }) : rule;
        });
      saveExclusionRules(nextRules, action === "delete" ? t("Selected rules deleted.") : (action === "enable" ? t("Selected rules enabled.") : t("Selected rules disabled.")), bulkAction);
      return;
    }
    var enableDetailRule = event.target.closest("[data-enable-exclusion]");
    if (enableDetailRule) {
      var enableId = enableDetailRule.getAttribute("data-enable-exclusion");
      saveExclusionRules(exclusionRules.map(function (rule) {
        return rule.id === enableId ? Object.assign({}, rule, { enabled: true }) : rule;
      }), t("Exclusion rule enabled."), enableDetailRule);
      return;
    }
    var toggleExclusion = event.target.closest("[data-exclusion-toggle]");
    var deleteExclusion = event.target.closest("[data-exclusion-delete]");
    if (toggleExclusion) {
      var toggleId = toggleExclusion.getAttribute("data-exclusion-toggle");
      saveExclusionRules(exclusionRules.map(function (rule) {
        return rule.id === toggleId ? Object.assign({}, rule, { enabled: !rule.enabled }) : rule;
      }), t("Exclusion rule updated."));
      return;
    }
    if (deleteExclusion) {
      var deleteId = deleteExclusion.getAttribute("data-exclusion-delete");
      saveExclusionRules(exclusionRules.filter(function (rule) { return rule.id !== deleteId; }), t("Exclusion rule deleted."));
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
        detailsButton.textContent = detailRow.hidden ? t("Details") : t("Hide");
      }
      return;
    }
    var singleCopyButton = event.target.closest("[data-copy-ip]");
    var copyAllButton = event.target.closest("[data-copy-ips]");
    if (!singleCopyButton && !copyAllButton) return;
    var copyValue = "";
    var successMessage = "";
    if (copyAllButton) {
      try {
        var ipList = JSON.parse(copyAllButton.getAttribute("data-copy-ips") || "[]");
        if (!Array.isArray(ipList) || !ipList.length || ipList.some(function (ip) {
          return typeof ip !== "string" || !/^\d{1,3}(?:\.\d{1,3}){3}$/.test(ip);
        })) throw new Error(t("Invalid IP list"));
        copyValue = ipList.join("\n");
        successMessage = "Copied " + ipList.length + " displayed IP addresses";
      } catch (error) {
        showCopyNotice(t("Could not copy this IP list."), true);
        return;
      }
    } else {
      copyValue = singleCopyButton.getAttribute("data-copy-ip") || "";
      successMessage = "Copied " + copyValue;
    }
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
      field.value = copyValue;
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
      navigator.clipboard.writeText(copyValue).then(function () {
        showCopyNotice(successMessage, false);
      }).catch(function () {
        var copied = fallbackCopy();
        showCopyNotice(copied ? successMessage : t("Copy failed. Select the IP manually."), !copied);
      });
    } else {
      var copied = fallbackCopy();
      showCopyNotice(copied ? successMessage : t("Copy failed. Select the IP manually."), !copied);
    }
  });
  requestSnapshot();
}());
