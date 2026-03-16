<?php

/**
 * Paypercut Checkout Session Controller (AJAX)
 *
 * Creates a checkout session for embedded mode via AJAX.
 * Called lazily when the customer selects "Pay with Paypercut" on the payment step.
 *
 * Compatible with PrestaShop 8.x and 9.x.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

require_once _PS_MODULE_DIR_ . 'paypercut/classes/PaypercutApi.php';
require_once _PS_MODULE_DIR_ . 'paypercut/classes/PaypercutCustomer.php';
require_once _PS_MODULE_DIR_ . 'paypercut/classes/PaypercutTransaction.php';

class PaypercutCheckoutModuleFrontController extends ModuleFrontController
{
    /**
     * Write to both PrestaShop logger (if enabled) and PHP error_log (always).
     * @param string $msg
     * @param bool $isError
     */
    private function debugLog($msg, $isError = false)
    {
        error_log('[Paypercut Checkout] ' . $msg);
        /** @var Paypercut $module */
        $module = $this->module;
        if ($isError) {
            $module->logError($msg);
        } else {
            $module->logDebug($msg);
        }
    }

    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        $this->debugLog('postProcess() called. REQUEST_METHOD=' . $_SERVER['REQUEST_METHOD']);

        // Only accept AJAX requests
        if (!$this->isXmlHttpRequest()) {
            $this->debugLog('Rejected: not an XHR request. HTTP_X_REQUESTED_WITH='
                . (isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? $_SERVER['HTTP_X_REQUESTED_WITH'] : '(not set)'), true);
            header('HTTP/1.1 403 Forbidden');
            die(json_encode(array('error' => 'Forbidden')));
        }

        header('Content-Type: application/json; charset=utf-8');

        /** @var Cart $cart */
        $cart = $this->context->cart;

        if (
            false === Validate::isLoadedObject($cart)
            || $cart->id_customer == 0
            || $cart->id_address_delivery == 0
            || $cart->id_address_invoice == 0
        ) {
            $this->debugLog('Invalid cart state. id=' . ($cart ? $cart->id : 'null')
                . ', customer=' . ($cart ? $cart->id_customer : 'null')
                . ', delivery=' . ($cart ? $cart->id_address_delivery : 'null')
                . ', invoice=' . ($cart ? $cart->id_address_invoice : 'null'), true);
            die(json_encode(array('error' => 'Invalid cart. Please refresh the page.')));
        }

        $this->debugLog('Cart #' . $cart->id . ' validated (customer=' . $cart->id_customer . ').');

        // Verify module is active
        $authorized = false;
        foreach (Module::getPaymentModules() as $mod) {
            if ($mod['name'] === $this->module->name) {
                $authorized = true;
                break;
            }
        }

        if (!$authorized) {
            $this->debugLog('Module not in active payment modules list.', true);
            die(json_encode(array('error' => 'This payment method is not available.')));
        }

        // Validate CSRF token (use 'paypercut_token' to avoid PS reserved 'token' param)
        $token = Tools::getValue('paypercut_token');
        $expectedToken = Tools::getToken(false);
        if (empty($token) || $token !== $expectedToken) {
            $this->debugLog('CSRF mismatch. received=' . ($token ? substr($token, 0, 8) . '...' : '(empty)')
                . ' expected=' . ($expectedToken ? substr($expectedToken, 0, 8) . '...' : '(empty)'), true);
            die(json_encode(array('error' => 'Invalid security token. Please refresh the page.')));
        }

        $this->debugLog('CSRF token validated.');

        $customer = new Customer($cart->id_customer);

        if (!Validate::isLoadedObject($customer)) {
            $this->debugLog('Invalid customer id=' . $cart->id_customer, true);
            die(json_encode(array('error' => 'Invalid customer.')));
        }

        try {
            $api = new PaypercutApi(Configuration::get(Paypercut::CONFIG_API_KEY));
            $this->debugLog('API key present: ' . (Configuration::get(Paypercut::CONFIG_API_KEY) ? 'yes (' . strlen(Configuration::get(Paypercut::CONFIG_API_KEY)) . ' chars)' : 'NO'));

            // Handle customer mapping (optional, non-blocking)
            $this->handleCustomerMapping($api, $customer);

            // Build and create checkout session
            /** @var Paypercut $module */
            $module = $this->module;
            $checkoutData = $module->buildCheckoutPayload($cart, 'embedded');
            $this->debugLog('Calling createCheckout. Payload keys: ' . implode(', ', array_keys($checkoutData))
                . '. amount=' . (isset($checkoutData['amount']) ? $checkoutData['amount'] : '?')
                . ', currency=' . (isset($checkoutData['currency']) ? $checkoutData['currency'] : '?'));
            $result = $api->createCheckout($checkoutData);
            $this->debugLog('API response: ' . (is_array($result) ? json_encode(array_keys($result)) : 'NOT_ARRAY: ' . var_export($result, true)));

            if (!isset($result['id'])) {
                $this->debugLog('API response missing "id". Full response: ' . json_encode($result), true);
                throw new Exception('Invalid checkout response from Paypercut API.');
            }

            // Store transaction pre-record
            // Check if a pending transaction already exists for this cart to avoid duplicates
            $existingTransaction = PaypercutTransaction::getByCartId((int) $cart->id);
            if ($existingTransaction && $existingTransaction->payment_status === 'pending') {
                // Update existing pending transaction with new checkout ID
                $existingTransaction->checkout_id = $result['id'];
                $existingTransaction->amount = (int) round($cart->getOrderTotal(true, Cart::BOTH) * 100);
                $existingTransaction->currency = strtoupper((new Currency($cart->id_currency))->iso_code);
                $existingTransaction->update();
            } else {
                $transaction = new PaypercutTransaction();
                $transaction->id_cart = (int) $cart->id;
                $transaction->checkout_id = $result['id'];
                $transaction->payment_status = 'pending';
                $transaction->amount = (int) round($cart->getOrderTotal(true, Cart::BOTH) * 100);
                $transaction->currency = strtoupper((new Currency($cart->id_currency))->iso_code);
                $transaction->id_shop = (int) $this->context->shop->id;
                $transaction->add();
            }

            $this->debugLog('Checkout session created: id=' . $result['id'] . ' for cart #' . $cart->id);

            // Build wallet options for the SDK
            $walletOptions = array();
            if (Configuration::get(Paypercut::CONFIG_APPLE_PAY)) {
                $walletOptions[] = 'apple_pay';
            }
            if (Configuration::get(Paypercut::CONFIG_GOOGLE_PAY)) {
                $walletOptions[] = 'google_pay';
            }

            $confirmUrl = $this->context->link->getModuleLink(
                $this->module->name,
                'validation',
                array(
                    'embedded' => '1',
                    'id_cart' => (int) $cart->id,
                    'key' => $customer->secure_key,
                ),
                true
            );

            $this->debugLog('Responding with checkout_id=' . $result['id'] . ', confirm_url=' . $confirmUrl . ', wallets=' . json_encode($walletOptions));

            die(json_encode(array(
                'checkout_id' => $result['id'],
                'confirm_url' => $confirmUrl,
                'wallet_options' => $walletOptions,
                'error' => null,
            )));
        } catch (Exception $e) {
            $this->debugLog('Exception: ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine(), true);

            die(json_encode(array('error' => 'Failed to initialize payment. Please try again.')));
        }
    }

    /**
     * Determine if the current request is AJAX.
     *
     * Compatible with PrestaShop 8.x and 9.x.
     *
     * @return bool
     */
    public function isXmlHttpRequest()
    {
        return !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
            && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    }

    /**
     * Try to link PrestaShop customer to Paypercut customer.
     *
     * @param PaypercutApi $api
     * @param Customer     $customer
     */
    private function handleCustomerMapping(PaypercutApi $api, Customer $customer)
    {
        if (!$customer->id) {
            return;
        }

        $idShop = (int) $this->context->shop->id;

        try {
            $existingId = PaypercutCustomer::getPaypercutId($customer->id, $idShop);

            if ($existingId) {
                try {
                    $apiCustomer = $api->getCustomer($existingId);
                    if (!$apiCustomer || !isset($apiCustomer['id'])) {
                        $mapping = PaypercutCustomer::getByCustomerAndShop($customer->id, $idShop);
                        if ($mapping) {
                            $mapping->delete();
                        }
                        $existingId = false;
                    }
                } catch (Exception $e) {
                    $mapping = PaypercutCustomer::getByCustomerAndShop($customer->id, $idShop);
                    if ($mapping) {
                        $mapping->delete();
                    }
                    $existingId = false;
                }
            }

            if (!$existingId) {
                $result = $api->createCustomer(array(
                    'email' => $customer->email,
                    'name' => $customer->firstname . ' ' . $customer->lastname,
                ));

                if (isset($result['id'])) {
                    PaypercutCustomer::getOrCreate($customer->id, $result['id'], $idShop);
                }
            }
        } catch (Exception $e) {
            /** @var Paypercut $module */
            $module = $this->module;
            $module->logDebug('Customer mapping skipped: ' . $e->getMessage());
        }
    }
}
