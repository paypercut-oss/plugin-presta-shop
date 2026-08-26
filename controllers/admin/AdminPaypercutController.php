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
require_once _PS_MODULE_DIR_ . 'paypercut/classes/telemetry/bootstrap.php';

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
                case 'startDebugSession':
                    $this->ajaxStartDebugSession();

                    return;
                case 'stopDebugSession':
                    $this->ajaxStopDebugSession();

                    return;
                case 'debugSessionStatus':
                    $this->ajaxDebugSessionStatus();

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
        // Expire a session whose deadline passed while nobody was looking, so
        // the panel below is painted from state that is true right now.
        PaypercutTelemetrySession::reap();

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
            'paypercut_module_version' => Paypercut::VERSION,
            'paypercut_environment' => PaypercutEnvironment::current(),
            'paypercut_environments' => PaypercutEnvironment::ENVIRONMENTS,
            'paypercut_debug_session' => PaypercutTelemetrySession::describe(),
            'paypercut_debug_session_now' => time(),
            'paypercut_debug_session_poll' => PaypercutTelemetrySession::POLL_INTERVAL_SECONDS,
            'paypercut_debug_session_log' => PaypercutTelemetryAdmin::sentLogRows(),
            'paypercut_debug_session_log_max' => PaypercutSentLog::MAX_ENTRIES,
            'paypercut_debug_session_ends_at' => $this->debugSessionEndsAt(),
            'paypercut_api_host' => PaypercutEnvironment::host(PaypercutEnvironment::apiBaseUri(PaypercutEnvironment::current())),
            'paypercut_telemetry_host' => $this->telemetryHost(),
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

        $environment = PaypercutEnvironment::normalize(Tools::getValue('PAYPERCUT_ENVIRONMENT', PaypercutEnvironment::PRODUCTION));
        if ($environment === '') {
            $errors[] = $this->module->l('Select a valid Paypercut environment.', 'AdminPaypercut');
        }

        if (empty($errors)) {
            Configuration::updateValue(Paypercut::CONFIG_API_KEY, $apiKey);
            Configuration::updateValue(Paypercut::CONFIG_ENVIRONMENT, $environment);
            Configuration::updateValue(Paypercut::CONFIG_CHECKOUT_MODE, Tools::getValue('PAYPERCUT_CHECKOUT_MODE', 'hosted'));
            Configuration::updateValue(Paypercut::CONFIG_ORDER_STATUS_ID, (int) Tools::getValue('PAYPERCUT_ORDER_STATUS_ID', Configuration::get('PS_OS_PAYMENT')));
            Configuration::updateValue(Paypercut::CONFIG_STATEMENT_DESCRIPTOR, trim(Tools::getValue('PAYPERCUT_STATEMENT_DESCRIPTOR', '')));
            Configuration::updateValue(Paypercut::CONFIG_GOOGLE_PAY, (int) Tools::getValue('PAYPERCUT_GOOGLE_PAY', 0));
            Configuration::updateValue(Paypercut::CONFIG_APPLE_PAY, (int) Tools::getValue('PAYPERCUT_APPLE_PAY', 0));
            Configuration::updateValue(Paypercut::CONFIG_LOGGING, (int) Tools::getValue('PAYPERCUT_LOGGING', 0));

            // Payment method domain registration
            $this->ensurePaymentMethodDomain($apiKey);

            // A re-key or an environment change invalidates the session token,
            // and reap() is the single path that notices and tears it down.
            PaypercutTelemetrySession::reap();

            PaypercutTelemetryRecorder::record(
                PaypercutTelemetryEvent::of('connection.validated', array(
                    'source' => 'settings_save',
                    'is_bnpl' => false,
                    'environment' => $environment,
                    'api_key_mode' => (new PaypercutApi($apiKey))->detectMode(),
                ))
            );

            // A live session opens with one configuration snapshot; without
            // this, a setting changed mid-session is read against the snapshot
            // taken before it and the timeline lies.
            PaypercutTelemetryRecorder::record(
                PaypercutTelemetryEvent::environmentConfiguration(PaypercutEnvironmentSnapshot::values())
            );

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

        // The environment the merchant has selected but may not have saved yet:
        // testing a dev key against production is a confusing way to fail.
        $environment = PaypercutEnvironment::normalize(Tools::getValue('environment', ''));

        try {
            $api = new PaypercutApi($apiKey, $environment !== '' ? $environment : null);
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

            PaypercutTelemetryRecorder::record(
                PaypercutTelemetryEvent::of('connection.tested', array(
                    'is_bnpl' => false,
                    'ok' => true,
                    'environment' => $environment !== '' ? $environment : PaypercutEnvironment::current(),
                ))
            );

            $this->ajaxJson($response);
        } catch (Exception $e) {
            // The platform quotes a rejected key back inside its message, so
            // only the exception's class travels.
            PaypercutTelemetryRecorder::record(
                PaypercutTelemetryEvent::failure('connection.tested', 'credentials_rejected', array(
                    'is_bnpl' => false,
                    'ok' => false,
                    'environment' => $environment !== '' ? $environment : PaypercutEnvironment::current(),
                ))->because('validation threw ' . PaypercutTelemetryEvent::shortClassName($e))
            );

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
                        PaypercutTelemetryRecorder::record(
                            PaypercutTelemetryEvent::failure('connection.webhook_registration_failed', 'already_exists', array(
                                'source' => 'settings',
                            ))
                        );

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

                PaypercutTelemetryRecorder::record(
                    PaypercutTelemetryEvent::of('webhook.registered', array('source' => 'settings'))
                );

                $this->ajaxJson(array(
                    'success' => true,
                    'message' => $this->module->l('Webhook created successfully.', 'AdminPaypercut'),
                    'webhook_id' => $result['id'],
                ));
            } else {
                PaypercutTelemetryRecorder::record(
                    PaypercutTelemetryEvent::failure('webhook.registration_failed', 'rejected', array('source' => 'settings'))
                );

                $this->ajaxJson(array('error' => $this->module->l('Failed to create webhook.', 'AdminPaypercut')));
            }
        } catch (Exception $e) {
            PaypercutTelemetryRecorder::record(
                PaypercutTelemetryEvent::failure('webhook.registration_failed', 'rejected', array('source' => 'settings'))
                    ->because('threw ' . PaypercutTelemetryEvent::shortClassName($e))
            );

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

            PaypercutTelemetryRecorder::record(PaypercutTelemetryEvent::of('webhook.deleted'));

            $this->ajaxJson(array(
                'success' => true,
                'message' => $this->module->l('Webhook deleted successfully.', 'AdminPaypercut'),
            ));
        } catch (Exception $e) {
            PaypercutTelemetryRecorder::record(
                PaypercutTelemetryEvent::failure('webhook.delete_failed', 'rejected')
                    ->because('threw ' . PaypercutTelemetryEvent::shortClassName($e))
            );

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
            PaypercutTelemetryRecorder::record(
                PaypercutTelemetryEvent::failure('refund.rejected', 'invalid_amount', array('source' => 'admin'))
                    ->about(array('order_ref' => Paypercut::orderRef($order)))
            );

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
                PaypercutTelemetryRecorder::record(
                    PaypercutTelemetryEvent::failure('refund.rejected', 'missing_payment_intent', array('source' => 'admin'))
                        ->about(array('order_ref' => Paypercut::orderRef($order)))
                );

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

            // The reason is merchant-authored free text and is on the "not
            // shared" list, so only whether one was given travels.
            PaypercutTelemetryRecorder::record(
                PaypercutTelemetryEvent::of('refund.succeeded', array(
                    'source' => 'admin',
                    'is_partial' => $newTotalRefunded < $orderTotal,
                    'has_reason' => '' !== (string) $reason,
                    'has_refund_id' => isset($result['id']) && '' !== (string) $result['id'],
                ))->about(array(
                    'order_ref' => Paypercut::orderRef($order),
                    'payment_intent_id' => (string) $paymentIntentId,
                    'payment_id' => (string) $paymentId,
                ))
            );

            $this->ajaxJson(array(
                'success' => true,
                'message' => $this->module->l('Refund initiated successfully.', 'AdminPaypercut'),
                'refund_id' => isset($result['id']) ? $result['id'] : '',
            ));
        } catch (PaypercutApiException $e) {
            PaypercutTelemetryRecorder::record(
                PaypercutTelemetryEvent::apiFailure('refund.failed', $e, array(
                    'source' => 'admin',
                    'has_reason' => '' !== (string) Tools::getValue('reason', ''),
                ))->about(array(
                    'order_ref' => Paypercut::orderRef($order),
                    'payment_intent_id' => (string) $transaction->payment_intent_id,
                ))
            );

            $this->ajaxJson(array('error' => $e->getMessage()));
        } catch (Exception $e) {
            PaypercutTelemetryRecorder::record(
                PaypercutTelemetryEvent::failure('refund.failed', 'transport', array(
                    'source' => 'admin',
                    'has_reason' => '' !== (string) Tools::getValue('reason', ''),
                ), $e)->about(array('order_ref' => Paypercut::orderRef($order)))
            );

            $this->ajaxJson(array('error' => $e->getMessage()));
        }
    }

    // ──────────────────────────────────────────────
    // AJAX: Debug session
    // ──────────────────────────────────────────────

    /**
     * Mint a telemetry token and publish a session.
     *
     * The employee's identity and the CSRF token were both checked by
     * AdminController::init() before initContent() ran; this additionally
     * demands edit access on the module tab, because everything below writes.
     */
    private function ajaxStartDebugSession()
    {
        if (!$this->debugSessionAllowed(true)) {
            return;
        }

        PaypercutTelemetrySession::reap();

        $state = PaypercutTelemetrySession::describe();

        if ($state['state'] === 'running') {
            // Idempotent: a second click must not mint a second token.
            $this->ajaxJson(array_merge($state, array('success' => true, 'already_running' => true, 'now' => time())));

            return;
        }

        if (!PaypercutTelemetrySession::claimStartLock()) {
            $this->ajaxJson(array('error' => $this->module->l('A debug session is already being started.', 'AdminPaypercut')));

            return;
        }

        // The response is built first and emitted after the lock is released:
        // ajaxJson() ends the request with exit(), and PHP does not run finally
        // on exit().
        try {
            $result = $this->startDebugSession();
        } catch (Exception $e) {
            PaypercutTelemetrySession::releaseStartLock();

            throw $e;
        }

        PaypercutTelemetrySession::releaseStartLock();

        $this->ajaxJson($result);
    }

    /**
     * @return array  The JSON payload for the panel
     */
    private function startDebugSession()
    {
        $connection = PaypercutTelemetrySession::connection();

        if ($connection['secret'] === '') {
            return array('error' => $this->module->l('Save your Paypercut API key before starting a debug session.', 'AdminPaypercut'));
        }

        // Both hosts come from this one environment value. A token minted for
        // one environment is rejected by every other environment's edge, so
        // they must never be resolved independently.
        $mintBase = PaypercutEnvironment::apiBaseUri($connection['environment']);
        $edgeBase = PaypercutEnvironment::telemetryBaseUri($connection['environment']);

        if ($edgeBase === '') {
            return array('error' => $connection['environment'] === ''
                ? $this->module->l('This store does not record which Paypercut environment it uses, so a debug session cannot be started. Save your settings again, then try again.', 'AdminPaypercut')
                : $this->module->l('Debug sessions are not available on this store\'s Paypercut environment.', 'AdminPaypercut'));
        }

        $minter = new PaypercutTokenMinter();
        $response = $minter->mint($connection['secret'], $mintBase);
        $status = (int) $response['status'];

        if ($status !== 200) {
            return $this->rejectDebugSession(PaypercutMintErrorMapper::map($status, $response['body']), $response);
        }

        if ($response['token'] === '' || $response['expires_at'] === '') {
            return $this->rejectDebugSession(PaypercutMintErrorMapper::badResponse(), $response);
        }

        $now = time();
        $lifetime = PaypercutTokenMinter::deriveLifetime($response['expires_at'], $response['date'], $now);
        $skew = PaypercutTokenMinter::skew($response['date'], $now);

        if ($lifetime < PaypercutTelemetrySession::MIN_LIFETIME_SECONDS) {
            return $this->rejectDebugSession(PaypercutMintErrorMapper::clockSkew($skew), $response);
        }

        $expiresAt = $now
            + min($lifetime, PaypercutTelemetrySession::SESSION_MAX_SECONDS)
            - PaypercutTelemetrySession::SKEW_SECONDS;

        // Re-check under the lock: if anything published a session while the
        // mint was in flight, discard this token rather than store a second
        // one. An unreferenced token cannot be deleted by any teardown path.
        $existing = PaypercutTelemetrySession::describe();

        if ($existing['state'] === 'running') {
            return array_merge($existing, array('success' => true, 'already_running' => true, 'now' => time()));
        }

        $employee = $this->context->employee;
        $sessionId = 'dbg_' . Tools::passwdGen(16, 'NO_NUMERIC');

        PaypercutTelemetrySession::begin(array(
            'status' => 'active',
            'session_id' => $sessionId,
            'environment' => $connection['environment'],
            'edge_base' => $edgeBase,
            'started_at' => $now,
            'expires_at' => $expiresAt,
            'started_by' => (int) $employee->id,
            'started_by_name' => Tools::safeOutput($employee->firstname . ' ' . $employee->lastname),
            'key_fingerprint' => PaypercutTelemetrySession::fingerprint($connection['secret']),
            'ended_at' => 0,
            'reason_code' => '',
            'trace_id' => PaypercutTelemetryEvent::identifier($response['trace_id']),
            'request_id' => PaypercutTelemetryEvent::identifier($response['request_id']),
        ), $response['token']);

        $snapshot = PaypercutEnvironmentSnapshot::values();
        $envelopes = array(
            PaypercutTelemetryEvent::sessionStarted($sessionId, $connection['environment'], $expiresAt)->envelope(),
            PaypercutTelemetryEvent::environmentSnapshot($snapshot)->envelope(),
            PaypercutTelemetryEvent::environmentConfiguration($snapshot)->envelope(),
        );

        foreach (PaypercutTelemetryEvent::environmentModules(PaypercutActiveModules::values()) as $event) {
            $envelopes[] = $event->envelope();
        }

        PaypercutTelemetryQueue::append($envelopes);

        PaypercutTelemetrySession::audit('Telemetry: debug session started', array(
            'session_id' => $sessionId,
            'environment' => $connection['environment'],
            'expires_at' => $expiresAt,
            'clock_skew_s' => $skew,
        ));

        return array_merge(
            PaypercutTelemetrySession::describe(),
            array('success' => true, 'now' => time())
        );
    }

    /**
     * Record a start that did not happen, so the merchant can see why.
     *
     * @param array $mapped    { reason_code, message, retryable }
     * @param array $response  The mint response
     *
     * @return array
     */
    private function rejectDebugSession(array $mapped, array $response)
    {
        $traceId = PaypercutTelemetryEvent::identifier($response['trace_id']);
        $requestId = PaypercutTelemetryEvent::identifier($response['request_id']);

        PaypercutTelemetrySession::fail($mapped, $traceId, $requestId);

        // No session exists yet by definition, so this is the one failure that
        // can only be recorded locally.
        PaypercutTelemetrySession::audit('Telemetry: mint rejected', array(
            'status' => (int) $response['status'],
            'reason_code' => $mapped['reason_code'],
            'trace_id' => $traceId,
        ));

        return array(
            'error' => $mapped['message'],
            'reason_code' => $mapped['reason_code'],
            'retryable' => $mapped['retryable'],
            'trace_id' => $traceId,
        );
    }

    /**
     * End the session early at the merchant's request.
     */
    private function ajaxStopDebugSession()
    {
        if (!$this->debugSessionAllowed(true)) {
            return;
        }

        $record = PaypercutTelemetrySession::record();
        $runtime = PaypercutTelemetrySession::runtime();

        if (isset($record['status']) && $record['status'] === 'active') {
            PaypercutTelemetryQueue::append(array(
                PaypercutTelemetryEvent::sessionStopped(
                    (string) (isset($record['session_id']) ? $record['session_id'] : ''),
                    'merchant_stopped',
                    (int) (isset($runtime['events_sent']) ? $runtime['events_sent'] : 0),
                    (int) (isset($runtime['events_dropped']) ? $runtime['events_dropped'] : 0)
                )->envelope(),
            ));

            // Twice: the first pass clears anything already parked in flight,
            // the second carries the stop event itself. Bounded on purpose —
            // each pass can block for up to the edge timeout, and this is a
            // button click.
            $flusher = new PaypercutTelemetryFlusher();

            for ($attempt = 0; $attempt < 2; ++$attempt) {
                if (!$flusher->flushOnce()) {
                    break;
                }
            }
        }

        PaypercutTelemetrySession::end('merchant_stopped');

        $this->ajaxJson(array_merge(
            PaypercutTelemetrySession::describe(),
            array('success' => true, 'now' => time())
        ));
    }

    /**
     * The panel's poll, which doubles as the delivery trigger while the
     * merchant has this screen open: an authenticated back-office request is
     * the only place events are sent from.
     */
    private function ajaxDebugSessionStatus()
    {
        if (!$this->debugSessionAllowed()) {
            return;
        }

        PaypercutTelemetrySession::reap();

        $flusher = new PaypercutTelemetryFlusher();
        $flusher->flushOnce();

        // `now` travels with `expires_at` so the countdown is driven by the
        // server's clock, not the browser's.
        $this->ajaxJson(array_merge(
            PaypercutTelemetrySession::describe(),
            array('success' => true, 'now' => time())
        ));
    }

    /**
     * @param bool $write  Starting or stopping a session, rather than reading one
     *
     * @return bool  false when a response has already been sent
     */
    private function debugSessionAllowed($write = false)
    {
        if (!Validate::isLoadedObject($this->context->employee)) {
            $this->ajaxJson(array('error' => $this->module->l('Insufficient permissions.', 'AdminPaypercut')));

            return false;
        }

        // Minting a telemetry credential is a write. init() has already checked
        // that this employee may VIEW the module tab, which is a different
        // permission and a much lower bar.
        if ($write && empty($this->tabAccess['edit'])) {
            $this->ajaxJson(array('error' => $this->module->l('You do not have permission to start or stop a debug session.', 'AdminPaypercut')));

            return false;
        }

        return true;
    }

    /**
     * The edge host to name in the consent copy.
     *
     * An unrecognised environment resolves no edge at all — and refuses Start —
     * but the disclosure is still rendered, so it names the released host.
     *
     * @return string
     */
    private function telemetryHost()
    {
        $edge = PaypercutEnvironment::telemetryBaseUri(PaypercutEnvironment::current());

        if ($edge === '') {
            $edge = PaypercutEnvironment::TELEMETRY_BASE_URIS[PaypercutEnvironment::PRODUCTION];
        }

        return PaypercutEnvironment::host($edge);
    }

    /**
     * Wall-clock time the running session ends, for the server-painted panel.
     *
     * @return string
     */
    private function debugSessionEndsAt()
    {
        $state = PaypercutTelemetrySession::describe();

        return $state['expires_at'] > 0 ? date('H:i', $state['expires_at']) : '';
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
            PaypercutTelemetryRecorder::record(
                PaypercutTelemetryEvent::failure('settings.webhooks_unreadable', 'lookup_failed')
                    ->because('threw ' . PaypercutTelemetryEvent::shortClassName($e))
            );

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

                PaypercutTelemetryRecorder::record(PaypercutTelemetryEvent::of('payment_domain.registered'));
            }
        } catch (Exception $e) {
            // Non-fatal: log and continue
            $this->module->logError('Domain registration: ' . $e->getMessage());

            PaypercutTelemetryRecorder::record(
                PaypercutTelemetryEvent::failure('payment_domain.registration_failed', 'rejected')
                    ->because('threw ' . PaypercutTelemetryEvent::shortClassName($e))
            );
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
