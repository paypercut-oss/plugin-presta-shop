<?php

/**
 * Paypercut Payments for PrestaShop
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/PaypercutApi.php';
require_once dirname(__FILE__) . '/classes/PaypercutTransaction.php';
require_once dirname(__FILE__) . '/classes/PaypercutRefund.php';
require_once dirname(__FILE__) . '/classes/PaypercutCustomer.php';

class Paypercut extends PaymentModule
{
    /* Configuration keys */
    const CONFIG_API_KEY = 'PAYPERCUT_API_KEY';
    const CONFIG_CHECKOUT_MODE = 'PAYPERCUT_CHECKOUT_MODE';
    const CONFIG_ORDER_STATUS_ID = 'PAYPERCUT_ORDER_STATUS_ID';
    const CONFIG_STATEMENT_DESCRIPTOR = 'PAYPERCUT_STATEMENT_DESCRIPTOR';
    const CONFIG_GOOGLE_PAY = 'PAYPERCUT_GOOGLE_PAY';
    const CONFIG_APPLE_PAY = 'PAYPERCUT_APPLE_PAY';
    const CONFIG_WEBHOOK_ID = 'PAYPERCUT_WEBHOOK_ID';
    const CONFIG_WEBHOOK_SECRET = 'PAYPERCUT_WEBHOOK_SECRET';
    const CONFIG_LOGGING = 'PAYPERCUT_LOGGING';
    const CONFIG_DOMAIN_ID = 'PAYPERCUT_DOMAIN_ID';

    const MODULE_ADMIN_CONTROLLER = 'AdminPaypercut';

    const HOOKS = array(
        'paymentOptions',
        'paymentReturn',
        'displayAdminOrderLeft',
        'displayAdminOrderMainBottom',
        'displayOrderConfirmation',
        'displayOrderDetail',
        'displayCustomerAccount',
        'actionPaymentCCAdd',
        'actionObjectShopAddAfter',
        'displayBackOfficeHeader',
    );

    const API_BASE_URL = 'https://api.paypercut.io';

    const SUPPORTED_CURRENCIES = array(
        'BGN',
        'DKK',
        'SEK',
        'NOK',
        'GBP',
        'EUR',
        'USD',
        'CHF',
        'CZK',
        'HUF',
        'PLN',
        'RON'
    );

    const SUPPORTED_LOCALES = array('bg', 'en', 'el', 'ro', 'hr', 'pl', 'cs', 'sl', 'sk');

    public function __construct()
    {
        $this->name = 'paypercut';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'Paypercut';
        $this->need_instance = 1;
        $this->bootstrap = true;
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        $this->ps_versions_compliancy = array(
            'min' => '1.7.7.0',
            'max' => _PS_VERSION_,
        );

        $this->controllers = array(
            'checkout',
            'redirect',
            'validation',
            'webhook',
        );

        parent::__construct();

        $this->displayName = $this->l('Paypercut Payments');
        $this->description = $this->l('Accept payments via Paypercut - Cards, Google Pay, Apple Pay and more.');
        $this->confirmUninstall = $this->l('Are you sure you want to uninstall the Paypercut payment module?');

        if (empty(Configuration::get(self::CONFIG_API_KEY))) {
            $this->warning = $this->l('API Key must be configured to accept payments.');
        }
    }

    /**
     * Module installation
     *
     * @return bool
     */
    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        // Register hooks
        foreach (self::HOOKS as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }

        // Create database tables
        if (!$this->installDatabase()) {
            return false;
        }

        // Install default configuration
        if (!$this->installConfiguration()) {
            return false;
        }

        // Install admin tab
        if (!$this->installTab()) {
            return false;
        }

        return true;
    }

    /**
     * Module uninstallation
     *
     * @return bool
     */
    public function uninstall()
    {
        // Remove configuration
        $this->uninstallConfiguration();

        // Remove admin tab
        $this->uninstallTab();

        // Note: we don't drop tables to preserve transaction history

        return parent::uninstall();
    }

    /**
     * Redirect to admin controller on Configure click
     */
    public function getContent()
    {
        Tools::redirectAdmin($this->context->link->getAdminLink(self::MODULE_ADMIN_CONTROLLER));
    }

    // ──────────────────────────────────────────────
    // HOOK: paymentOptions
    // ──────────────────────────────────────────────

    /**
     * @param array $params
     *
     * @return array|void
     */
    public function hookPaymentOptions(array $params)
    {
        if (!$this->active) {
            return;
        }

        /** @var Cart $cart */
        $cart = $params['cart'];

        if (false === Validate::isLoadedObject($cart) || false === $this->checkCurrency($cart)) {
            return array();
        }

        $paymentOptions = array();

        $checkoutMode = Configuration::get(self::CONFIG_CHECKOUT_MODE) ?: 'hosted';

        if ($checkoutMode === 'embedded') {
            $paymentOptions[] = $this->getEmbeddedPaymentOption();
        } else {
            $paymentOptions[] = $this->getHostedPaymentOption();
        }

        return $paymentOptions;
    }

    /**
     * Hosted payment option (external redirect)
     *
     * @return PaymentOption
     */
    private function getHostedPaymentOption()
    {
        $option = new PaymentOption();
        $option->setModuleName($this->name);
        $option->setCallToActionText($this->l('Pay with Paypercut'));
        $option->setAction($this->context->link->getModuleLink(
            $this->name,
            'redirect',
            array(),
            true
        ));
        $option->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/paypercut-square.png'));

        $this->context->smarty->assign(array(
            'paypercut_payment_methods' => $this->getPaymentMethodsList(),
            'paypercut_module_path' => $this->getPathUri(),
        ));

        $option->setAdditionalInformation(
            $this->context->smarty->fetch('module:paypercut/views/templates/front/payment_option.tpl')
        );

        return $option;
    }

    /**
     * Embedded payment option (form on checkout page)
     *
     * @return PaymentOption
     */
    private function getEmbeddedPaymentOption()
    {
        $option = new PaymentOption();
        $option->setModuleName($this->name);
        $option->setCallToActionText($this->l('Pay with Paypercut'));
        $option->setAction($this->context->link->getModuleLink(
            $this->name,
            'validation',
            array('embedded' => '1'),
            true
        ));
        $option->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/paypercut-square.png'));

        // AJAX URL for lazy checkout-session creation (called when customer selects this option)
        $ajaxUrl = $this->context->link->getModuleLink($this->name, 'checkout', array(), true);

        $this->context->smarty->assign(array(
            'paypercut_ajax_url' => $ajaxUrl,
            'paypercut_token' => Tools::getToken(false),
            'paypercut_loading_text' => $this->l('Loading payment form...'),
            'paypercut_payment_methods' => $this->getPaymentMethodsList(),
            'paypercut_module_path' => $this->getPathUri(),
            'paypercut_module_uri' => $this->getPathUri(),
        ));

        $option->setAdditionalInformation(
            $this->context->smarty->fetch('module:paypercut/views/templates/front/payment_option.tpl')
        );

        // NOTE: We intentionally do NOT use setForm(). Many PS 8.x/9.x themes
        // do not render setForm() content in the DOM. Instead, the embedded
        // checkout container is placed inside setAdditionalInformation() above,
        // which is reliably rendered by all themes.

        return $option;
    }

    // ──────────────────────────────────────────────
    // HOOK: paymentReturn
    // ──────────────────────────────────────────────

    /**
     * @param array $params
     *
     * @return string
     */
    public function hookPaymentReturn(array $params)
    {
        if (!$this->active) {
            return '';
        }

        /** @var Order $order */
        $order = isset($params['order']) ? $params['order'] : (isset($params['objOrder']) ? $params['objOrder'] : null);

        if (!$order || false === Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return '';
        }

        $transaction = PaypercutTransaction::getByOrderId((int) $order->id);

        $this->context->smarty->assign(array(
            'moduleName' => $this->name,
            'transaction_id' => $transaction ? $transaction->payment_id : '',
            'status' => $order->getCurrentState(),
            'shop_name' => $this->context->shop->name,
        ));

        return $this->context->smarty->fetch('module:paypercut/views/templates/hook/displayPaymentReturn.tpl');
    }

    // ──────────────────────────────────────────────
    // HOOK: displayAdminOrderLeft (PS < 1.7.7)
    // ──────────────────────────────────────────────

    /**
     * @param array $params
     *
     * @return string
     */
    public function hookDisplayAdminOrderLeft(array $params)
    {
        return $this->renderAdminOrderPanel($params);
    }

    // ──────────────────────────────────────────────
    // HOOK: displayAdminOrderMainBottom (PS >= 1.7.7)
    // ──────────────────────────────────────────────

    /**
     * @param array $params
     *
     * @return string
     */
    public function hookDisplayAdminOrderMainBottom(array $params)
    {
        return $this->renderAdminOrderPanel($params);
    }

    /**
     * Shared renderer for admin order panel
     *
     * @param array $params
     *
     * @return string
     */
    private function renderAdminOrderPanel(array $params)
    {
        if (empty($params['id_order'])) {
            return '';
        }

        $order = new Order((int) $params['id_order']);

        if (false === Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return '';
        }

        $transaction = PaypercutTransaction::getByOrderId((int) $order->id);

        if (!$transaction) {
            return '';
        }

        // Convert to array for Smarty dot-notation access
        $transactionData = array(
            'payment_id' => $transaction->payment_id,
            'checkout_id' => $transaction->checkout_id,
            'payment_intent_id' => $transaction->payment_intent_id,
            'payment_status' => $transaction->payment_status,
            'payment_method' => $transaction->payment_method,
            'amount' => $transaction->amount,
            'currency' => $transaction->currency,
        );

        // Get refunds
        $refunds = PaypercutRefund::getByOrderId((int) $order->id);
        $totalRefunded = PaypercutRefund::getTotalRefunded((int) $order->id);
        $maxRefundable = ($transactionData['amount'] / 100) - $totalRefunded;

        $this->context->smarty->assign(array(
            'moduleName' => $this->name,
            'moduleDisplayName' => $this->displayName,
            'moduleLogoSrc' => $this->getPathUri() . 'logo.png',
            'transaction' => $transactionData,
            'refunds' => $refunds,
            'total_refunded' => $totalRefunded,
            'max_refundable' => $maxRefundable,
            'dashboard_url' => 'https://dashboard.paypercut.io/payments/' . $transactionData['payment_id'],
            'refund_url' => $this->context->link->getAdminLink(self::MODULE_ADMIN_CONTROLLER) . '&action=refund',
            'id_order' => (int) $order->id,
        ));

        return $this->context->smarty->fetch('module:paypercut/views/templates/hook/displayAdminOrderMainBottom.tpl');
    }

    // ──────────────────────────────────────────────
    // HOOK: displayOrderConfirmation
    // ──────────────────────────────────────────────

    /**
     * @param array $params
     *
     * @return string
     */
    public function hookDisplayOrderConfirmation(array $params)
    {
        if (empty($params['order'])) {
            return '';
        }

        /** @var Order $order */
        $order = $params['order'];

        if (false === Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return '';
        }

        $transaction = PaypercutTransaction::getByOrderId((int) $order->id);

        $this->context->smarty->assign(array(
            'moduleName' => $this->name,
            'transaction_id' => $transaction ? $transaction->payment_id : '',
        ));

        return $this->context->smarty->fetch('module:paypercut/views/templates/hook/displayPaymentReturn.tpl');
    }

    // ──────────────────────────────────────────────
    // HOOK: displayOrderDetail
    // ──────────────────────────────────────────────

    /**
     * @param array $params
     *
     * @return string
     */
    public function hookDisplayOrderDetail(array $params)
    {
        if (empty($params['order'])) {
            return '';
        }

        /** @var Order $order */
        $order = $params['order'];

        if (false === Validate::isLoadedObject($order) || $order->module !== $this->name) {
            return '';
        }

        $transaction = PaypercutTransaction::getByOrderId((int) $order->id);

        $this->context->smarty->assign(array(
            'moduleName' => $this->name,
            'transaction_id' => $transaction ? $transaction->payment_id : '',
            'status' => $transaction ? $transaction->payment_status : '',
        ));

        return $this->context->smarty->fetch('module:paypercut/views/templates/hook/displayOrderDetail.tpl');
    }

    // ──────────────────────────────────────────────
    // HOOK: displayBackOfficeHeader (load admin CSS)
    // ──────────────────────────────────────────────

    /**
     * @param array $params
     *
     * @return void
     */
    public function hookDisplayBackOfficeHeader(array $params)
    {
        if (
            'AdminOrders' === Tools::getValue('controller')
            || self::MODULE_ADMIN_CONTROLLER === Tools::getValue('controller')
        ) {
            $this->context->controller->addCSS($this->getPathUri() . 'views/css/paypercut-admin.css');
            $this->context->controller->addJS($this->getPathUri() . 'views/js/paypercut-admin.js');
        }
    }

    // ──────────────────────────────────────────────
    // HOOK: actionObjectShopAddAfter (multi-shop)
    // ──────────────────────────────────────────────

    /**
     * @param array $params
     */
    public function hookActionObjectShopAddAfter(array $params)
    {
        if (empty($params['object'])) {
            return;
        }

        /** @var Shop $shop */
        $shop = $params['object'];

        if (false === Validate::isLoadedObject($shop)) {
            return;
        }

        $this->addCheckboxCarrierRestrictionsForModule(array((int) $shop->id));
        $this->addCheckboxCountryRestrictionsForModule(array((int) $shop->id));

        if ($this->currencies_mode === 'checkbox') {
            $this->addCheckboxCurrencyRestrictionsForModule(array((int) $shop->id));
        } elseif ($this->currencies_mode === 'radio') {
            $this->addRadioCurrencyRestrictionsForModule(array((int) $shop->id));
        }
    }

    // ──────────────────────────────────────────────
    // Checkout helpers
    // ──────────────────────────────────────────────

    /**
     * Build checkout session payload from a cart
     *
     * @param Cart   $cart
     * @param string $uiMode hosted|embedded
     *
     * @return array
     */
    public function buildCheckoutPayload(Cart $cart, $uiMode = 'hosted')
    {
        $currency = new Currency($cart->id_currency);
        $customer = new Customer($cart->id_customer);
        $totalAmount = (int) round($cart->getOrderTotal(true, Cart::BOTH) * 100);

        // Build line items using price_data structure per API spec
        $currencyCode = strtoupper($currency->iso_code);
        $lineItems = array();
        foreach ($cart->getProducts() as $product) {
            $lineItems[] = array(
                'quantity' => (int) $product['cart_quantity'],
                'price_data' => array(
                    'currency' => $currencyCode,
                    'unit_amount' => (int) round($product['price_wt'] * 100),
                    'type' => 'one_time',
                    'product_data' => array(
                        'name' => $product['name'],
                    ),
                ),
            );
        }

        $successUrlParams = array(
            'id_cart' => (int) $cart->id,
            'key' => $customer->secure_key,
        );
        $successUrl = $this->context->link->getModuleLink($this->name, 'validation', $successUrlParams, true);
        $cancelUrl = $this->context->link->getPageLink('order', true, null, array('step' => '3'));

        $payload = array(
            'amount' => $totalAmount,
            'currency' => $currencyCode,
            'mode' => 'payment',
            'ui_mode' => $uiMode,
            'client_reference_id' => (string) $cart->id,
        );

        // success_url is not allowed for embedded/custom ui_mode; use return_url instead
        if ($uiMode === 'hosted') {
            $payload['success_url'] = $successUrl;
            $payload['cancel_url'] = $cancelUrl;
        } else {
            $payload['return_url'] = $successUrl;
        }

        // Customer handling
        $paypercutCustomerId = null;
        if (Validate::isLoadedObject($customer) && $customer->id) {
            $paypercutCustomerId = PaypercutCustomer::getPaypercutId((int) $customer->id);
        }

        if ($paypercutCustomerId) {
            $payload['customer'] = $paypercutCustomerId;
        } else {
            $payload['customer_email'] = Validate::isLoadedObject($customer) ? $customer->email : '';
        }

        // Wallet options (only applicable to embedded checkout; hosted always shows everything)
        if ($uiMode !== 'hosted') {
            $walletOptions = array();
            $googlePay = Configuration::get(self::CONFIG_GOOGLE_PAY);
            $applePay = Configuration::get(self::CONFIG_APPLE_PAY);

            if ($googlePay !== false) {
                $walletOptions['google_pay'] = array('display' => $googlePay ? 'auto' : 'never');
            }
            if ($applePay !== false) {
                $walletOptions['apple_pay'] = array('display' => $applePay ? 'auto' : 'never');
            }
            if (!empty($walletOptions)) {
                $payload['wallet_options'] = $walletOptions;
            }
        }

        // Statement descriptor
        $descriptor = Configuration::get(self::CONFIG_STATEMENT_DESCRIPTOR);
        if (!empty($descriptor)) {
            $payload['payment_intent_data'] = array(
                'statement_descriptor' => Tools::substr($descriptor, 0, 22),
            );
        }

        // Line items
        if (!empty($lineItems)) {
            $payload['line_items'] = $lineItems;
        }

        // Locale
        $locale = $this->getPaypercutLocale();
        if ($locale) {
            $payload['locale'] = $locale;
        }

        return $payload;
    }

    /**
     * Map Paypercut payment status to PrestaShop OrderState ID
     *
     * @param string $paymentStatus
     *
     * @return int
     */
    public function getOrderStatusForPaymentStatus($paymentStatus)
    {
        $configuredSuccessStatus = (int) Configuration::get(self::CONFIG_ORDER_STATUS_ID);
        if (!$configuredSuccessStatus) {
            $configuredSuccessStatus = (int) Configuration::get('PS_OS_PAYMENT');
        }

        $map = array(
            'succeeded'        => $configuredSuccessStatus,
            'pending'          => (int) Configuration::get('PS_OS_PREPARATION'),
            'processing'       => (int) Configuration::get('PS_OS_PREPARATION'),
            'requires_capture' => (int) Configuration::get('PS_OS_PREPARATION'),
            'failed'           => (int) Configuration::get('PS_OS_ERROR'),
            'canceled'         => (int) Configuration::get('PS_OS_CANCELED'),
            'cancelled'        => (int) Configuration::get('PS_OS_CANCELED'),
            'expired'          => (int) Configuration::get('PS_OS_CANCELED'),
        );

        return isset($map[$paymentStatus]) ? $map[$paymentStatus] : (int) Configuration::get('PS_OS_PREPARATION');
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * Check if cart currency is allowed
     *
     * @param Cart $cart
     *
     * @return bool
     */
    private function checkCurrency(Cart $cart)
    {
        $currencyOrder = new Currency($cart->id_currency);
        $currenciesModule = $this->getCurrency($cart->id_currency);

        if (empty($currenciesModule)) {
            return false;
        }

        foreach ($currenciesModule as $currencyModule) {
            if ($currencyOrder->id == $currencyModule['id_currency']) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array
     */
    private function getPaymentMethodsList()
    {
        $methods = array();

        $methods[] = array(
            'type' => 'card',
            'name' => $this->l('Credit/Debit Card'),
            'icon' => 'credit-card',
        );

        if (Configuration::get(self::CONFIG_GOOGLE_PAY)) {
            $methods[] = array(
                'type' => 'google_pay',
                'name' => 'Google Pay',
                'icon' => 'google',
            );
        }

        if (Configuration::get(self::CONFIG_APPLE_PAY)) {
            $methods[] = array(
                'type' => 'apple_pay',
                'name' => 'Apple Pay',
                'icon' => 'apple',
            );
        }

        return $methods;
    }

    /**
     * Get Paypercut locale from browser language
     *
     * @return string|null
     */
    private function getPaypercutLocale()
    {
        $acceptLanguage = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? $_SERVER['HTTP_ACCEPT_LANGUAGE'] : '';

        if ($acceptLanguage) {
            $languages = explode(',', $acceptLanguage);

            foreach ($languages as $language) {
                $langParts = explode(';', trim($language));
                $langCode = strtolower(trim($langParts[0]));
                $primaryParts = explode('-', $langCode);
                $primaryCode = $primaryParts[0];

                if (in_array($primaryCode, self::SUPPORTED_LOCALES)) {
                    return $primaryCode;
                }
            }
        }

        return null;
    }

    /**
     * Log error message
     *
     * @param string $message
     */
    public function logError($message)
    {
        if (!Configuration::get(self::CONFIG_LOGGING)) {
            return;
        }

        PrestaShopLogger::addLog(
            'Paypercut: ' . $message,
            3,
            null,
            'Paypercut',
            null,
            true
        );
    }

    /**
     * Log debug message
     *
     * @param string $message
     */
    public function logDebug($message)
    {
        if (!Configuration::get(self::CONFIG_LOGGING)) {
            return;
        }

        PrestaShopLogger::addLog(
            'Paypercut: ' . $message,
            1,
            null,
            'Paypercut',
            null,
            true
        );
    }

    // ──────────────────────────────────────────────
    // Install / Uninstall helpers
    // ──────────────────────────────────────────────

    /**
     * @return bool
     */
    private function installDatabase()
    {
        $sqlFile = dirname(__FILE__) . '/sql/install.php';

        if (!file_exists($sqlFile)) {
            return false;
        }

        return include $sqlFile;
    }

    /**
     * @return bool
     */
    private function installConfiguration()
    {
        return Configuration::updateValue(self::CONFIG_CHECKOUT_MODE, 'hosted')
            && Configuration::updateValue(self::CONFIG_ORDER_STATUS_ID, (int) Configuration::get('PS_OS_PAYMENT'))
            && Configuration::updateValue(self::CONFIG_GOOGLE_PAY, 0)
            && Configuration::updateValue(self::CONFIG_APPLE_PAY, 0)
            && Configuration::updateValue(self::CONFIG_LOGGING, 0);
    }

    /**
     * @return bool
     */
    private function uninstallConfiguration()
    {
        return Configuration::deleteByName(self::CONFIG_API_KEY)
            && Configuration::deleteByName(self::CONFIG_CHECKOUT_MODE)
            && Configuration::deleteByName(self::CONFIG_ORDER_STATUS_ID)
            && Configuration::deleteByName(self::CONFIG_STATEMENT_DESCRIPTOR)
            && Configuration::deleteByName(self::CONFIG_GOOGLE_PAY)
            && Configuration::deleteByName(self::CONFIG_APPLE_PAY)
            && Configuration::deleteByName(self::CONFIG_WEBHOOK_ID)
            && Configuration::deleteByName(self::CONFIG_WEBHOOK_SECRET)
            && Configuration::deleteByName(self::CONFIG_LOGGING)
            && Configuration::deleteByName(self::CONFIG_DOMAIN_ID);
    }

    /**
     * @return bool
     */
    private function installTab()
    {
        if (Tab::getIdFromClassName(self::MODULE_ADMIN_CONTROLLER)) {
            return true;
        }

        $tab = new Tab();
        $tab->class_name = self::MODULE_ADMIN_CONTROLLER;
        $tab->module = $this->name;
        $tab->active = true;
        $tab->id_parent = -1; // hidden tab
        $tab->name = array_fill_keys(
            Language::getIDs(false),
            $this->displayName
        );

        return (bool) $tab->add();
    }

    /**
     * @return bool
     */
    private function uninstallTab()
    {
        $idTab = (int) Tab::getIdFromClassName(self::MODULE_ADMIN_CONTROLLER);

        if ($idTab) {
            $tab = new Tab($idTab);

            return (bool) $tab->delete();
        }

        return true;
    }
}
