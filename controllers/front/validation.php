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
require_once _PS_MODULE_DIR_ . 'paypercut/classes/telemetry/bootstrap.php';

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

            $existingOrder = new Order((int) $existingOrderId);

            PaypercutTelemetryRecorder::record(
                PaypercutTelemetryEvent::of('checkout.return.duplicate', array(
                    'order_status' => (int) $existingOrder->getCurrentState(),
                    'source' => 'return',
                ))->about(array(
                    'payment_id' => $transaction ? (string) $transaction->payment_id : '',
                    'order_ref' => Paypercut::orderRef($existingOrder),
                ))
            );

            $this->redirectToConfirmation((int) $cart->id, (int) $existingOrderId, $customer->secure_key);

            return;
        }

        try {
            $api = new PaypercutApi(Configuration::get(Paypercut::CONFIG_API_KEY));

            // Find the transaction record by cart
            $transaction = PaypercutTransaction::getByCartId((int) $cart->id);

            if (!$transaction) {
                $this->reportUnverifiable('no_session_meta', $cart);

                throw new Exception('No transaction found for cart #' . $cart->id);
            }

            $checkoutId = $transaction->checkout_id;

            if (empty($checkoutId)) {
                $this->reportUnverifiable('no_session_meta', $cart);

                throw new Exception('Checkout ID is empty for cart #' . $cart->id);
            }

            // Verify checkout status with API
            $checkoutData = $api->getCheckout($checkoutId);

            if (!$checkoutData || !isset($checkoutData['status'])) {
                $this->reportUnverifiable('no_payment_status', $cart, $checkoutId);

                throw new Exception('Failed to verify checkout status for: ' . $checkoutId);
            }

            $checkoutStatus = $checkoutData['status'];

            $module->logDebug('Checkout ' . $checkoutId . ' status: ' . $checkoutStatus);

            if ($checkoutStatus === 'complete') {
                $this->handleCompleteCheckout($cart, $customer, $transaction, $checkoutData, $isEmbedded);
            } elseif ($checkoutStatus === 'expired') {
                $module->logError('Checkout expired: ' . $checkoutId);

                // An expired session is unambiguously a failure. A session that
                // is merely still open is not: the shopper may simply have come
                // back before the payment settled.
                PaypercutTelemetryRecorder::record(
                    PaypercutTelemetryEvent::failure('payment.failed', 'expired', array(
                        'session_status' => $checkoutStatus,
                        'order_updated' => false,
                    ))->about(array(
                        'payment_id' => (string) $checkoutId,
                        'order_ref' => Paypercut::cartRef($cart),
                    ))
                );

                $this->errors[] = $module->l('Your payment session has expired. Please try again.', 'validation');
                $this->redirectWithNotifications('index.php?controller=order&step=3');
            } elseif ($checkoutStatus === 'open') {
                $module->logError('Checkout still open (not completed): ' . $checkoutId);

                PaypercutTelemetryRecorder::record(
                    PaypercutTelemetryEvent::of('checkout.return.pending', array(
                        'session_status' => $checkoutStatus,
                        'order_status' => 'none',
                    ))->about(array(
                        'payment_id' => (string) $checkoutId,
                        'order_ref' => Paypercut::cartRef($cart),
                    ))
                );

                $this->errors[] = $module->l('The payment was not completed. Please try again.', 'validation');
                $this->redirectWithNotifications('index.php?controller=order&step=3');
            } else {
                PaypercutTelemetryRecorder::record(
                    PaypercutTelemetryEvent::failure('order.status_unhandled', 'unknown_payment_status', array(
                        'source' => 'return',
                        'payment_status' => PaypercutTelemetryEvent::identifier((string) $checkoutStatus),
                        'order_status' => 'none',
                    ))->about(array('order_ref' => Paypercut::cartRef($cart)))
                );

                throw new Exception('Unexpected checkout status: ' . $checkoutStatus);
            }
        } catch (PaypercutApiException $e) {
            $module->logError('Validation error: ' . $e->getMessage());

            PaypercutTelemetryRecorder::record(
                PaypercutTelemetryEvent::apiFailure('checkout.return.unverifiable', $e, array('order_status' => 'none'))
                    ->about(array('order_ref' => Paypercut::cartRef($cart)))
            );

            $this->errors[] = $module->l('An error occurred while verifying the payment. Please contact support.', 'validation');
            $this->redirectWithNotifications('index.php?controller=order&step=3');
        } catch (Exception $e) {
            $module->logError('Validation error: ' . $e->getMessage());

            PaypercutTelemetryRecorder::record(
                PaypercutTelemetryEvent::failure('checkout.return.unverifiable', 'lookup_failed', array('order_status' => 'none'))
                    ->because('verification threw ' . PaypercutTelemetryEvent::shortClassName($e))
                    ->about(array('order_ref' => Paypercut::cartRef($cart)))
            );

            $this->errors[] = $module->l('An error occurred while verifying the payment. Please contact support.', 'validation');
            $this->redirectWithNotifications('index.php?controller=order&step=3');
        }
    }

    /**
     * The return could not be checked, so the shopper is left without an order
     * and nothing else in the module will say why.
     *
     * @param string $code
     * @param Cart   $cart
     * @param string $checkoutId
     */
    private function reportUnverifiable($code, Cart $cart, $checkoutId = '')
    {
        PaypercutTelemetryRecorder::record(
            PaypercutTelemetryEvent::failure('checkout.return.unverifiable', $code, array('order_status' => 'none'))
                ->about(array(
                    'payment_id' => (string) $checkoutId,
                    'order_ref' => Paypercut::cartRef($cart),
                ))
        );
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

            PaypercutTelemetryRecorder::record(
                PaypercutTelemetryEvent::of('order.confirmation_skipped', array(
                    'reason' => 'already_confirmed',
                    'source' => 'return',
                    'after_lock' => false,
                ))->about(array(
                    'payment_id' => (string) $paymentId,
                    'order_ref' => Paypercut::orderRef(new Order((int) $existingOrderId)),
                ))
            );
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

        $order = new Order($orderId);
        $name = $isEmbedded ? 'checkout.embedded.order_created' : 'checkout.hosted.order_created';

        PaypercutTelemetryRecorder::record(
            PaypercutTelemetryEvent::of($name, array(
                'order_status' => $orderStatusId,
                'session_matched' => true,
                'verified_status' => 'complete',
            ))->about(array(
                'payment_id' => (string) $paymentId,
                'payment_intent_id' => (string) $paymentIntentId,
                'order_ref' => Paypercut::orderRef($order),
            ))
        );

        PaypercutTelemetryRecorder::record(
            PaypercutTelemetryEvent::of('payment.succeeded', array(
                'session_status' => 'complete',
                'order_status' => $orderStatusId,
                'order_updated' => true,
            ))->about(array(
                'payment_id' => (string) $paymentId,
                'payment_intent_id' => (string) $paymentIntentId,
                'order_ref' => Paypercut::orderRef($order),
            ))
        );

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
