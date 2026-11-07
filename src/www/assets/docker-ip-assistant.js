(function () {
  "use strict";

  var API_URL = "/plugins/ipscope/api.php";
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
      throw new Error("IPScope returned an invalid recommendation response.");
    }

    var recommendation = body.recommendations;
    if (!recommendation || typeof recommendation !== "object" || Array.isArray(recommendation)) {
      throw new Error("IPScope recommendation data is missing or invalid.");
    }
    if (!Array.isArray(recommendation.networks)) {
      throw new Error("IPScope recommendation networks are invalid.");
    }

    var timeStatus = recommendation.snapshot_time_status;
    var age = recommendation.snapshot_age_seconds;
    if (typeof timeStatus !== "string" ||
        (age !== null && (typeof age !== "number" || !Number.isFinite(age) || age < 0)) ||
        (timeStatus === "valid" && typeof age !== "number")) {
      throw new Error("IPScope snapshot timestamp data is invalid.");
    }

    recommendation.networks.forEach(function (item) {
      if (!item || typeof item !== "object" || Array.isArray(item) ||
          typeof item.network !== "string" || !Array.isArray(item.candidates)) {
        throw new Error("IPScope returned an invalid network recommendation.");
      }
    });

    var matches = recommendation.networks.filter(function (item) {
      return item.network === expectedNetwork;
    });
    if (matches.length > 1) {
      throw new Error("IPScope returned duplicate records for the selected network.");
    }

    var selected = matches[0] || null;
    if (selected) {
      var seenCandidates = Object.create(null);
      selected.candidates.forEach(function (candidate) {
        if (!candidate || typeof candidate !== "object" || Array.isArray(candidate)) {
          throw new Error("IPScope returned a candidate that is not an object.");
        }
        var ip = candidate.ip;
        if (!isValidIPv4Address(ip)) {
          throw new Error("IPScope returned an invalid candidate IPv4 address.");
        }
        if (seenCandidates[ip]) {
          throw new Error("IPScope returned duplicate candidate IP addresses.");
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
    title.textContent = "IPScope · Available IPs";

    var meta = document.createElement("span");
    meta.className = "ipscope-docker-ip-meta";
    meta.textContent = "Suggestions are advisory";

    heading.appendChild(title);
    heading.appendChild(meta);

    var actions = document.createElement("div");
    actions.className = "ipscope-docker-ip-actions";

    var findButton = makeButton("Find available IPs", "ipscope-docker-ip-find", loadCandidates);
    actions.appendChild(findButton);

    var message = document.createElement("p");
    message.className = "ipscope-docker-ip-message";
    message.setAttribute("role", "status");
    message.textContent = "Choose a custom Docker network, then check its available addresses.";

    var candidates = document.createElement("div");
    candidates.className = "ipscope-docker-ip-candidates";
    candidates.hidden = true;

    panel.appendChild(heading);
    panel.appendChild(actions);
    panel.appendChild(message);
    panel.appendChild(candidates);

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
          button.title = "Currently selected in Fixed IP field";
        } else if (!buttonSnapshotFresh) {
          button.title = "Rescan IPScope before using this address";
        } else {
          button.title = "Select this IP address";
        }

        button.textContent = selected && buttonSnapshotFresh
          ? button.dataset.ip + " · Selected"
          : button.dataset.ip;
      });

      if (!suggestionsLoaded) return;

      if (!currentIp) {
        setMessage("Select an address to fill the Fixed IP address field. IPScope will not apply or save the container.", false);
      } else if (matchingButton && snapshotFresh) {
        setMessage("Selected " + currentIp + ". Review the container settings before applying.", false);
      } else if (matchingButton) {
        setMessage("Fixed IP " + currentIp + " matches an old suggestion, but the inventory snapshot is stale. Rescan IPScope before relying on it.", false, true);
      } else {
        setMessage("Fixed IP " + currentIp + " is not in the current IPScope suggestions. Its availability has not been verified by this snapshot.", false, true);
      }
    }

    ipInput.addEventListener("input", syncSelectedCandidate);
    ipInput.addEventListener("change", syncSelectedCandidate);

    function clearCandidateState() {
      suggestionsLoaded = false;
      candidateButtons = [];
      snapshotGeneratedAt = null;
      snapshotTimeStatus = "";
      candidates.textContent = "";
      candidates.hidden = true;
    }

    function setMessage(text, error, warning) {
      message.textContent = text;
      message.classList.toggle("is-error", !!error);
      message.classList.toggle("is-warning", !!warning && !error);
    }

    function loadCandidates() {
      var network = currentNetwork();
      if (!network || /^(bridge|host|container|none)$/i.test(network)) {
        setMessage("Select a custom Docker network with a managed IPv4 address pool first.", true);
        candidates.hidden = true;
        return;
      }

      var requestId = ++requestSequence;
      findButton.disabled = true;
      findButton.textContent = "Checking…";
      suggestionsLoaded = false;
      candidateButtons = [];
      snapshotGeneratedAt = null;
      snapshotTimeStatus = "";
      candidates.textContent = "";
      candidates.hidden = true;
      setMessage("Checking the latest IPScope inventory…", false);

      var query = "?action=recommendations&network=" + encodeURIComponent(network) + "&limit=20";
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
            throw new Error("IPScope returned an invalid response. Open IPScope and check its status.");
          }
          if (!response.ok) {
            throw new Error(body && body.error ? body.error : "Could not load available IPs.");
          }
          if (!body || body.ok !== true) {
            throw new Error(body && body.error ? body.error : "IPScope returned an invalid response.");
          }
          return body;
        });
      }).then(function (body) {
        if (requestId !== requestSequence) return;
        if (currentNetwork() !== network) {
          setMessage("The selected Docker network changed. Check suggestions again.", true);
          return;
        }

        var validated = validateRecommendationPayload(body, network);
        var recommendation = validated.recommendation;
        var selected = validated.selected;
        candidates.textContent = "";
        candidateButtons = [];
        suggestionsLoaded = true;

        if (!selected || !Array.isArray(selected.candidates) || !selected.candidates.length) {
          candidates.hidden = true;
          meta.textContent = "0 candidates";
          if (String(ipInput.value || "").trim()) {
            syncSelectedCandidate();
          } else {
            setMessage("No available candidates were found for " + network + ". Check the latest scan and exclusion rules in IPScope.", false);
          }
          return;
        }

        var age = recommendation.snapshot_age_seconds == null ? NaN : Number(recommendation.snapshot_age_seconds);
        var timeStatus = String(recommendation.snapshot_time_status || "");
        snapshotTimeStatus = timeStatus;
        snapshotGeneratedAt = Number.isFinite(age) ? Date.now() - age * 1000 : null;
        var snapshotFresh = isSnapshotFresh();
        var ageLabel = Number.isFinite(age) ? (age < 60 ? "just now" : Math.floor(age / 60) + " min ago") : "unknown age";

        selected.candidates.forEach(function (candidate) {
          var ip = String(candidate.ip || "");
          if (!/^\d{1,3}(?:\.\d{1,3}){3}$/.test(ip)) return;
          var useButton = makeButton(ip, "ipscope-docker-ip-candidate", function () {
            if (!isSnapshotFresh()) {
              syncSelectedCandidate();
              setMessage("This inventory snapshot is not fresh enough. Rescan in IPScope before assigning an address.", true);
              return;
            }
            if (currentNetwork() !== network) {
              setMessage("The selected network changed. Please check suggestions again.", true);
              return;
            }
            ipInput.value = ip;
            ipInput.dispatchEvent(new Event("input", { bubbles: true }));
            ipInput.dispatchEvent(new Event("change", { bubbles: true }));
            setMessage("Selected " + ip + ". Review the container settings before applying.", false);
          });
          useButton.dataset.ip = ip;
          useButton.disabled = !snapshotFresh;
          if (!snapshotFresh) {
            useButton.setAttribute("aria-disabled", "true");
            useButton.title = "Rescan IPScope before using this address";
          }
          useButton.setAttribute("aria-pressed", "false");
          useButton.setAttribute("aria-label", (snapshotFresh ? "Use suggested IP " : "Unavailable until a fresh scan: ") + ip);
          candidateButtons.push(useButton);
          candidates.appendChild(useButton);
        });

        syncSelectedCandidate();

        var count = candidates.children.length;
        candidates.hidden = count === 0;
        meta.textContent = count + " shown · snapshot " + ageLabel;
        if (!snapshotFresh && !String(ipInput.value || "").trim()) {
          setMessage("Suggestions are disabled because the inventory snapshot is older than 5 minutes or its timestamp is unavailable. Run Scan Now in IPScope, then check again.", true);
        } else {
          syncSelectedCandidate();
        }
      }).catch(function (error) {
        if (requestId !== requestSequence) return;
        clearCandidateState();
        setMessage(error.message || "Could not load available IPs.", true);
        meta.textContent = "Suggestions unavailable";
      }).finally(function () {
        if (requestId !== requestSequence) return;
        findButton.disabled = false;
        findButton.textContent = "Find available IPs";
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
      meta.textContent = "Suggestions are advisory";
      setMessage("Network changed. Find available IPs to check this network.", false);
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
