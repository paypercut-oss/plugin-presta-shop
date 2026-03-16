<?php

/**
 * Paypercut Admin Controller
 *
 * Configuration page with tabbed interface:
 *   - API Configuration (key, test connection)
 *   - Payment Settings (checkout mode, wallets, statement descriptor, order status, payment method config)
 *   - Webhooks (create / delete / status)
 *   - General (logging)
 *
 * AJAX actions:
 *   - testConnection
 *   - createWebhook
 *   - deleteWebhook
 *   - refund (from order panel)
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'paypercut/classes/PaypercutApi.php';

class AdminPaypercutController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        $this->display = 'view';

        parent::__construct();

        if (!$this->module->active) {
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminModules'));
        }
    }

    // ──────────────────────────────────────────────
    // Main configuration page
    // ──────────────────────────────────────────────

    public function initContent()
    {
        parent::initContent();

        // Handle AJAX actions
        if (Tools::isSubmit('action')) {
            $action = Tools::getValue('action');

            switch ($action) {
                case 'testConnection':
                    $this->ajaxTestConnection();

                    return;
                case 'createWebhook':
                    $this->ajaxCreateWebhook();

                    return;
                case 'deleteWebhook':
                    $this->ajaxDeleteWebhook();

                    return;
                case 'refund':
                    $this->ajaxRefund();

                    return;
            }
        }

        // Handle form submission
        if (Tools::isSubmit('submitPaypercutSettings')) {
            $this->saveConfiguration();
        }

        $this->renderConfigurationPage();
    }

    // ──────────────────────────────────────────────
    // Form rendering
    // ──────────────────────────────────────────────

    private function renderConfigurationPage()
    {
        $apiKey = Configuration::get(Paypercut::CONFIG_API_KEY);

        // Detect mode
        $mode = '';
        if ($apiKey) {
            $api = new PaypercutApi($apiKey);
            $mode = $api->detectMode();
        }

        // Webhook status
        $webhookStatus = $this->getWebhookStatus();

        // Webhook URL for display
        $webhookUrl = $this->context->link->getModuleLink('paypercut', 'webhook', array(), true);

        // Order statuses
        $orderStatuses = OrderState::getOrderStates((int) $this->context->language->id);

        // Store currency
        $defaultCurrency = Currency::getDefaultCurrency();
        $currencySupported = in_array(strtoupper($defaultCurrency->iso_code), Paypercut::SUPPORTED_CURRENCIES);

        // Assign to Smarty
        $this->context->smarty->assign(array(
            'paypercut_module_path' => $this->module->getPathUri(),
            'paypercut_mode' => $mode,
            'paypercut_api_key' => $apiKey,
            'paypercut_checkout_mode' => Configuration::get(Paypercut::CONFIG_CHECKOUT_MODE) ?: 'hosted',
            'paypercut_order_status_id' => (int) Configuration::get(Paypercut::CONFIG_ORDER_STATUS_ID) ?: (int) Configuration::get('PS_OS_PAYMENT'),
            'paypercut_statement_descriptor' => Configuration::get(Paypercut::CONFIG_STATEMENT_DESCRIPTOR),
            'paypercut_google_pay' => (int) Configuration::get(Paypercut::CONFIG_GOOGLE_PAY),
            'paypercut_apple_pay' => (int) Configuration::get(Paypercut::CONFIG_APPLE_PAY),
            'paypercut_logging' => (int) Configuration::get(Paypercut::CONFIG_LOGGING),
            'paypercut_webhook_id' => Configuration::get(Paypercut::CONFIG_WEBHOOK_ID),
            'paypercut_webhook_secret' => Configuration::get(Paypercut::CONFIG_WEBHOOK_SECRET) ? '********' : '',
            'paypercut_webhook_url' => $webhookUrl,
            'paypercut_webhook_status' => $webhookStatus,
            'paypercut_order_statuses' => $orderStatuses,
            'paypercut_store_currency' => $defaultCurrency->iso_code,
            'paypercut_currency_supported' => $currencySupported,
            'paypercut_admin_ajax_url' => $this->context->link->getAdminLink('AdminPaypercut'),
            'paypercut_ps_version' => _PS_VERSION_,
        ));

        $this->context->smarty->assign('content', $this->context->smarty->fetch(
            _PS_MODULE_DIR_ . 'paypercut/views/templates/admin/configure.tpl'
        ));
    }

    // ──────────────────────────────────────────────
    // Save settings
    // ──────────────────────────────────────────────

    private function saveConfiguration()
    {
        $errors = array();

        $apiKey = trim(Tools::getValue('PAYPERCUT_API_KEY', ''));
        if (empty($apiKey)) {
            $errors[] = $this->module->l('API Key is required.', 'AdminPaypercut');
        }

        if (empty($errors)) {
            Configuration::updateValue(Paypercut::CONFIG_API_KEY, $apiKey);
            Configuration::updateValue(Paypercut::CONFIG_CHECKOUT_MODE, Tools::getValue('PAYPERCUT_CHECKOUT_MODE', 'hosted'));
            Configuration::updateValue(Paypercut::CONFIG_ORDER_STATUS_ID, (int) Tools::getValue('PAYPERCUT_ORDER_STATUS_ID', Configuration::get('PS_OS_PAYMENT')));
            Configuration::updateValue(Paypercut::CONFIG_STATEMENT_DESCRIPTOR, trim(Tools::getValue('PAYPERCUT_STATEMENT_DESCRIPTOR', '')));
            Configuration::updateValue(Paypercut::CONFIG_GOOGLE_PAY, (int) Tools::getValue('PAYPERCUT_GOOGLE_PAY', 0));
            Configuration::updateValue(Paypercut::CONFIG_APPLE_PAY, (int) Tools::getValue('PAYPERCUT_APPLE_PAY', 0));
            Configuration::updateValue(Paypercut::CONFIG_LOGGING, (int) Tools::getValue('PAYPERCUT_LOGGING', 0));

            // Payment method domain registration
            $this->ensurePaymentMethodDomain($apiKey);

            $this->confirmations[] = $this->module->l('Settings saved successfully.', 'AdminPaypercut');
        } else {
            $this->errors = $errors;
        }
    }

    // ──────────────────────────────────────────────
    // AJAX: Test connection
    // ──────────────────────────────────────────────

    private function ajaxTestConnection()
    {
        $apiKey = trim(Tools::getValue('api_key', ''));

        if (empty($apiKey)) {
            $this->ajaxJson(array('error' => $this->module->l('API Key is required.', 'AdminPaypercut')));

            return;
        }

        try {
            $api = new PaypercutApi($apiKey);
            $result = $api->testConnection();
            $mode = $api->detectMode();

            $response = array(
                'success' => true,
                'message' => $this->module->l('Connection successful!', 'AdminPaypercut'),
                'mode' => $mode,
            );

            if (isset($result['business_name'])) {
                $response['account_name'] = $result['business_name'];
            }

            $this->ajaxJson($response);
        } catch (Exception $e) {
            $this->ajaxJson(array('error' => $e->getMessage()));
        }
    }

    // ──────────────────────────────────────────────
    // AJAX: Webhook management
    // ──────────────────────────────────────────────

    private function ajaxCreateWebhook()
    {
        $apiKey = Configuration::get(Paypercut::CONFIG_API_KEY);

        if (empty($apiKey)) {
            $this->ajaxJson(array('error' => $this->module->l('API Key not configured.', 'AdminPaypercut')));

            return;
        }

        $webhookUrl = $this->context->link->getModuleLink('paypercut', 'webhook', array(), true);

        try {
            $api = new PaypercutApi($apiKey);

            // Check if webhook already exists
            $webhooks = $api->listWebhooks();
            if (isset($webhooks['items'])) {
                foreach ($webhooks['items'] as $wh) {
                    if (isset($wh['url']) && $wh['url'] === $webhookUrl) {
                        $this->ajaxJson(array(
                            'error' => $this->module->l('Webhook already exists for this URL.', 'AdminPaypercut'),
                            'webhook_id' => $wh['id'],
                        ));

                        return;
                    }
                }
            }

            // Create webhook
            $shopName = Configuration::get('PS_SHOP_NAME');
            $result = $api->createWebhook(array(
                'name' => 'PrestaShop - ' . $shopName,
                'url' => $webhookUrl,
                'enabled_events' => array(
                    'checkout_session.completed',
                ),
            ));

            if (isset($result['id'])) {
                Configuration::updateValue(Paypercut::CONFIG_WEBHOOK_ID, $result['id']);

                if (isset($result['secret'])) {
                    Configuration::updateValue(Paypercut::CONFIG_WEBHOOK_SECRET, $result['secret']);
                }

                $this->ajaxJson(array(
                    'success' => true,
                    'message' => $this->module->l('Webhook created successfully.', 'AdminPaypercut'),
                    'webhook_id' => $result['id'],
                ));
            } else {
                $this->ajaxJson(array('error' => $this->module->l('Failed to create webhook.', 'AdminPaypercut')));
            }
        } catch (Exception $e) {
            $this->ajaxJson(array('error' => $e->getMessage()));
        }
    }

    private function ajaxDeleteWebhook()
    {
        $apiKey = Configuration::get(Paypercut::CONFIG_API_KEY);
        $webhookId = Configuration::get(Paypercut::CONFIG_WEBHOOK_ID);

        if (empty($webhookId)) {
            $this->ajaxJson(array('error' => $this->module->l('No webhook configured.', 'AdminPaypercut')));

            return;
        }

        try {
            $api = new PaypercutApi($apiKey);
            $api->deleteWebhook($webhookId);

            Configuration::deleteByName(Paypercut::CONFIG_WEBHOOK_ID);
            Configuration::deleteByName(Paypercut::CONFIG_WEBHOOK_SECRET);

            $this->ajaxJson(array(
                'success' => true,
                'message' => $this->module->l('Webhook deleted successfully.', 'AdminPaypercut'),
            ));
        } catch (Exception $e) {
            $this->ajaxJson(array('error' => $e->getMessage()));
        }
    }

    // ──────────────────────────────────────────────
    // AJAX: Refund
    // ──────────────────────────────────────────────

    private function ajaxRefund()
    {
        $idOrder = (int) Tools::getValue('id_order');
        $amount = (float) Tools::getValue('amount');

        if (!$idOrder || $amount <= 0) {
            $this->ajaxJson(array('error' => $this->module->l('Invalid order or amount.', 'AdminPaypercut')));

            return;
        }

        $order = new Order($idOrder);

        if (!Validate::isLoadedObject($order) || $order->module !== $this->module->name) {
            $this->ajaxJson(array('error' => $this->module->l('Order not found or not a Paypercut order.', 'AdminPaypercut')));

            return;
        }

        $transaction = PaypercutTransaction::getByOrderId($idOrder);

        if (!$transaction) {
            $this->ajaxJson(array('error' => $this->module->l('Transaction not found.', 'AdminPaypercut')));

            return;
        }

        // Validate refund amount
        $totalRefunded = PaypercutRefund::getTotalRefunded($idOrder);
        $maxRefundable = ($transaction->amount / 100) - $totalRefunded;

        if ($amount > $maxRefundable) {
            $this->ajaxJson(array('error' => sprintf(
                $this->module->l('Maximum refundable amount is %s.', 'AdminPaypercut'),
                number_format($maxRefundable, 2)
            )));

            return;
        }

        try {
            $api = new PaypercutApi(Configuration::get(Paypercut::CONFIG_API_KEY));

            // Use payment_intent_id preferably, fallback to payment_id
            $paymentIntentId = $transaction->payment_intent_id;
            $paymentId = $transaction->payment_id;

            if (empty($paymentIntentId) && empty($paymentId)) {
                $this->ajaxJson(array('error' => $this->module->l('Payment ID not available for refund.', 'AdminPaypercut')));

                return;
            }

            $refundData = array(
                'amount' => (int) round($amount * 100),
            );

            if (!empty($paymentIntentId)) {
                $refundData['payment_intent'] = $paymentIntentId;
            } else {
                $refundData['payment'] = $paymentId;
            }

            $reason = Tools::getValue('reason', '');
            if (!empty($reason)) {
                $refundData['reason'] = $reason;
            }

            $result = $api->createRefund($refundData);

            // Store refund
            $refund = new PaypercutRefund();
            $refund->id_order = $idOrder;
            $refund->payment_id = $paymentId;
            $refund->refund_id = isset($result['id']) ? $result['id'] : '';
            $refund->amount = $amount;
            $refund->status = isset($result['status']) ? $result['status'] : 'pending';
            $refund->reason = $reason;
            $refund->id_shop = (int) $this->context->shop->id;
            $refund->add();

            // Add order note
            $currency = new Currency($order->id_currency);
            $comment = 'Refund initiated via Paypercut' . PHP_EOL;
            $comment .= 'Refund ID: ' . (isset($result['id']) ? $result['id'] : 'N/A') . PHP_EOL;
            $comment .= 'Amount: ' . number_format($amount, 2) . ' ' . $currency->iso_code;

            if (!empty($reason)) {
                $comment .= PHP_EOL . 'Reason: ' . $reason;
            }

            // Check if fully refunded
            $newTotalRefunded = $totalRefunded + $amount;
            $orderTotal = $transaction->amount / 100;

            if ($newTotalRefunded >= $orderTotal) {
                $refundStatusId = (int) Configuration::get('PS_OS_REFUND');
                if (!$refundStatusId) {
                    $refundStatusId = 7;
                }

                $history = new OrderHistory();
                $history->id_order = $idOrder;
                $history->changeIdOrderState($refundStatusId, $order);
                $history->addWithemail(true);
            }

            $this->ajaxJson(array(
                'success' => true,
                'message' => $this->module->l('Refund initiated successfully.', 'AdminPaypercut'),
                'refund_id' => isset($result['id']) ? $result['id'] : '',
            ));
        } catch (Exception $e) {
            $this->ajaxJson(array('error' => $e->getMessage()));
        }
    }

    // ──────────────────────────────────────────────
    // Webhook status helper
    // ──────────────────────────────────────────────

    private function getWebhookStatus()
    {
        $apiKey = Configuration::get(Paypercut::CONFIG_API_KEY);
        $webhookId = Configuration::get(Paypercut::CONFIG_WEBHOOK_ID);
        $webhookUrl = $this->context->link->getModuleLink('paypercut', 'webhook', array(), true);

        if (empty($apiKey)) {
            return array(
                'configured' => false,
                'message' => $this->module->l('Configure API key first.', 'AdminPaypercut'),
            );
        }

        if (empty($webhookId)) {
            return array(
                'configured' => false,
                'message' => $this->module->l('Webhook not configured.', 'AdminPaypercut'),
            );
        }

        try {
            $api = new PaypercutApi($apiKey);
            $webhook = $api->getWebhook($webhookId);

            if ($webhook && isset($webhook['url']) && $webhook['url'] === $webhookUrl) {
                $status = isset($webhook['status']) ? $webhook['status'] : 'unknown';

                return array(
                    'configured' => true,
                    'webhook_id' => $webhookId,
                    'status' => $status,
                    'message' => in_array($status, array('enabled', 'active'))
                        ? $this->module->l('Webhook is active.', 'AdminPaypercut')
                        : $this->module->l('Webhook exists but is not enabled.', 'AdminPaypercut'),
                    'enabled_events' => isset($webhook['enabled_events']) ? $webhook['enabled_events'] : array(),
                );
            }

            // Webhook ID stored but doesn't match – stale
            Configuration::deleteByName(Paypercut::CONFIG_WEBHOOK_ID);

            return array(
                'configured' => false,
                'message' => $this->module->l('Stored webhook no longer exists. Please create a new one.', 'AdminPaypercut'),
            );
        } catch (Exception $e) {
            return array(
                'configured' => false,
                'message' => $e->getMessage(),
            );
        }
    }

    // ──────────────────────────────────────────────
    // Domain registration helper
    // ──────────────────────────────────────────────

    private function ensurePaymentMethodDomain($apiKey)
    {
        try {
            $api = new PaypercutApi($apiKey);

            $shopUrl = Tools::getShopDomainSsl(true);
            $domain = parse_url($shopUrl, PHP_URL_HOST);

            if (empty($domain)) {
                return;
            }

            // Check existing domains
            $domains = $api->listPaymentMethodDomains();

            if (isset($domains['items'])) {
                foreach ($domains['items'] as $d) {
                    if (isset($d['domain_name']) && $d['domain_name'] === $domain) {
                        if (isset($d['id'])) {
                            Configuration::updateValue(Paypercut::CONFIG_DOMAIN_ID, $d['id']);
                        }

                        return;
                    }
                }
            }

            // Register domain
            $result = $api->registerPaymentMethodDomain($domain);

            if (isset($result['id'])) {
                Configuration::updateValue(Paypercut::CONFIG_DOMAIN_ID, $result['id']);
            }
        } catch (Exception $e) {
            // Non-fatal: log and continue
            $this->module->logError('Domain registration: ' . $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────
    // JSON helper
    // ──────────────────────────────────────────────

    private function ajaxJson(array $data)
    {
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
