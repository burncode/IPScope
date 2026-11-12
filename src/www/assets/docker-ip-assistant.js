(function () {
  "use strict";

  var API_URL = "/plugins/ipscope/api.php";

  function t(text) {
    return window.IPScopeI18n && typeof window.IPScopeI18n.t === "function"
      ? window.IPScopeI18n.t(text)
      : text;
  }
  var PANEL_ID = "ipscope-docker-ip-assistant";
  var INPUT_SELECTOR = 'input[name="contMyIP"]';
  var NETWORK_SELECTOR = 'select[name="contNetwork"]';

  function isValidIPv4Address(value) {
    if (typeof value !== "string") return false;
    var octets = value.split(".");
    if (octets.length !== 4) return false;
    return octets.every(function (octet) {
      if (!/^\d{1,3}$/.test(octet) || (octet.length > 1 && octet.charAt(0) === "0")) return false;
      var number = Number(octet);
      return number >= 0 && number <= 255;
    });
  }

  function validateRecommendationPayload(body, expectedNetwork) {
    if (!body || typeof body !== "object" || Array.isArray(body) || body.ok !== true) {
      throw new Error(t("js.ipscope_returned_an_invalid_recommendation_response"));
    }

    var recommendation = body.recommendations;
    if (!recommendation || typeof recommendation !== "object" || Array.isArray(recommendation)) {
      throw new Error(t("js.ipscope_recommendation_data_is_missing_or_invalid"));
    }
    if (!Array.isArray(recommendation.networks)) {
      throw new Error(t("js.ipscope_recommendation_networks_are_invalid"));
    }

    var timeStatus = recommendation.snapshot_time_status;
    var age = recommendation.snapshot_age_seconds;
    if (typeof timeStatus !== "string" ||
        ["valid", "stale", "missing", "future", "invalid"].indexOf(timeStatus) === -1 ||
        (age !== null && (typeof age !== "number" || !Number.isFinite(age) || age < 0)) ||
        (timeStatus === "valid" && typeof age !== "number") ||
        (timeStatus !== "valid" && age !== null)) {
      throw new Error(t("js.ipscope_snapshot_timestamp_data_is_invalid"));
    }

    recommendation.networks.forEach(function (item) {
      if (!item || typeof item !== "object" || Array.isArray(item) ||
          typeof item.network !== "string" || !Array.isArray(item.candidates)) {
        throw new Error(t("js.ipscope_returned_an_invalid_network_recommendation"));
      }
    });

    var matches = recommendation.networks.filter(function (item) {
      return item.network === expectedNetwork;
    });
    if (matches.length > 1) {
      throw new Error(t("js.ipscope_returned_duplicate_records_for_the_selected_net"));
    }

    var selected = matches[0] || null;
    if (selected) {
      var seenCandidates = Object.create(null);
      selected.candidates.forEach(function (candidate) {
        if (!candidate || typeof candidate !== "object" || Array.isArray(candidate)) {
          throw new Error(t("js.ipscope_returned_a_candidate_that_is_not_an_object"));
        }
        var ip = candidate.ip;
        if (!isValidIPv4Address(ip)) {
          throw new Error(t("js.ipscope_returned_an_invalid_candidate_ipv4_address"));
        }
        if (seenCandidates[ip]) {
          throw new Error(t("js.ipscope_returned_duplicate_candidate_ip_addresses"));
        }
        seenCandidates[ip] = true;
      });
    }

    return { recommendation: recommendation, selected: selected };
  }

  function ready(callback) {
    if (document.readyState === "loading") {
      document.addEventListener("DOMContentLoaded", callback, { once: true });
    } else {
      callback();
    }
  }

  function makeButton(label, className, handler) {
    var button = document.createElement("button");
    button.type = "button";
    button.className = className;
    button.textContent = label;
    button.addEventListener("click", handler);
    return button;
  }

  function init() {
    var ipInput = document.querySelector(INPUT_SELECTOR);
    var networkSelect = document.querySelector(NETWORK_SELECTOR);
    var ipContainer = ipInput && ipInput.closest(".myIP");
    if (!ipInput || !networkSelect || !ipContainer || document.getElementById(PANEL_ID)) return;

    var panel = document.createElement("section");
    panel.id = PANEL_ID;
    panel.className = "ipscope-docker-ip-assistant";
    panel.setAttribute("aria-label", "IPScope available IP suggestions");

    var heading = document.createElement("div");
    heading.className = "ipscope-docker-ip-heading";

    var title = document.createElement("strong");
    title.textContent = "IPScope · " + t("js.available_ips");

    var meta = document.createElement("span");
    meta.className = "ipscope-docker-ip-meta";
    meta.textContent = t("js.suggestions_are_advisory");

    heading.appendChild(title);
    heading.appendChild(meta);

    var actions = document.createElement("div");
    actions.className = "ipscope-docker-ip-actions";

    var refreshButton = makeButton(t("js.refresh_inventory"), "ipscope-docker-ip-refresh", refreshInventory);
    var findButton = makeButton(t("js.find_available_ips"), "ipscope-docker-ip-find", function () { loadCandidates(0); });
    actions.appendChild(refreshButton);
    actions.appendChild(findButton);

    var message = document.createElement("p");
    message.className = "ipscope-docker-ip-message";
    message.setAttribute("role", "status");
    message.textContent = t("js.choose_a_custom_docker_network_then_check_its_available");

    var candidates = document.createElement("div");
    candidates.className = "ipscope-docker-ip-candidates";
    candidates.hidden = true;

    var pagination = document.createElement("div");
    pagination.className = "ipscope-docker-ip-pagination";
    var pageMeta = document.createElement("span");
    pageMeta.className = "ipscope-docker-ip-page-meta";
    var previousButton = makeButton(t("js.previous_batch"), "ipscope-docker-ip-page", function () {
      if (currentOffset >= PAGE_SIZE) loadCandidates(currentOffset - PAGE_SIZE);
    });
    var nextButton = makeButton(t("js.next_batch"), "ipscope-docker-ip-page", function () {
      if (currentOffset + PAGE_SIZE < totalCandidates) loadCandidates(currentOffset + PAGE_SIZE);
    });
    pagination.appendChild(pageMeta);
    pagination.appendChild(previousButton);
    pagination.appendChild(nextButton);
    pagination.hidden = true;

    panel.appendChild(heading);
    panel.appendChild(actions);
    panel.appendChild(message);
    panel.appendChild(candidates);
    panel.appendChild(pagination);

    // Keep the helper inside the native Fixed IP input cell. Inserting it as
    // a sibling of that cell makes Unraid's Markdown grid place it in column 1.
    var anchor = ipInput.parentElement;
    if (anchor) {
      anchor.appendChild(panel);
    } else {
      ipContainer.appendChild(panel);
    }

    // Match the helper to the actual Fixed IP input width, not the wider
    // Unraid form column that contains the input.
    function syncPanelWidth() {
      var inputWidth = ipInput.getBoundingClientRect().width;
      if (inputWidth > 0) {
        panel.style.width = Math.round(inputWidth) + "px";
        panel.style.maxWidth = "100%";
      }
    }
    syncPanelWidth();
    if (typeof ResizeObserver !== "undefined") {
      var inputSizeObserver = new ResizeObserver(syncPanelWidth);
      inputSizeObserver.observe(ipInput);
    } else {
      window.addEventListener("resize", syncPanelWidth);
    }

    var PAGE_SIZE = 20;
    var currentOffset = 0;
    var totalCandidates = 0;
    var candidateButtons = [];
    var suggestionsLoaded = false;
    var requestSequence = 0;
    var snapshotGeneratedAt = null;
    var snapshotTimeStatus = "";

    function currentNetwork() {
      return String(networkSelect.value || "").trim();
    }

    // Re-evaluate freshness against the live clock whenever the user interacts.
    // The API age is captured when the response arrives, so the deadline keeps
    // advancing instead of freezing the original boolean for the page lifetime.
    function isSnapshotFresh() {
      var now = Date.now();
      return snapshotTimeStatus === "valid" &&
        Number.isFinite(snapshotGeneratedAt) &&
        snapshotGeneratedAt <= now + 60000 &&
        now - snapshotGeneratedAt <= 300000;
    }

    // Keep the visual selection synchronized with both suggested and manually
    // edited values in Unraid's native Fixed IP field.
    function syncSelectedCandidate() {
      var currentIp = String(ipInput.value || "").trim();
      var matchingButton = null;
      var snapshotFresh = candidateButtons.length > 0 && isSnapshotFresh();

      candidateButtons.forEach(function (button) {
        var selected = button.dataset.ip === currentIp;
        var buttonSnapshotFresh = isSnapshotFresh();
        if (selected) matchingButton = button;

        // A stale recommendation must never look like a verified selection.
        button.classList.toggle("is-selected", selected && buttonSnapshotFresh);
        button.disabled = !buttonSnapshotFresh || selected;
        button.setAttribute("aria-pressed", selected ? "true" : "false");
        button.setAttribute(
          "aria-label",
          selected ? "Selected IP " + button.dataset.ip : "Use suggested IP " + button.dataset.ip
        );

        if (selected && buttonSnapshotFresh) {
          button.title = t("js.currently_selected_in_fixed_ip_field");
        } else if (!buttonSnapshotFresh) {
          button.title = t("js.rescan_ipscope_before_using_this_address");
        } else {
          button.title = t("js.select_this_ip_address");
        }

        button.textContent = selected && buttonSnapshotFresh
          ? button.dataset.ip + " · Selected"
          : button.dataset.ip;
      });

      if (!suggestionsLoaded) return;

      if (!currentIp) {
        setMessage(t("js.select_an_address_to_fill_the_fixed_ip_address_field_ip"), false);
      } else if (matchingButton && snapshotFresh) {
        setMessage(t("js.selected_ip_review_the_container_settings_before_applyi").replace("{ip}", currentIp), false);
      } else if (matchingButton) {
        setMessage(t("js.fixed_ip_ip_matches_an_old_suggestion_but_the_inventory").replace("{ip}", currentIp), false, true);
      } else {
        setMessage(t("js.fixed_ip_ip_is_not_in_the_current_ipscope_suggestions_i").replace("{ip}", currentIp), false, true);
      }
    }

    ipInput.addEventListener("input", syncSelectedCandidate);
    ipInput.addEventListener("change", syncSelectedCandidate);

    function clearCandidateState() {
      suggestionsLoaded = false;
      candidateButtons = [];
      snapshotGeneratedAt = null;
      snapshotTimeStatus = "";
      totalCandidates = 0;
      currentOffset = 0;
      candidates.textContent = "";
      candidates.hidden = true;
      pagination.hidden = true;
    }

    function setLoading(loading) {
      findButton.disabled = loading;
      refreshButton.disabled = loading;
      previousButton.disabled = loading || currentOffset <= 0;
      nextButton.disabled = loading || currentOffset + PAGE_SIZE >= totalCandidates;
      candidateButtons.forEach(function (button) { button.disabled = loading || !isSnapshotFresh(); });
    }

    function refreshInventory() {
      var network = currentNetwork();
      if (!network || /^(bridge|host|container|none)$/i.test(network)) {
        setMessage(t("js.select_a_custom_docker_network_with_a_managed_ipv4_addr"), true);
        return;
      }
      var requestId = ++requestSequence;
      setLoading(true);
      refreshButton.textContent = t("js.refreshing_inventory");
      setMessage(t("js.refreshing_ipscope_inventory"), false);
      fetch(API_URL + "?action=scan", {
        method: "POST",
        credentials: "same-origin",
        headers: { "Accept": "application/json" },
        cache: "no-store"
      }).then(function (response) {
        return response.text().then(function (raw) {
          var body;
          try { body = raw ? JSON.parse(raw) : null; }
          catch (error) { throw new Error(t("js.ipscope_returned_an_invalid_response")); }
          if (!response.ok || !body || body.ok !== true) {
            throw new Error(body && body.error ? body.error : t("js.inventory_refresh_failed"));
          }
          return body;
        });
      }).then(function () {
        if (requestId !== requestSequence || currentNetwork() !== network) return;
        clearCandidateState();
        loadCandidates(0);
        setMessage(t("js.inventory_refreshed_loading_candidates"), false);
      }).catch(function (error) {
        if (requestId !== requestSequence) return;
        setMessage(error.message || t("js.inventory_refresh_failed"), true);
      }).finally(function () {
        if (requestId !== requestSequence) return;
        setLoading(false);
        refreshButton.textContent = t("js.refresh_inventory");
      });
    }

    function setMessage(text, error, warning) {
      message.textContent = text;
      message.classList.toggle("is-error", !!error);
      message.classList.toggle("is-warning", !!warning && !error);
    }

    function loadCandidates(offset) {
      offset = Number.isInteger(offset) && offset >= 0 ? offset : 0;
      var network = currentNetwork();
      if (!network || /^(bridge|host|container|none)$/i.test(network)) {
        setMessage(t("js.select_a_custom_docker_network_with_a_managed_ipv4_addr"), true);
        candidates.hidden = true;
        return;
      }

      var requestId = ++requestSequence;
      setLoading(true);
      findButton.textContent = t("js.checking");
      candidateButtons.forEach(function (button) { button.disabled = true; });
      setMessage(t("js.checking_the_latest_ipscope_inventory"), false);

      var query = "?action=recommendations&network=" + encodeURIComponent(network) + "&limit=" + PAGE_SIZE + "&offset=" + offset;
      fetch(API_URL + query, {
        method: "GET",
        credentials: "same-origin",
        headers: { "Accept": "application/json" },
        cache: "no-store"
      }).then(function (response) {
        return response.text().then(function (raw) {
          var body;
          try {
            body = raw ? JSON.parse(raw) : null;
          } catch (error) {
            throw new Error(t("js.ipscope_returned_an_invalid_response_open_ipscope_and_c"));
          }
          if (!response.ok) {
            throw new Error(body && body.error ? body.error : t("js.could_not_load_available_ips"));
          }
          if (!body || body.ok !== true) {
            throw new Error(body && body.error ? body.error : t("js.ipscope_returned_an_invalid_response"));
          }
          return body;
        });
      }).then(function (body) {
        if (requestId !== requestSequence) return;
        if (currentNetwork() !== network) {
          setMessage(t("js.the_selected_docker_network_changed_check_suggestions_a"), true);
          return;
        }

        var validated = validateRecommendationPayload(body, network);
        var recommendation = validated.recommendation;
        var selected = validated.selected;
        candidates.textContent = "";
        candidateButtons = [];
        suggestionsLoaded = true;
        currentOffset = offset;
        totalCandidates = selected ? Number(selected.candidate_count || 0) : 0;

        if (!selected || !Array.isArray(selected.candidates) || !selected.candidates.length) {
          candidates.hidden = true;
          pagination.hidden = true;
          meta.textContent = t("js.0_candidates");
          if (String(ipInput.value || "").trim()) {
            syncSelectedCandidate();
          } else {
            setMessage(t("js.no_available_candidates_were_found_for_this_network_che"), false);
          }
          return;
        }

        var age = recommendation.snapshot_age_seconds == null ? NaN : Number(recommendation.snapshot_age_seconds);
        var timeStatus = String(recommendation.snapshot_time_status || "");
        snapshotTimeStatus = timeStatus;
        snapshotGeneratedAt = Number.isFinite(age) ? Date.now() - age * 1000 : null;
        var snapshotFresh = isSnapshotFresh();
        var ageLabel = Number.isFinite(age) ? (age < 60 ? t("js.just_now") : Math.floor(age / 60) + " " + t("js.min_ago")) : t("js.unknown_age");

        selected.candidates.forEach(function (candidate) {
          var ip = String(candidate.ip || "");
          if (!/^\d{1,3}(?:\.\d{1,3}){3}$/.test(ip)) return;
          var useButton = makeButton(ip, "ipscope-docker-ip-candidate", function () {
            if (!isSnapshotFresh()) {
              syncSelectedCandidate();
              setMessage(t("js.this_inventory_snapshot_is_not_fresh_enough_rescan_in_i"), true);
              return;
            }
            if (currentNetwork() !== network) {
              setMessage(t("js.the_selected_network_changed_please_check_suggestions_a"), true);
              return;
            }
            ipInput.value = ip;
            ipInput.dispatchEvent(new Event("input", { bubbles: true }));
            ipInput.dispatchEvent(new Event("change", { bubbles: true }));
            setMessage(t("js.selected_address_review_the_container_settings_before_a").replace("{ip}", ip), false);
          });
          useButton.dataset.ip = ip;
          useButton.disabled = !snapshotFresh;
          if (!snapshotFresh) {
            useButton.setAttribute("aria-disabled", "true");
            useButton.title = t("js.rescan_ipscope_before_using_this_address");
          }
          useButton.setAttribute("aria-pressed", "false");
          useButton.setAttribute("aria-label", (snapshotFresh ? t("js.use_suggested_ip") + " " : t("js.unavailable_until_a_fresh_scan") + ": ") + ip);
          candidateButtons.push(useButton);
          candidates.appendChild(useButton);
        });

        syncSelectedCandidate();

        var count = candidates.children.length;
        candidates.hidden = count === 0;
        pagination.hidden = totalCandidates <= PAGE_SIZE;
        pageMeta.textContent = totalCandidates
          ? (currentOffset + 1) + "–" + Math.min(currentOffset + count, totalCandidates) + " / " + totalCandidates
          : "";
        previousButton.disabled = currentOffset <= 0;
        nextButton.disabled = currentOffset + PAGE_SIZE >= totalCandidates;
        meta.textContent = totalCandidates + " " + t("js.candidates_total") + " · " + count + " " + t("js.shown_snapshot") + " " + ageLabel;
        if (!snapshotFresh && !String(ipInput.value || "").trim()) {
          setMessage(t("js.suggestions_are_disabled_because_the_inventory_snapshot"), true);
        } else {
          syncSelectedCandidate();
        }
      }).catch(function (error) {
        if (requestId !== requestSequence) return;
        clearCandidateState();
        setMessage(error.message || t("js.could_not_load_available_ips"), true);
        meta.textContent = t("js.suggestions_unavailable");
      }).finally(function () {
        if (requestId !== requestSequence) return;
        setLoading(false);
        findButton.textContent = t("js.find_available_ips");
        refreshButton.textContent = t("js.refresh_inventory");
      });
    }

    networkSelect.addEventListener("change", function () {
      requestSequence += 1;
      suggestionsLoaded = false;
      candidateButtons = [];
      snapshotGeneratedAt = null;
      snapshotTimeStatus = "";
      candidates.textContent = "";
      candidates.hidden = true;
      pagination.hidden = true;
      totalCandidates = 0;
      currentOffset = 0;
      setLoading(false);
      meta.textContent = t("js.suggestions_are_advisory");
      setMessage(t("js.network_changed_find_available_ips_to_check_this_networ"), false);
    });

    // Unraid's showSubnet() toggles the Fixed IP section when network type changes.
    // Keep the helper hidden whenever the native Fixed IP field is not applicable.
    var visibilityObserver = new MutationObserver(function () {
      var hidden = ipContainer.hidden ||
        window.getComputedStyle(ipContainer).display === "none";
      panel.hidden = hidden;
    });
    visibilityObserver.observe(ipContainer, { attributes: true, attributeFilter: ["class", "style", "hidden"] });
    panel.hidden = ipContainer.hidden ||
      window.getComputedStyle(ipContainer).display === "none";
  }

  ready(init);
})();
