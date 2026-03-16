<?php

/**
 * Paypercut Redirect Controller
 *
 * Creates a checkout session and redirects the customer to Paypercut's hosted page.
 * Used in "hosted" checkout mode.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

require_once _PS_MODULE_DIR_ . 'paypercut/classes/PaypercutApi.php';
require_once _PS_MODULE_DIR_ . 'paypercut/classes/PaypercutCustomer.php';
require_once _PS_MODULE_DIR_ . 'paypercut/classes/PaypercutTransaction.php';

class PaypercutRedirectModuleFrontController extends ModuleFrontController
{
    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        /** @var Cart $cart */
        $cart = $this->context->cart;

        if (
            false === Validate::isLoadedObject($cart)
            || $cart->id_customer == 0
            || $cart->id_address_delivery == 0
            || $cart->id_address_invoice == 0
        ) {
            Tools::redirect('index.php?controller=order&step=1');

            return;
        }

        // Verify module is active
        $authorized = false;
        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] === $this->module->name) {
                $authorized = true;
                break;
            }
        }

        if (!$authorized) {
            $this->errors[] = $this->module->l('This payment method is not available.', 'redirect');
            $this->redirectWithNotifications('index.php?controller=order&step=3');

            return;
        }

        $customer = new Customer($cart->id_customer);

        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');

            return;
        }

        try {
            $api = new PaypercutApi(Configuration::get(Paypercut::CONFIG_API_KEY));

            // Get or create Paypercut customer
            $this->handleCustomerMapping($api, $customer);

            // Build checkout payload
            /** @var Paypercut $module */
            $module = $this->module;
            $payload = $module->buildCheckoutPayload($cart, 'hosted');

            // Create checkout session
            $result = $api->createCheckout($payload);

            if (!isset($result['url']) || !isset($result['id'])) {
                throw new Exception('Invalid checkout response from Paypercut API.');
            }

            // Store transaction pre-record
            $transaction = new PaypercutTransaction();
            $transaction->id_cart = (int) $cart->id;
            $transaction->checkout_id = $result['id'];
            $transaction->payment_status = 'pending';
            $transaction->amount = (int) round($cart->getOrderTotal(true, Cart::BOTH) * 100);
            $transaction->currency = strtoupper((new Currency($cart->id_currency))->iso_code);
            $transaction->id_shop = (int) $this->context->shop->id;
            $transaction->add();

            $module->logDebug('Hosted checkout created: ' . $result['id'] . ' for cart #' . $cart->id);

            // Redirect to Paypercut hosted page
            Tools::redirect($result['url']);
        } catch (Exception $e) {
            /** @var Paypercut $module */
            $module = $this->module;
            $module->logError('Redirect controller error: ' . $e->getMessage());

            $this->errors[] = $this->module->l('An error occurred during payment initialization. Please try again.', 'redirect');
            $this->redirectWithNotifications('index.php?controller=order&step=3');
        }
    }

    /**
     * Try to link PrestaShop customer to Paypercut customer
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
            // Check for existing mapping
            $existingId = PaypercutCustomer::getPaypercutId($customer->id, $idShop);

            if ($existingId) {
                // Verify still exists on API
                try {
                    $apiCustomer = $api->getCustomer($existingId);
                    if (!$apiCustomer || !isset($apiCustomer['id'])) {
                        // No longer exists – remove stale mapping
                        $mapping = PaypercutCustomer::getByCustomerAndShop($customer->id, $idShop);
                        if ($mapping) {
                            $mapping->delete();
                        }
                        $existingId = false;
                    }
                } catch (Exception $e) {
                    // Assume customer doesn't exist if API call fails
                    $mapping = PaypercutCustomer::getByCustomerAndShop($customer->id, $idShop);
                    if ($mapping) {
                        $mapping->delete();
                    }
                    $existingId = false;
                }
            }

            if (!$existingId) {
                // Create customer on Paypercut
                $result = $api->createCustomer(array(
                    'email' => $customer->email,
                    'name' => $customer->firstname . ' ' . $customer->lastname,
                ));

                if (isset($result['id'])) {
                    PaypercutCustomer::getOrCreate($customer->id, $result['id'], $idShop);
                }
            }
        } catch (Exception $e) {
            // Customer mapping is optional – log and continue
            /** @var Paypercut $module */
            $module = $this->module;
            $module->logDebug('Customer mapping skipped: ' . $e->getMessage());
        }
    }
}
