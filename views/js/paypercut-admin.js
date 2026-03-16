/**
 * Paypercut - Admin JavaScript
 *
 * Handles AJAX actions for admin configuration page and order panel.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

(function () {
    "use strict";

    document.addEventListener("DOMContentLoaded", function () {
        initTestConnection();
        initWebhookButtons();
        initRefundButton();
        hideDefaultRefundButtons();
    });

    /**
     * Test Connection button
     */
    function initTestConnection() {
        var btn = document.getElementById("paypercut-test-connection");
        if (!btn) return;

        btn.addEventListener("click", function () {
            var url = btn.getAttribute("data-url");
            var apiKeyInput = document.getElementById("PAYPERCUT_API_KEY");
            var resultSpan = document.getElementById(
                "paypercut-connection-result",
            );

            if (!apiKeyInput || !apiKeyInput.value.trim()) {
                resultSpan.innerHTML =
                    '<span class="label label-danger">Enter API key first</span>';
                return;
            }

            btn.disabled = true;
            btn.innerHTML =
                '<i class="material-icons" style="font-size:14px;vertical-align:middle">sync</i> Testing...';
            resultSpan.innerHTML = "";

            var formData = new FormData();
            formData.append("action", "testConnection");
            formData.append("api_key", apiKeyInput.value.trim());

            fetch(
                url +
                    "&action=testConnection&api_key=" +
                    encodeURIComponent(apiKeyInput.value.trim()),
            )
                .then(function (resp) {
                    return resp.json();
                })
                .then(function (data) {
                    if (data.success) {
                        var msg = data.message;
                        if (data.account_name)
                            msg += " (" + data.account_name + ")";
                        if (data.mode)
                            msg += " [" + data.mode.toUpperCase() + "]";
                        resultSpan.innerHTML =
                            '<span class="label label-success">' +
                            escapeHtml(msg) +
                            "</span>";
                    } else {
                        resultSpan.innerHTML =
                            '<span class="label label-danger">' +
                            escapeHtml(data.error || "Failed") +
                            "</span>";
                    }
                })
                .catch(function (err) {
                    resultSpan.innerHTML =
                        '<span class="label label-danger">Error: ' +
                        escapeHtml(err.message) +
                        "</span>";
                })
                .finally(function () {
                    btn.disabled = false;
                    btn.innerHTML =
                        '<i class="material-icons" style="font-size:14px;vertical-align:middle">power</i> Test Connection';
                });
        });
    }

    /**
     * Create / Delete Webhook buttons
     */
    function initWebhookButtons() {
        var createBtn = document.getElementById("paypercut-create-webhook");
        var deleteBtn = document.getElementById("paypercut-delete-webhook");
        var resultSpan = document.getElementById("paypercut-webhook-result");

        if (createBtn) {
            createBtn.addEventListener("click", function () {
                var url = createBtn.getAttribute("data-url");
                createBtn.disabled = true;
                createBtn.innerHTML =
                    '<i class="material-icons" style="font-size:14px;vertical-align:middle">sync</i> Creating...';

                fetch(url + "&action=createWebhook")
                    .then(function (resp) {
                        return resp.json();
                    })
                    .then(function (data) {
                        if (data.success) {
                            resultSpan.innerHTML =
                                '<span class="label label-success">' +
                                escapeHtml(data.message) +
                                "</span>";
                            setTimeout(function () {
                                window.location.reload();
                            }, 1500);
                        } else {
                            resultSpan.innerHTML =
                                '<span class="label label-danger">' +
                                escapeHtml(data.error || "Failed") +
                                "</span>";
                            createBtn.disabled = false;
                            createBtn.innerHTML =
                                '<i class="material-icons" style="font-size:14px;vertical-align:middle">add</i> Create Webhook';
                        }
                    })
                    .catch(function (err) {
                        resultSpan.innerHTML =
                            '<span class="label label-danger">' +
                            escapeHtml(err.message) +
                            "</span>";
                        createBtn.disabled = false;
                        createBtn.innerHTML =
                            '<i class="material-icons" style="font-size:14px;vertical-align:middle">add</i> Create Webhook';
                    });
            });
        }

        if (deleteBtn) {
            deleteBtn.addEventListener("click", function () {
                if (!confirm("Are you sure you want to delete the webhook?"))
                    return;

                var url = deleteBtn.getAttribute("data-url");
                deleteBtn.disabled = true;
                deleteBtn.innerHTML =
                    '<i class="material-icons" style="font-size:14px;vertical-align:middle">sync</i> Deleting...';

                fetch(url + "&action=deleteWebhook")
                    .then(function (resp) {
                        return resp.json();
                    })
                    .then(function (data) {
                        if (data.success) {
                            resultSpan.innerHTML =
                                '<span class="label label-success">' +
                                escapeHtml(data.message) +
                                "</span>";
                            setTimeout(function () {
                                window.location.reload();
                            }, 1500);
                        } else {
                            resultSpan.innerHTML =
                                '<span class="label label-danger">' +
                                escapeHtml(data.error || "Failed") +
                                "</span>";
                            deleteBtn.disabled = false;
                            deleteBtn.innerHTML =
                                '<i class="material-icons" style="font-size:14px;vertical-align:middle">delete</i> Delete Webhook';
                        }
                    })
                    .catch(function (err) {
                        resultSpan.innerHTML =
                            '<span class="label label-danger">' +
                            escapeHtml(err.message) +
                            "</span>";
                        deleteBtn.disabled = false;
                        deleteBtn.innerHTML =
                            '<i class="material-icons" style="font-size:14px;vertical-align:middle">delete</i> Delete Webhook';
                    });
            });
        }
    }

    /**
     * Refund button on admin order page
     */
    function initRefundButton() {
        var btn = document.getElementById("paypercut-refund-btn");
        if (!btn) return;

        btn.addEventListener("click", function () {
            var url = btn.getAttribute("data-url");
            var orderId = btn.getAttribute("data-order-id");
            var amountInput = document.getElementById(
                "paypercut-refund-amount",
            );
            var reasonInput = document.getElementById(
                "paypercut-refund-reason",
            );
            var resultDiv = document.getElementById("paypercut-refund-result");

            var amount = parseFloat(amountInput.value);
            if (isNaN(amount) || amount <= 0) {
                resultDiv.innerHTML =
                    '<div class="alert alert-danger">Enter a valid amount.</div>';
                return;
            }

            if (!confirm("Refund " + amount.toFixed(2) + "?")) return;

            btn.disabled = true;
            btn.innerHTML =
                '<i class="material-icons" style="font-size:14px;vertical-align:middle">sync</i> Processing...';
            resultDiv.innerHTML = "";

            var params =
                "&action=refund&id_order=" +
                orderId +
                "&amount=" +
                amount +
                "&reason=" +
                encodeURIComponent(reasonInput.value || "");

            fetch(url + params)
                .then(function (resp) {
                    return resp.json();
                })
                .then(function (data) {
                    if (data.success) {
                        resultDiv.innerHTML =
                            '<div class="alert alert-success">' +
                            escapeHtml(data.message) +
                            "</div>";
                        setTimeout(function () {
                            window.location.reload();
                        }, 2000);
                    } else {
                        resultDiv.innerHTML =
                            '<div class="alert alert-danger">' +
                            escapeHtml(data.error || "Refund failed") +
                            "</div>";
                        btn.disabled = false;
                        btn.innerHTML =
                            '<i class="material-icons" style="font-size:14px;vertical-align:middle">undo</i> Refund';
                    }
                })
                .catch(function (err) {
                    resultDiv.innerHTML =
                        '<div class="alert alert-danger">' +
                        escapeHtml(err.message) +
                        "</div>";
                    btn.disabled = false;
                    btn.innerHTML =
                        '<i class="material-icons" style="font-size:14px;vertical-align:middle">undo</i> Refund';
                });
        });
    }

    /**
     * Hide default PrestaShop refund/return buttons when the order was
     * paid via Paypercut. We detect this by checking for the Paypercut
     * admin order panel that is rendered only for Paypercut orders.
     *
     * Targeted buttons across PrestaShop 1.7.7+ / 8.x:
     *   - "Partial refund"    (.partial-refund-display)
     *   - "Standard refund"   (.standard-refund-display)
     *   - "Return products"   (.return-product-display)
     *
     * A body class is added so that the corresponding CSS rules in
     * paypercut-admin.css take effect, hiding buttons and form sections.
     */
    function hideDefaultRefundButtons() {
        var panel = document.querySelector(".paypercut-admin-order-panel");
        if (!panel) return;

        document.body.classList.add("paypercut-order");

        // Also explicitly hide via JS for maximum reliability (covers
        // late-rendered elements and edge-case race conditions).
        var selectors = [
            "button.partial-refund-display",
            "button.standard-refund-display",
            "button.return-product-display",
        ];

        selectors.forEach(function (sel) {
            var els = document.querySelectorAll(sel);
            els.forEach(function (el) {
                el.style.display = "none";
            });
        });
    }

    /**
     * Escape HTML
     */
    function escapeHtml(str) {
        var div = document.createElement("div");
        div.appendChild(document.createTextNode(str || ""));
        return div.innerHTML;
    }
})();
