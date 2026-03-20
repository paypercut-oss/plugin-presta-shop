/**
 * Paypercut - Embedded Checkout (Front-Office)
 *
 * Lazy-loads the Paypercut Checkout SDK and creates a checkout session
 * only when the customer selects "Pay with Paypercut" on the payment step.
 *
 * Compatible with PrestaShop 8.x and 9.x.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */
(function () {
    "use strict";

    var PREFIX = "[Paypercut]";

    function log() {
        if (typeof console !== "undefined" && console.log) {
            var args = Array.prototype.slice.call(arguments);
            args.unshift(PREFIX);
            console.log.apply(console, args);
        }
    }

    function warn() {
        if (typeof console !== "undefined" && console.warn) {
            var args = Array.prototype.slice.call(arguments);
            args.unshift(PREFIX);
            console.warn.apply(console, args);
        }
    }

    function err() {
        if (typeof console !== "undefined" && console.error) {
            var args = Array.prototype.slice.call(arguments);
            args.unshift(PREFIX);
            console.error.apply(console, args);
        }
    }

    log("Script loaded. readyState:", document.readyState);

    /* ── State ───────────────────────────────────── */
    var checkout = null; // PaypercutCheckout instance
    var sessionLoading = false; // AJAX in-flight guard
    var sessionReady = false; // checkout rendered at least once
    var sdkLoaded = false; // CDN <script> injected
    var paypercutActive = false; // radio currently selected

    /* ── DOM references (resolved lazily) ────────── */
    var wrapper = null;
    var ajaxUrl = "";
    var token = "";
    var container = null;
    var confirmEl = null;

    /**
     * Resolve DOM references. Called lazily because the form block
     * (setForm HTML) may be injected into the DOM after this script loads.
     * Returns true if the wrapper element was found.
     */
    function resolveDOM() {
        if (wrapper) return true;

        wrapper = document.getElementById("paypercut-embedded-checkout");
        if (!wrapper) {
            warn("resolveDOM: #paypercut-embedded-checkout NOT found in DOM.");
            // Debug: list all elements with id containing 'paypercut'
            var all = document.querySelectorAll('[id*="paypercut"]');
            if (all.length) {
                log('resolveDOM: elements with "paypercut" in their id:');
                for (var i = 0; i < all.length; i++) {
                    log("  -", all[i].tagName, "#" + all[i].id);
                }
            } else {
                warn(
                    'resolveDOM: no elements with "paypercut" in id found anywhere in DOM.',
                );
            }
            return false;
        }

        ajaxUrl = wrapper.getAttribute("data-ajax-url") || "";
        token = wrapper.getAttribute("data-token") || "";
        container = document.getElementById("paypercut-checkout-container");
        confirmEl = document.getElementById("payment-confirmation");

        log("resolveDOM: OK", {
            ajaxUrl: ajaxUrl ? ajaxUrl.substring(0, 60) + "…" : "(empty)",
            token: token ? token.substring(0, 8) + "…" : "(empty)",
            container: !!container,
            confirmEl: !!confirmEl,
        });

        if (!ajaxUrl) {
            err(
                "resolveDOM: data-ajax-url is empty! Check template variable $paypercut_ajax_url.",
            );
        }
        if (!token) {
            err(
                "resolveDOM: data-token is empty! Check template variable $paypercut_token.",
            );
        }

        return true;
    }

    /* ── Helpers ─────────────────────────────────── */

    /**
     * Inject the Paypercut CDN script once, then call cb().
     */
    function loadSdk(cb) {
        if (sdkLoaded) {
            log("loadSdk: already loaded.");
            cb();
            return;
        }

        // Check if already on the page (e.g. cached or server-rendered)
        if (typeof PaypercutCheckout !== "undefined") {
            log("loadSdk: PaypercutCheckout already defined globally.");
            sdkLoaded = true;
            cb();
            return;
        }

        log("loadSdk: injecting CDN script…");
        var script = document.createElement("script");
        script.src =
            "https://cdn.jsdelivr.net/npm/@paypercut/checkout-js@1.0.14/dist/paypercut-checkout.iife.min.js";
        script.async = true;
        script.onload = function () {
            log(
                "loadSdk: CDN script loaded successfully. PaypercutCheckout available:",
                typeof PaypercutCheckout !== "undefined",
            );
            sdkLoaded = true;
            cb();
        };
        script.onerror = function () {
            err("loadSdk: FAILED to load CDN script!");
            showError("Failed to load payment SDK. Please refresh the page.");
        };
        document.head.appendChild(script);
    }

    /**
     * Show a user-visible error inside the checkout container.
     */
    function showError(message) {
        err("showError:", message);
        if (!container) {
            err("showError: container element missing, cannot display error.");
            return;
        }
        container.innerHTML =
            '<div class="alert alert-danger">' + escapeHtml(message) + "</div>";
    }

    /**
     * Show the loading spinner.
     */
    function showSpinner() {
        if (!container) {
            warn("showSpinner: container element missing.");
            return;
        }
        container.innerHTML =
            '<div class="paypercut-loading">' +
            '<div class="paypercut-spinner"></div>' +
            "<p>" +
            (wrapper.getAttribute("data-loading-text") ||
                "Loading payment form...") +
            "</p>" +
            "</div>";
    }

    /**
     * Minimal HTML-entity escaping.
     */
    function escapeHtml(str) {
        var div = document.createElement("div");
        div.appendChild(document.createTextNode(str || ""));
        return div.innerHTML;
    }

    /**
     * Inject CSS rules:
     * 1. Hide #payment-confirmation when Paypercut is active
     * 2. Dim the checkout form when terms are not accepted
     */
    (function injectHideRule() {
        var style = document.createElement("style");
        style.textContent =
            "body.paypercut-active #payment-confirmation { display: none !important; }" +
            "body.paypercut-terms-pending #paypercut-checkout-container { opacity: 0.45; pointer-events: none; }";
        document.head.appendChild(style);
    })();

    /**
     * Hide / show PrestaShop's native "Place Order" button.
     * Uses a body class + CSS !important so PS's own scripts cannot override it.
     */
    function togglePsConfirmation(show) {
        if (show) {
            document.body.classList.remove("paypercut-active");
        } else {
            document.body.classList.add("paypercut-active");
        }
    }

    /* ── Terms & Conditions gate ──────────────────── */

    var termsCheckbox = null;
    var termsOverlay = null;

    /**
     * Find the T&C checkbox (PS 8.x/9.x + custom themes).
     */
    function getTermsCheckbox() {
        if (termsCheckbox) return termsCheckbox;
        termsCheckbox =
            document.getElementById(
                "conditions_to_approve[terms-and-conditions]",
            ) ||
            document.querySelector(
                'input[name="conditions_to_approve[terms-and-conditions]"]',
            ) ||
            // Custom theme fallbacks
            document.getElementById("confirm-terms") ||
            document.getElementById("confirm_terms") ||
            document.querySelector('input[name="confirm_terms"]');
        return termsCheckbox;
    }

    /**
     * Returns true if there is no terms checkbox or it is already checked.
     */
    function areTermsAccepted() {
        var cb = getTermsCheckbox();
        return !cb || cb.checked;
    }

    /**
     * Show or hide the overlay that blocks the payment form until terms are accepted.
     */
    function updateTermsOverlay() {
        if (!wrapper) return;

        var accepted = areTermsAccepted();
        log("updateTermsOverlay: terms accepted =", accepted);

        if (accepted) {
            document.body.classList.remove("paypercut-terms-pending");
            if (termsOverlay && termsOverlay.parentNode) {
                termsOverlay.parentNode.removeChild(termsOverlay);
            }
            termsOverlay = null;
        } else {
            document.body.classList.add("paypercut-terms-pending");
            if (!termsOverlay) {
                termsOverlay = document.createElement("div");
                termsOverlay.className = "paypercut-terms-overlay";
                termsOverlay.innerHTML =
                    '<div class="paypercut-terms-overlay__message">' +
                    '<i class="material-icons">info</i>' +
                    "Please accept the terms and conditions to proceed." +
                    "</div>";
            }
            if (!termsOverlay.parentNode) {
                wrapper.appendChild(termsOverlay);
            }
        }
    }

    /* ── Core flow ───────────────────────────────── */

    /**
     * Called when the customer selects the Paypercut radio button.
     */
    function onPaypercutSelected() {
        log("onPaypercutSelected: called.");
        paypercutActive = true;

        // Resolve DOM lazily (form block may have been injected after script load)
        if (!resolveDOM()) {
            warn("onPaypercutSelected: DOM not resolved, aborting.");
            return;
        }

        togglePsConfirmation(false);
        updateTermsOverlay();

        // Already rendered – nothing to do
        if (sessionReady && checkout && checkout.isMounted()) {
            log("onPaypercutSelected: already rendered and mounted, skipping.");
            return;
        }

        // Already loading
        if (sessionLoading) {
            log("onPaypercutSelected: already loading, skipping.");
            return;
        }

        initCheckout();
    }

    /**
     * Called when the customer selects a different payment method.
     */
    function onPaypercutDeselected() {
        log("onPaypercutDeselected: called.");
        paypercutActive = false;
        togglePsConfirmation(true);
        // Remove terms overlay when leaving Paypercut
        if (termsOverlay && termsOverlay.parentNode) {
            termsOverlay.parentNode.removeChild(termsOverlay);
            termsOverlay = null;
        }
        document.body.classList.remove("paypercut-terms-pending");
    }

    /**
     * AJAX → create session → load SDK → render.
     */
    function initCheckout() {
        log("initCheckout: starting AJAX to", ajaxUrl);
        sessionLoading = true;
        showSpinner();

        // 1. Create checkout session via AJAX
        var xhr = new XMLHttpRequest();
        xhr.open("POST", ajaxUrl, true);
        xhr.setRequestHeader(
            "Content-Type",
            "application/x-www-form-urlencoded",
        );
        xhr.setRequestHeader("X-Requested-With", "XMLHttpRequest");

        xhr.onload = function () {
            log(
                "initCheckout: AJAX response. status:",
                xhr.status,
                "length:",
                xhr.responseText.length,
            );

            if (xhr.status !== 200) {
                sessionLoading = false;
                err(
                    "initCheckout: non-200 status:",
                    xhr.status,
                    "body:",
                    xhr.responseText.substring(0, 500),
                );
                showError(
                    "Payment service unavailable (HTTP " +
                        xhr.status +
                        "). Please try again.",
                );
                return;
            }

            var data;
            try {
                data = JSON.parse(xhr.responseText);
            } catch (e) {
                sessionLoading = false;
                err(
                    "initCheckout: failed to parse JSON:",
                    e.message,
                    "raw:",
                    xhr.responseText.substring(0, 500),
                );
                showError("Unexpected response. Please refresh the page.");
                return;
            }

            log(
                "initCheckout: parsed response:",
                JSON.stringify(data).substring(0, 300),
            );

            if (data.error) {
                sessionLoading = false;
                err("initCheckout: server returned error:", data.error);
                showError(data.error);
                return;
            }

            if (!data.checkout_id) {
                sessionLoading = false;
                err("initCheckout: no checkout_id in response!");
                showError(
                    "Failed to create payment session. Please try again.",
                );
                return;
            }

            log(
                "initCheckout: checkout_id =",
                data.checkout_id,
                "→ loading SDK…",
            );

            // 2. Load SDK then render
            loadSdk(function () {
                renderCheckout(data);
            });
        };

        xhr.onerror = function () {
            sessionLoading = false;
            err(
                "initCheckout: XHR network error (onerror).",
                "readyState:",
                xhr.readyState,
            );
            showError(
                "Network error. Please check your connection and try again.",
            );
        };

        var postBody = "paypercut_token=" + encodeURIComponent(token);
        log("initCheckout: sending POST body:", postBody.substring(0, 60));
        xhr.send(postBody);
    }

    /**
     * Initialise the PaypercutCheckout instance and subscribe to events.
     *
     * @param {Object} data  JSON from the AJAX endpoint
     */
    function renderCheckout(data) {
        log("renderCheckout: called with checkout_id =", data.checkout_id);

        // Clean previous instance if any (e.g. after expiry)
        if (checkout) {
            log("renderCheckout: destroying previous instance.");
            try {
                checkout.destroy();
            } catch (e) {
                /* noop */
            }
            checkout = null;
        }

        // Clear the container (remove spinner)
        container.innerHTML = "";

        var opts = {
            id: data.checkout_id,
            containerId: "#paypercut-checkout-container",
            ui_mode: "embedded",
            form_only: false,
            wallet_options: data.wallet_options || [],
        };
        log("renderCheckout: PaypercutCheckout options:", JSON.stringify(opts));

        try {
            checkout = PaypercutCheckout(opts);

            /* ── Success ─────────────────────────── */
            checkout.on("success", function (payload) {
                log("Event: success", payload);
                var url = data.confirm_url;
                url +=
                    (url.indexOf("?") !== -1 ? "&" : "?") +
                    "checkout_id=" +
                    encodeURIComponent(data.checkout_id);
                window.location.href = url;
            });

            /* ── Error ───────────────────────────── */
            checkout.on("error", function (errPayload) {
                err("Event: error", errPayload);
                var msg =
                    errPayload && errPayload.message
                        ? errPayload.message
                        : "Payment failed. Please try again.";
                showError(msg);
            });

            /* ── Expired ─────────────────────────── */
            checkout.on("expired", function () {
                warn("Event: expired");
                sessionReady = false;
                sessionLoading = false;
                if (checkout) {
                    try {
                        checkout.destroy();
                    } catch (e) {
                        /* noop */
                    }
                    checkout = null;
                }
                showError(
                    "Your payment session has expired. Please select the payment method again.",
                );

                // Allow re-init on next click
                var retryBtn = document.createElement("button");
                retryBtn.type = "button";
                retryBtn.className = "btn btn-primary mt-2";
                retryBtn.textContent = "Retry";
                retryBtn.addEventListener("click", function () {
                    initCheckout();
                });
                container.appendChild(retryBtn);
            });

            /* ── Loaded (iframe ready) ───────────── */
            checkout.on("loaded", function () {
                log("Event: loaded — iframe is ready.");
                sessionLoading = false;
                sessionReady = true;
                // Apply terms overlay now that the form is visible
                updateTermsOverlay();
            });

            log("renderCheckout: calling checkout.render()…");
            checkout.render();
            log('renderCheckout: render() called, waiting for "loaded" event.');
        } catch (e) {
            sessionLoading = false;
            err(
                "renderCheckout: exception during init/render:",
                e.message,
                e.stack,
            );
            showError("Failed to initialise payment form.");
        }
    }

    /* ── Payment-option selection detection ───────── */

    /**
     * Detect which payment option is currently selected.
     *
     * Works in both PrestaShop 8.x and 9.x by listening for
     * change events on payment-option radio buttons.
     */
    function isPaypercutRadio(radio) {
        // PS 8.x/9.x: data-module-name attribute on the radio input
        if (radio.getAttribute("data-module-name") === "paypercut") {
            return true;
        }
        // Fallback: check if the radio's associated containers hold our element
        var optionId = radio.id; // e.g. "payment-option-2"
        if (optionId) {
            // Check additional-information block (setAdditionalInformation)
            var infoContainer = document.getElementById(
                optionId + "-additional-information",
            );
            if (
                infoContainer &&
                infoContainer.querySelector(".paypercut-payment-option")
            ) {
                return true;
            }
            // Check the form block (setForm)
            var formBlock = document.getElementById(
                "pay-with-" + optionId + "-form",
            );
            if (
                formBlock &&
                formBlock.querySelector("#paypercut-embedded-checkout")
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Check if a tab-link <a> element corresponds to Paypercut.
     * Custom themes may use <a> tabs with data-action URLs or class names.
     */
    function isPaypercutTab(tabLink) {
        // Check by class name
        if (tabLink.classList && tabLink.classList.contains("paypercut")) {
            return true;
        }
        // Check data-action attribute for paypercut URL
        var action = tabLink.getAttribute("data-action") || "";
        if (action.indexOf("paypercut") !== -1) {
            return true;
        }
        // Check href attribute
        var href = tabLink.getAttribute("href") || "";
        if (href.indexOf("paypercut") !== -1 && href.indexOf("#") !== 0) {
            return true;
        }
        return false;
    }

    /**
     * Detect Paypercut selection via hidden input or active tab (custom themes).
     * Returns true if Paypercut is currently the selected payment method.
     */
    function detectCustomThemePaypercut() {
        // Check hidden payment_method input
        var hiddenInput =
            document.getElementById("payment_method") ||
            document.querySelector('input[name="payment_method"]');
        if (
            hiddenInput &&
            hiddenInput.value &&
            hiddenInput.value.indexOf("paypercut") !== -1
        ) {
            log(
                "detectCustomThemePaypercut: hidden input contains paypercut URL.",
            );
            return true;
        }
        // Check for active tab link with paypercut class or data-action
        var activeTab = document.querySelector(
            "#nav-tab-payment .nav-link.active, .payments .nav-link.active",
        );
        if (activeTab && isPaypercutTab(activeTab)) {
            log("detectCustomThemePaypercut: active tab link is Paypercut.");
            return true;
        }
        return false;
    }

    function handlePaymentOptionChange() {
        var radios = document.querySelectorAll('input[name="payment-option"]');
        log(
            "handlePaymentOptionChange: found",
            radios.length,
            "payment-option radio(s).",
        );

        if (radios.length === 0) {
            // No standard PS radio buttons — try custom-theme detection
            log(
                "handlePaymentOptionChange: no radios, trying custom theme detection…",
            );
            if (detectCustomThemePaypercut()) {
                log(
                    "handlePaymentOptionChange: → Paypercut SELECTED (custom theme)",
                );
                onPaypercutSelected();
            } else {
                log(
                    "handlePaymentOptionChange: → Paypercut NOT selected (custom theme)",
                );
                onPaypercutDeselected();
            }
            return;
        }

        var selected = false;
        for (var i = 0; i < radios.length; i++) {
            var radio = radios[i];
            var isOurs = isPaypercutRadio(radio);
            var checked = radio.checked;
            log(
                "  Radio #" + i + ":",
                radio.id,
                "data-module-name=" +
                    (radio.getAttribute("data-module-name") || "(none)"),
                "checked=" + checked,
                "isPaypercut=" + isOurs,
            );
            if (checked && isOurs) {
                selected = true;
            }
        }

        if (selected) {
            log("handlePaymentOptionChange: → Paypercut SELECTED");
            onPaypercutSelected();
        } else {
            log("handlePaymentOptionChange: → Paypercut NOT selected");
            onPaypercutDeselected();
        }
    }

    // Listen for changes on payment option radios (event delegation)
    document.addEventListener("change", function (e) {
        if (e.target && e.target.name === "payment-option") {
            log("Event: change on", e.target.id);
            // Small delay to let PS toggle the form containers
            setTimeout(handlePaymentOptionChange, 50);
        }
        // Also detect changes on hidden payment_method input (custom themes)
        if (e.target && e.target.name === "payment_method") {
            log("Event: change on payment_method hidden input");
            setTimeout(handlePaymentOptionChange, 50);
        }
    });

    // Also handle click for broader compat (clicks on labels that toggle radios)
    document.addEventListener("click", function (e) {
        if (!e.target) return;
        // Check if clicked element is or is inside a payment option label/container
        var radio = e.target.closest
            ? e.target.closest('input[name="payment-option"]')
            : null;
        if (!radio) {
            // Also detect clicks on label elements that toggle payment radios
            var label = e.target.closest
                ? e.target.closest(
                      '.payment-option, label[for^="payment-option"]',
                  )
                : null;
            if (label) {
                log("Event: click on payment option label/container");
                setTimeout(handlePaymentOptionChange, 100);
                return;
            }
            // Custom theme: detect clicks on payment tab links (<a> inside nav-tab-payment)
            var paymentTab = e.target.closest
                ? e.target.closest(
                      "#nav-tab-payment .nav-link, .payments .nav-link",
                  )
                : null;
            if (paymentTab) {
                log(
                    "Event: click on payment tab link",
                    paymentTab.id || paymentTab.className,
                );
                setTimeout(handlePaymentOptionChange, 100);
            }
            return;
        }
        log("Event: click on radio", radio.id);
        setTimeout(handlePaymentOptionChange, 50);
    });

    // Custom theme: observe the hidden payment_method input for value changes
    // (setPayment() via onclick doesn't fire 'change' on hidden inputs)
    (function observePaymentMethodInput() {
        var pmInput =
            document.getElementById("payment_method") ||
            document.querySelector('input[name="payment_method"]');
        if (!pmInput) return;
        var lastValue = pmInput.value;
        // Use MutationObserver on attribute changes
        if (typeof MutationObserver !== "undefined") {
            var observer = new MutationObserver(function () {
                if (pmInput.value !== lastValue) {
                    lastValue = pmInput.value;
                    log(
                        "MutationObserver: payment_method changed to",
                        lastValue,
                    );
                    setTimeout(handlePaymentOptionChange, 50);
                }
            });
            observer.observe(pmInput, {
                attributes: true,
                attributeFilter: ["value"],
            });
        }
        // Also poll — setAttribute doesn't always trigger MutationObserver for .value
        setInterval(function () {
            if (pmInput.value !== lastValue) {
                lastValue = pmInput.value;
                log("Poll: payment_method changed to", lastValue);
                setTimeout(handlePaymentOptionChange, 50);
            }
        }, 500);
    })();

    // Check on DOM ready if Paypercut is already selected (e.g. only payment option, or back-button)
    function initCheck() {
        log("initCheck: running initial detection…");
        handlePaymentOptionChange();

        // Watch the terms checkbox:
        // 1. Re-enforce hiding of PS "Place Order" button while Paypercut is active
        // 2. Toggle the terms overlay on the payment form
        var termsBox = getTermsCheckbox();
        if (termsBox) {
            log("initCheck: found terms checkbox, attaching listener.");
            termsBox.addEventListener("change", function () {
                if (paypercutActive) {
                    log(
                        "Terms checkbox changed while Paypercut active — updating overlay.",
                    );
                    togglePsConfirmation(false);
                    updateTermsOverlay();
                }
            });
        } else {
            log(
                "initCheck: no terms checkbox found (terms may not be required).",
            );
        }
    }

    if (document.readyState === "loading") {
        log("Document still loading, waiting for DOMContentLoaded…");
        document.addEventListener("DOMContentLoaded", function () {
            log("DOMContentLoaded fired.");
            setTimeout(initCheck, 100);
        });
    } else {
        log("Document already loaded (readyState:", document.readyState + ").");
        setTimeout(initCheck, 100);
    }
})();
