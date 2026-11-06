(function () {
  "use strict";

  var API_URL = "/plugins/ipscope/api.php";
  var PANEL_ID = "ipscope-docker-ip-assistant";
  var INPUT_SELECTOR = 'input[name="contMyIP"]';
  var NETWORK_SELECTOR = 'select[name="contNetwork"]';

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

    var anchor = ipInput.parentElement;
    if (anchor && anchor.parentElement) {
      anchor.parentElement.insertBefore(panel, anchor.nextSibling);
    } else {
      ipContainer.appendChild(panel);
    }

    function currentNetwork() {
      return String(networkSelect.value || "").trim();
    }

    function setMessage(text, error) {
      message.textContent = text;
      message.classList.toggle("is-error", !!error);
    }

    function loadCandidates() {
      var network = currentNetwork();
      if (!network || /^(bridge|host|container|none)$/i.test(network)) {
        setMessage("Select a custom Docker network with a managed IPv4 address pool first.", true);
        candidates.hidden = true;
        return;
      }

      findButton.disabled = true;
      findButton.textContent = "Checking…";
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
          if (!response.ok || !body || body.ok !== true) {
            throw new Error(body && body.error ? body.error : "Could not load available IPs.");
          }
          return body;
        });
      }).then(function (body) {
        if (currentNetwork() !== network) {
          setMessage("The selected Docker network changed. Check suggestions again.", true);
          return;
        }

        var recommendation = body.recommendations || {};
        var networkResults = recommendation.networks || [];
        var selected = networkResults.find(function (item) { return item.network === network; });
        candidates.textContent = "";

        if (!selected || !Array.isArray(selected.candidates) || !selected.candidates.length) {
          candidates.hidden = true;
          setMessage("No available candidates were found for " + network + ". Check the latest scan and exclusion rules in IPScope.", false);
          meta.textContent = "0 candidates";
          return;
        }

        selected.candidates.forEach(function (candidate) {
          var ip = String(candidate.ip || "");
          if (!/^\d{1,3}(?:\.\d{1,3}){3}$/.test(ip)) return;
          var useButton = makeButton(ip, "ipscope-docker-ip-candidate", function () {
            if (currentNetwork() !== network) {
              setMessage("The selected network changed. Please check suggestions again.", true);
              return;
            }
            ipInput.value = ip;
            ipInput.dispatchEvent(new Event("input", { bubbles: true }));
            ipInput.dispatchEvent(new Event("change", { bubbles: true }));
            setMessage("Selected " + ip + ". Review the container settings before applying.", false);
          });
          useButton.setAttribute("aria-label", "Use suggested IP " + ip);
          candidates.appendChild(useButton);
        });

        var count = candidates.children.length;
        candidates.hidden = count === 0;
        var age = recommendation.snapshot_age_seconds == null ? NaN : Number(recommendation.snapshot_age_seconds);
        var ageLabel = Number.isFinite(age) ? (age < 60 ? "just now" : Math.floor(age / 60) + " min ago") : "unknown age";
        meta.textContent = count + " shown · snapshot " + ageLabel;
        if (Number.isFinite(age) && age > 300) {
          setMessage("The inventory snapshot is older than 5 minutes. These suggestions may be stale; rescan in IPScope before assigning an address.", true);
        } else {
          setMessage("Select an address to fill the Fixed IP address field. IPScope will not apply or save the container.", false);
        }
      }).catch(function (error) {
        candidates.hidden = true;
        setMessage(error.message || "Could not load available IPs.", true);
        meta.textContent = "Suggestions unavailable";
      }).finally(function () {
        findButton.disabled = false;
        findButton.textContent = "Find available IPs";
      });
    }

    networkSelect.addEventListener("change", function () {
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
