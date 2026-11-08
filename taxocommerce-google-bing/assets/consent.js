/* TaxoCommerce Insights visitor consent. No third-party requests are made here. */
(function () {
    "use strict";
    var config = window.tciConsentConfig;
    if (!config || !config.cookie || !config.revision) {
        return;
    }

    function read() {
        var name = config.cookie + "=";
        var cookies = document.cookie.split(";");
        for (var i = 0; i < cookies.length; i++) {
            var current = cookies[i].trim();
            if (current.indexOf(name) !== 0) {
                continue;
            }
            var fields;
            try {
                fields = decodeURIComponent(current.slice(name.length)).split("|");
            } catch (error) {
                return null;
            }
            if (fields.length !== 5 || fields[0] !== "1" ||
                fields[1] !== String(config.revision) ||
                !/^[01]$/.test(fields[2]) || !/^[01]$/.test(fields[3]) ||
                !/^[01]$/.test(fields[4])) {
                return null;
            }
            return { statistics: fields[2] === "1", marketing: fields[3] === "1",
                preferences: fields[4] === "1" };
        }
        return null;
    }

    function deleteVendorCookies() {
        // Best-effort first-party cleanup; third-party cookies remain vendor-controlled.
        var domain = location.hostname;
        var parts = domain.split(".");
        var domains = [""];
        for (var i = 0; i < parts.length - 1; i++) {
            domains.push("; Domain=." + parts.slice(i).join("."));
        }
        document.cookie.split(";").forEach(function (entry) {
            var key = entry.split("=")[0].trim();
            if (!/^(_ga($|_)|_gid$|_gat($|_)|_clck$|_clsk$)/.test(key)) {
                return;
            }
            domains.forEach(function (suffix) {
                document.cookie = key + "=; Max-Age=0; Path=/; SameSite=Lax" + suffix;
            });
        });
    }

    function updateConsentAPI(choices) {
        // WP Consent API is optional; the first-party cookie also gates PHP.
        if (typeof window.wp_set_consent === "function") {
            ["statistics", "marketing", "preferences"].forEach(function (category) {
                window.wp_set_consent(category, choices[category] ? "allow" : "deny");
            });
        }
    }

    function store(choices) {
        var old = read();
        var payload = ["1", String(config.revision), choices.statistics ? "1" : "0",
            choices.marketing ? "1" : "0", choices.preferences ? "1" : "0"].join("|");
        var secure = location.protocol === "https:" ? "; Secure" : "";
        document.cookie = config.cookie + "=" + encodeURIComponent(payload) +
            "; Max-Age=" + (Number(config.days) || 180) * 86400 +
            "; Path=/; SameSite=Lax" + secure;
        updateConsentAPI(choices);
        if (old && ((old.statistics && !choices.statistics) || (old.marketing && !choices.marketing))) {
            deleteVendorCookies();
        }
        // Refresh so PHP and all integrations agree before loading vendor scripts.
        location.reload();
    }

    function init() {
        var banner = document.getElementById("tci-consent-banner");
        var details = document.getElementById("tci-consent-details");
        var reopen = document.getElementById("tci-consent-reopen");
        if (!banner || !details || !reopen) {
            return;
        }
        var current = read();
        banner.hidden = !!current;

        function populate() {
            var choices = read() || { statistics: false, marketing: false, preferences: false };
            details.querySelectorAll("[data-tci-category]").forEach(function (control) {
                control.checked = !!choices[control.getAttribute("data-tci-category")];
            });
        }

        reopen.addEventListener("click", function () {
            populate();
            details.hidden = false;
            banner.hidden = false;
            banner.querySelector("button").focus();
        });

        banner.addEventListener("click", function (event) {
            var button = event.target.closest("[data-tci-consent]");
            if (!button || !banner.contains(button)) {
                return;
            }
            var action = button.getAttribute("data-tci-consent");
            if (action === "accept") {
                store({ statistics: true, marketing: true, preferences: true });
            } else if (action === "reject") {
                store({ statistics: false, marketing: false, preferences: false });
            } else if (action === "configure") {
                populate();
                details.hidden = false;
            } else if (action === "save") {
                var choices = {};
                details.querySelectorAll("[data-tci-category]").forEach(function (control) {
                    choices[control.getAttribute("data-tci-category")] = control.checked;
                });
                store(choices);
            }
        });
    }

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", init);
    } else {
        init();
    }
}());
