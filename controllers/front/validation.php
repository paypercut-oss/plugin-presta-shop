<?php

/**
 * Paypercut Validation Controller
 *
 * Handles return from Paypercut hosted checkout and embedded checkout confirmation.
 * Verifies the checkout status with the API, creates a PrestaShop order, and
 * redirects the customer to the order-confirmation page.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

require_once _PS_MODULE_DIR_ . 'paypercut/classes/PaypercutApi.php';
require_once _PS_MODULE_DIR_ . 'paypercut/classes/PaypercutTransaction.php';
require_once _PS_MODULE_DIR_ . 'paypercut/classes/PaypercutCustomer.php';

class PaypercutValidationModuleFrontController extends ModuleFrontController
{
    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        /** @var Paypercut $module */
        $module = $this->module;

        // Determine flow: embedded vs hosted
        $isEmbedded = (bool) Tools::getValue('embedded');

        // ── Resolve cart from URL params (refresh-safe) or session ──
        $cart = $this->resolveCart();

        if (
            false === Validate::isLoadedObject($cart)
            || $cart->id_customer == 0
            || $cart->id_address_delivery == 0
            || $cart->id_address_invoice == 0
        ) {
            // Last resort: if we have an id_cart param, an order may already exist
            $idCartParam = (int) Tools::getValue('id_cart');
            if ($idCartParam) {
                $existingOrderId = Order::getOrderByCartId($idCartParam);
                if ($existingOrderId) {
                    $this->redirectToConfirmation($idCartParam, $existingOrderId);

                    return;
                }
            }

            Tools::redirect('index.php?controller=order&step=1');

            return;
        }

        $customer = new Customer($cart->id_customer);

        if (!Validate::isLoadedObject($customer)) {
            Tools::redirect('index.php?controller=order&step=1');

            return;
        }

        // Validate secure key when provided via URL
        $urlKey = Tools::getValue('key');
        if ($urlKey && $urlKey !== $customer->secure_key) {
            Tools::redirect('index.php?controller=order&step=1');

            return;
        }

        // ── If order already exists (webhook or previous load), redirect immediately ──
        $existingOrderId = Order::getOrderByCartId((int) $cart->id);
        if ($existingOrderId) {
            // Ensure transaction is linked
            $transaction = PaypercutTransaction::getByCartId((int) $cart->id);
            if ($transaction && !$transaction->id_order) {
                $transaction->id_order = (int) $existingOrderId;
                $transaction->update();
            }

            $module->logDebug('Validation: order already exists for cart #' . $cart->id . ', redirecting.');
            $this->redirectToConfirmation((int) $cart->id, (int) $existingOrderId, $customer->secure_key);

            return;
        }

        try {
            $api = new PaypercutApi(Configuration::get(Paypercut::CONFIG_API_KEY));

            // Find the transaction record by cart
            $transaction = PaypercutTransaction::getByCartId((int) $cart->id);

            if (!$transaction) {
                throw new Exception('No transaction found for cart #' . $cart->id);
            }

            $checkoutId = $transaction->checkout_id;

            if (empty($checkoutId)) {
                throw new Exception('Checkout ID is empty for cart #' . $cart->id);
            }

            // Verify checkout status with API
            $checkoutData = $api->getCheckout($checkoutId);

            if (!$checkoutData || !isset($checkoutData['status'])) {
                throw new Exception('Failed to verify checkout status for: ' . $checkoutId);
            }

            $checkoutStatus = $checkoutData['status'];

            $module->logDebug('Checkout ' . $checkoutId . ' status: ' . $checkoutStatus);

            if ($checkoutStatus === 'complete') {
                $this->handleCompleteCheckout($cart, $customer, $transaction, $checkoutData, $isEmbedded);
            } elseif ($checkoutStatus === 'expired') {
                $module->logError('Checkout expired: ' . $checkoutId);
                $this->errors[] = $module->l('Your payment session has expired. Please try again.', 'validation');
                $this->redirectWithNotifications('index.php?controller=order&step=3');
            } elseif ($checkoutStatus === 'open') {
                $module->logError('Checkout still open (not completed): ' . $checkoutId);
                $this->errors[] = $module->l('The payment was not completed. Please try again.', 'validation');
                $this->redirectWithNotifications('index.php?controller=order&step=3');
            } else {
                throw new Exception('Unexpected checkout status: ' . $checkoutStatus);
            }
        } catch (Exception $e) {
            $module->logError('Validation error: ' . $e->getMessage());

            $this->errors[] = $module->l('An error occurred while verifying the payment. Please contact support.', 'validation');
            $this->redirectWithNotifications('index.php?controller=order&step=3');
        }
    }

    /**
     * Handle a completed checkout: create order, update transaction, redirect
     *
     * @param Cart                 $cart
     * @param Customer             $customer
     * @param PaypercutTransaction $transaction
     * @param array                $checkoutData
     * @param bool                 $isEmbedded
     */
    private function handleCompleteCheckout(
        Cart $cart,
        Customer $customer,
        PaypercutTransaction $transaction,
        array $checkoutData,
        $isEmbedded
    ) {
        /** @var Paypercut $module */
        $module = $this->module;

        // Extract IDs from checkout data
        $paymentId = isset($checkoutData['payment_intent'])
            ? $checkoutData['payment_intent']
            : (isset($checkoutData['id']) ? $checkoutData['id'] : '');

        $paymentIntentId = isset($checkoutData['payment_intent'])
            ? $checkoutData['payment_intent']
            : '';

        // Detect payment method
        $paymentMethod = '';
        if (isset($checkoutData['payment_method_types']) && is_array($checkoutData['payment_method_types'])) {
            $paymentMethod = $checkoutData['payment_method_types'][0];
        }

        // Extract currency
        $currency = '';
        if (isset($checkoutData['currency'])) {
            if (is_string($checkoutData['currency'])) {
                $currency = strtoupper($checkoutData['currency']);
            } elseif (is_array($checkoutData['currency']) && isset($checkoutData['currency']['iso'])) {
                $currency = strtoupper($checkoutData['currency']['iso']);
            }
        }

        // Update transaction record
        $transaction->payment_id = $paymentId;
        $transaction->payment_intent_id = $paymentIntentId;
        $transaction->payment_status = 'succeeded';
        $transaction->payment_method = $paymentMethod;
        if ($currency) {
            $transaction->currency = $currency;
        }
        if (isset($checkoutData['amount_total'])) {
            $transaction->amount = (int) $checkoutData['amount_total'];
        }
        $transaction->update();

        // Build order comment
        $modeLabel = $isEmbedded ? 'Embedded' : 'Hosted';
        $comment = 'Payment completed via Paypercut (' . $modeLabel . ' Checkout)' . PHP_EOL;
        $comment .= 'Checkout ID: ' . $transaction->checkout_id . PHP_EOL;

        if ($paymentId) {
            $comment .= 'Payment ID: ' . $paymentId . PHP_EOL;
        }

        if (isset($checkoutData['amount_total']) && $currency) {
            $comment .= 'Amount: ' . number_format($checkoutData['amount_total'] / 100, 2) . ' ' . $currency;
        }

        // Get order status
        $orderStatusId = $module->getOrderStatusForPaymentStatus('succeeded');

        // Determine display name
        $displayName = 'Paypercut';
        if ($paymentMethod) {
            $displayName = 'Paypercut (' . ucfirst(str_replace('_', ' ', $paymentMethod)) . ')';
        }

        // Retrieve currency ID for PrestaShop
        $currencyObj = new Currency($cart->id_currency);
        $currencyIso = $currencyObj->iso_code;

        // Check if order already exists for this cart
        $existingOrderId = Order::getOrderByCartId((int) $cart->id);
        if ($existingOrderId) {
            $module->logDebug('Order already exists for cart #' . $cart->id . ': Order #' . $existingOrderId);
            // Update transaction with order id
            $transaction->id_order = (int) $existingOrderId;
            $transaction->update();
            // Redirect to confirmation
            $this->redirectToConfirmation((int) $cart->id, (int) $existingOrderId, $customer->secure_key);

            return;
        }

        // Create the PrestaShop order via validateOrder()
        $totalPaid = $cart->getOrderTotal(true, Cart::BOTH);

        $module->validateOrder(
            (int) $cart->id,
            $orderStatusId,
            $totalPaid,
            $displayName,
            $comment,
            array('transaction_id' => $paymentId),
            (int) $currencyObj->id,
            false,
            $customer->secure_key
        );

        $orderId = (int) $module->currentOrder;

        // Link transaction to order
        $transaction->id_order = $orderId;
        $transaction->update();

        $module->logDebug('Order #' . $orderId . ' created for cart #' . $cart->id);

        // Redirect to order-confirmation
        $this->redirectToConfirmation((int) $cart->id, $orderId, $customer->secure_key);
    }

    /**
     * Resolve the cart to use: prefer URL param id_cart (refresh-safe), fall back to session.
     *
     * @return Cart|false
     */
    private function resolveCart()
    {
        $idCartParam = (int) Tools::getValue('id_cart');

        if ($idCartParam) {
            $cart = new Cart($idCartParam);
            if (Validate::isLoadedObject($cart)) {
                return $cart;
            }
        }

        // Fall back to session cart (works for embedded flow and legacy URLs)
        return $this->context->cart;
    }

    /**
     * Redirect to PrestaShop order-confirmation page.
     *
     * @param int         $idCart
     * @param int         $idOrder
     * @param string|null $secureKey
     */
    private function redirectToConfirmation($idCart, $idOrder, $secureKey = null)
    {
        /** @var Paypercut $module */
        $module = $this->module;

        if (!$secureKey) {
            $order = new Order((int) $idOrder);
            if (Validate::isLoadedObject($order)) {
                $customer = new Customer($order->id_customer);
                $secureKey = $customer->secure_key;
            }
        }

        Tools::redirect(
            'index.php?controller=order-confirmation&id_cart=' . (int) $idCart
                . '&id_module=' . (int) $module->id
                . '&id_order=' . (int) $idOrder
                . '&key=' . $secureKey
        );
    }
}
