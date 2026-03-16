<?php

/**
 * Paypercut Webhook Controller
 *
 * Receives and processes webhook events from the Paypercut API.
 * Implements signature verification and idempotency.
 *
 * Supported events:
 *  - payment.succeeded
 *  - payment.failed
 *  - payment.pending
 *  - payment_intent.succeeded
 *  - payment_intent.payment_failed
 *  - payment_intent.canceled
 *  - refund.created
 *  - refund.succeeded
 *  - refund.failed
 *  - checkout.completed
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

require_once _PS_MODULE_DIR_ . 'paypercut/classes/PaypercutTransaction.php';
require_once _PS_MODULE_DIR_ . 'paypercut/classes/PaypercutRefund.php';
require_once _PS_MODULE_DIR_ . 'paypercut/classes/PaypercutWebhookLog.php';

class PaypercutWebhookModuleFrontController extends ModuleFrontController
{
    /** @var bool Disable SSL check for webhooks (server-to-server) */
    public $ssl = true;

    /** @var bool */
    public $auth = false;

    /** @var bool */
    public $ajax = true;

    /**
     * @see FrontController::postProcess()
     */
    public function postProcess()
    {
        // Read raw input
        $payload = file_get_contents('php://input');
        $signature = isset($_SERVER['HTTP_X_PAYPERCUT_SIGNATURE'])
            ? $_SERVER['HTTP_X_PAYPERCUT_SIGNATURE']
            : '';

        /** @var Paypercut $module */
        $module = $this->module;

        // Verify signature
        if (!$this->verifySignature($payload, $signature)) {
            $module->logError('Webhook signature verification failed.');
            $this->respondJson(array('error' => 'Invalid signature'), 401);

            return;
        }

        $data = json_decode($payload, true);

        if (!$data || !isset($data['type'])) {
            $module->logError('Invalid webhook payload.');
            $this->respondJson(array('error' => 'Invalid payload'), 400);

            return;
        }

        $eventId = isset($data['id']) ? $data['id'] : '';
        $eventType = $data['type'];

        $module->logDebug('Webhook received: ' . $eventType . ' (event: ' . $eventId . ')');

        // Idempotency check
        if ($eventId && PaypercutWebhookLog::isProcessed($eventId)) {
            $module->logDebug('Webhook already processed: ' . $eventId);
            $this->respondJson(array('status' => 'already_processed'), 200);

            return;
        }

        // Dispatch event
        try {
            switch ($eventType) {
                case 'payment.succeeded':
                    $this->handlePaymentSucceeded($data);
                    break;

                case 'payment.failed':
                    $this->handlePaymentFailed($data);
                    break;

                case 'payment.pending':
                    $this->handlePaymentPending($data);
                    break;

                case 'payment_intent.succeeded':
                    $this->handlePaymentIntentSucceeded($data);
                    break;

                case 'payment_intent.payment_failed':
                case 'payment_intent.canceled':
                    $this->handlePaymentIntentFailedOrCanceled($data);
                    break;

                case 'refund.created':
                case 'refund.succeeded':
                    $this->handleRefundEvent($data);
                    break;

                case 'refund.failed':
                    $this->handleRefundFailed($data);
                    break;

                case 'checkout.completed':
                    $this->handleCheckoutCompleted($data);
                    break;

                default:
                    $module->logDebug('Unhandled webhook event type: ' . $eventType);
                    break;
            }

            // Log as processed
            if ($eventId) {
                PaypercutWebhookLog::logEvent($eventId, $eventType, 'processed');
            }

            $this->respondJson(array('status' => 'ok'), 200);
        } catch (Exception $e) {
            $module->logError('Webhook processing error [' . $eventType . ']: ' . $e->getMessage());

            if ($eventId) {
                PaypercutWebhookLog::logEvent($eventId, $eventType, 'failed', $e->getMessage());
            }

            $this->respondJson(array('error' => 'Processing error'), 500);
        }
    }

    // ──────────────────────────────────────────────
    // Signature verification
    // ──────────────────────────────────────────────

    /**
     * @param string $payload
     * @param string $signature
     *
     * @return bool
     */
    private function verifySignature($payload, $signature)
    {
        $secret = Configuration::get(Paypercut::CONFIG_WEBHOOK_SECRET);

        if (empty($secret)) {
            /** @var Paypercut $module */
            $module = $this->module;
            $module->logError('Warning: Webhook secret not configured, skipping signature verification.');

            return true;
        }

        if (empty($signature)) {
            return false;
        }

        $expected = hash_hmac('sha256', $payload, $secret);

        return hash_equals($expected, $signature);
    }

    // ──────────────────────────────────────────────
    // Event handlers
    // ──────────────────────────────────────────────

    /**
     * @param array $data
     */
    private function handlePaymentSucceeded(array $data)
    {
        $payment = $this->extractObject($data);
        if (!$payment) {
            return;
        }

        /** @var Paypercut $module */
        $module = $this->module;

        $orderId = $this->findOrderId($payment);
        if (!$orderId) {
            $module->logError('payment.succeeded: could not find order.');

            return;
        }

        $order = new Order((int) $orderId);
        if (!Validate::isLoadedObject($order)) {
            $module->logError('payment.succeeded: invalid order #' . $orderId);

            return;
        }

        $orderStatusId = $module->getOrderStatusForPaymentStatus('succeeded');

        // Skip if already in target status
        if ((int) $order->getCurrentState() === $orderStatusId) {
            $module->logDebug('payment.succeeded: order #' . $orderId . ' already in target status.');

            return;
        }

        $comment = 'Payment completed via Paypercut (Webhook)' . PHP_EOL;
        $comment .= 'Payment ID: ' . (isset($payment['id']) ? $payment['id'] : 'N/A') . PHP_EOL;

        if (isset($payment['formatted_amount'])) {
            $comment .= 'Amount: ' . $payment['formatted_amount'];
        }

        if (isset($payment['payment_method_details']['card'])) {
            $card = $payment['payment_method_details']['card'];
            $comment .= PHP_EOL . 'Card: ' . ucfirst(isset($card['brand']) ? $card['brand'] : '') . ' ****' . (isset($card['last4']) ? $card['last4'] : '');
        }

        $history = new OrderHistory();
        $history->id_order = (int) $orderId;
        $history->changeIdOrderState($orderStatusId, $order);
        $history->addWithemail(true, array('order_name' => sprintf('#%06d', $orderId)));

        // Update transaction
        PaypercutTransaction::updateStatusByPaymentId(
            isset($payment['id']) ? $payment['id'] : '',
            'succeeded'
        );

        $module->logDebug('payment.succeeded processed for order #' . $orderId);
    }

    /**
     * @param array $data
     */
    private function handlePaymentFailed(array $data)
    {
        $payment = $this->extractObject($data);
        if (!$payment) {
            return;
        }

        /** @var Paypercut $module */
        $module = $this->module;

        $orderId = $this->findOrderId($payment);
        if (!$orderId) {
            return;
        }

        $order = new Order((int) $orderId);
        if (!Validate::isLoadedObject($order)) {
            return;
        }

        $orderStatusId = $module->getOrderStatusForPaymentStatus('failed');

        $comment = 'Payment failed via Paypercut (Webhook)' . PHP_EOL;
        $comment .= 'Payment ID: ' . (isset($payment['id']) ? $payment['id'] : 'N/A') . PHP_EOL;
        $comment .= 'Reason: ' . (isset($payment['failure_message']) ? $payment['failure_message'] : 'Unknown');

        $history = new OrderHistory();
        $history->id_order = (int) $orderId;
        $history->changeIdOrderState($orderStatusId, $order);
        $history->addWithemail(false);

        PaypercutTransaction::updateStatusByPaymentId(
            isset($payment['id']) ? $payment['id'] : '',
            'failed'
        );

        $module->logDebug('payment.failed processed for order #' . $orderId);
    }

    /**
     * @param array $data
     */
    private function handlePaymentPending(array $data)
    {
        $payment = $this->extractObject($data);
        if (!$payment) {
            return;
        }

        /** @var Paypercut $module */
        $module = $this->module;

        $orderId = $this->findOrderId($payment);
        if (!$orderId) {
            return;
        }

        $order = new Order((int) $orderId);
        if (!Validate::isLoadedObject($order)) {
            return;
        }

        $orderStatusId = $module->getOrderStatusForPaymentStatus('pending');

        $comment = 'Payment pending (Webhook)' . PHP_EOL;
        $comment .= 'Payment ID: ' . (isset($payment['id']) ? $payment['id'] : 'N/A');

        $history = new OrderHistory();
        $history->id_order = (int) $orderId;
        $history->changeIdOrderState($orderStatusId, $order);
        $history->addWithemail(false);

        PaypercutTransaction::updateStatusByPaymentId(
            isset($payment['id']) ? $payment['id'] : '',
            'pending'
        );

        $module->logDebug('payment.pending processed for order #' . $orderId);
    }

    /**
     * @param array $data
     */
    private function handlePaymentIntentSucceeded(array $data)
    {
        $intent = $this->extractObject($data);
        if (!$intent) {
            return;
        }

        /** @var Paypercut $module */
        $module = $this->module;

        // Find transaction by payment_intent_id
        $intentId = isset($intent['id']) ? $intent['id'] : '';
        $transaction = PaypercutTransaction::getByPaymentIntentId($intentId);

        if (!$transaction || !$transaction->id_order) {
            $module->logDebug('payment_intent.succeeded: no matching transaction for ' . $intentId);

            return;
        }

        $order = new Order((int) $transaction->id_order);
        if (!Validate::isLoadedObject($order)) {
            return;
        }

        $orderStatusId = $module->getOrderStatusForPaymentStatus('succeeded');

        if ((int) $order->getCurrentState() === $orderStatusId) {
            return;
        }

        $comment = 'Payment intent succeeded (Webhook)' . PHP_EOL . 'Intent ID: ' . $intentId;

        $history = new OrderHistory();
        $history->id_order = (int) $transaction->id_order;
        $history->changeIdOrderState($orderStatusId, $order);
        $history->addWithemail(true);

        $transaction->payment_status = 'succeeded';
        $transaction->update();

        $module->logDebug('payment_intent.succeeded processed for order #' . $transaction->id_order);
    }

    /**
     * @param array $data
     */
    private function handlePaymentIntentFailedOrCanceled(array $data)
    {
        $intent = $this->extractObject($data);
        if (!$intent) {
            return;
        }

        /** @var Paypercut $module */
        $module = $this->module;

        $intentId = isset($intent['id']) ? $intent['id'] : '';
        $transaction = PaypercutTransaction::getByPaymentIntentId($intentId);

        if (!$transaction || !$transaction->id_order) {
            return;
        }

        $order = new Order((int) $transaction->id_order);
        if (!Validate::isLoadedObject($order)) {
            return;
        }

        $status = (strpos($data['type'], 'canceled') !== false) ? 'canceled' : 'failed';
        $orderStatusId = $module->getOrderStatusForPaymentStatus($status);

        $comment = ucfirst($status) . ' via Paypercut (Webhook)' . PHP_EOL . 'Intent ID: ' . $intentId;

        $history = new OrderHistory();
        $history->id_order = (int) $transaction->id_order;
        $history->changeIdOrderState($orderStatusId, $order);
        $history->addWithemail(false);

        $transaction->payment_status = $status;
        $transaction->update();

        $module->logDebug('payment_intent.' . $status . ' processed for order #' . $transaction->id_order);
    }

    /**
     * @param array $data
     */
    private function handleRefundEvent(array $data)
    {
        $refund = $this->extractObject($data);
        if (!$refund) {
            return;
        }

        /** @var Paypercut $module */
        $module = $this->module;

        $paymentId = isset($refund['payment']) ? $refund['payment'] : '';
        $refundId = isset($refund['id']) ? $refund['id'] : '';

        if (empty($paymentId)) {
            $module->logError('refund event: missing payment ID');

            return;
        }

        // Find order via payment transaction
        $transaction = PaypercutTransaction::getByPaymentId($paymentId);
        if (!$transaction) {
            $transaction = PaypercutTransaction::getByPaymentIntentId($paymentId);
        }

        if (!$transaction || !$transaction->id_order) {
            $module->logDebug('refund event: no matching order for payment ' . $paymentId);

            return;
        }

        $orderId = (int) $transaction->id_order;

        // Check if refund already tracked
        $existingRefund = PaypercutRefund::getByRefundId($refundId);
        if ($existingRefund) {
            // Update status
            $existingRefund->status = ($data['type'] === 'refund.succeeded') ? 'succeeded' : 'pending';
            $existingRefund->update();

            return;
        }

        // Create new refund record
        $refundAmount = isset($refund['amount']) ? (float) $refund['amount'] / 100 : 0;

        $refundObj = new PaypercutRefund();
        $refundObj->id_order = $orderId;
        $refundObj->payment_id = $paymentId;
        $refundObj->refund_id = $refundId;
        $refundObj->amount = $refundAmount;
        $refundObj->status = ($data['type'] === 'refund.succeeded') ? 'succeeded' : 'pending';
        $refundObj->reason = isset($refund['reason']) ? $refund['reason'] : '';
        $refundObj->id_shop = (int) $this->context->shop->id;
        $refundObj->add();

        // Add order note
        $order = new Order($orderId);
        if (Validate::isLoadedObject($order)) {
            $currency = new Currency($order->id_currency);
            $comment = 'Refund ' . ($data['type'] === 'refund.succeeded' ? 'succeeded' : 'created') . ' (Webhook)' . PHP_EOL;
            $comment .= 'Refund ID: ' . $refundId . PHP_EOL;
            $comment .= 'Amount: ' . number_format($refundAmount, 2) . ' ' . $currency->iso_code;

            // Check if fully refunded
            $totalRefunded = PaypercutRefund::getTotalRefunded($orderId);
            $orderTotal = $transaction->amount / 100;

            if ($totalRefunded >= $orderTotal) {
                // Mark as refunded
                $refundStatusId = (int) Configuration::get('PS_OS_REFUND');
                if (!$refundStatusId) {
                    $refundStatusId = 7; // fallback
                }

                $history = new OrderHistory();
                $history->id_order = $orderId;
                $history->changeIdOrderState($refundStatusId, $order);
                $history->addWithemail(true);
            }
        }

        $module->logDebug('refund event processed for order #' . $orderId . ', refund: ' . $refundId);
    }

    /**
     * @param array $data
     */
    private function handleRefundFailed(array $data)
    {
        $refund = $this->extractObject($data);
        if (!$refund) {
            return;
        }

        $refundId = isset($refund['id']) ? $refund['id'] : '';

        if ($refundId) {
            PaypercutRefund::updateStatusByRefundId($refundId, 'failed');
        }

        /** @var Paypercut $module */
        $module = $this->module;
        $module->logDebug('refund.failed processed: ' . $refundId);
    }

    /**
     * @param array $data
     */
    private function handleCheckoutCompleted(array $data)
    {
        $checkout = $this->extractObject($data);
        if (!$checkout) {
            return;
        }

        /** @var Paypercut $module */
        $module = $this->module;

        $checkoutId = isset($checkout['id']) ? $checkout['id'] : '';
        $clientRef = isset($checkout['client_reference_id']) ? $checkout['client_reference_id'] : '';

        $module->logDebug('checkout.completed: ' . $checkoutId . ', ref: ' . $clientRef);

        // In most cases the validation controller already created the order.
        // This handler is a safety net for race conditions.

        if (!$clientRef) {
            return;
        }

        // client_reference_id is the cart id
        $cartId = (int) $clientRef;
        $existingOrderId = Order::getOrderByCartId($cartId);

        if ($existingOrderId) {
            $module->logDebug('checkout.completed: order already exists for cart #' . $cartId);

            return;
        }

        // Order doesn't exist yet – create it from the webhook
        $cart = new Cart($cartId);
        if (!Validate::isLoadedObject($cart)) {
            $module->logError('checkout.completed: invalid cart #' . $cartId);

            return;
        }

        $customer = new Customer($cart->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            return;
        }

        // Update or create transaction
        $transaction = PaypercutTransaction::getByCartId($cartId);
        if (!$transaction) {
            $transaction = PaypercutTransaction::getByCheckoutId($checkoutId);
        }

        if (!$transaction) {
            $transaction = new PaypercutTransaction();
            $transaction->id_cart = $cartId;
            $transaction->checkout_id = $checkoutId;
            $transaction->id_shop = (int) Context::getContext()->shop->id;
        }

        $paymentId = isset($checkout['payment_intent'])
            ? $checkout['payment_intent']
            : (isset($checkout['id']) ? $checkout['id'] : '');

        $transaction->payment_id = $paymentId;
        $transaction->payment_intent_id = isset($checkout['payment_intent']) ? $checkout['payment_intent'] : '';
        $transaction->payment_status = 'succeeded';

        $currency = '';
        if (isset($checkout['currency'])) {
            if (is_string($checkout['currency'])) {
                $currency = strtoupper($checkout['currency']);
            } elseif (is_array($checkout['currency']) && isset($checkout['currency']['iso'])) {
                $currency = strtoupper($checkout['currency']['iso']);
            }
        }
        $transaction->currency = $currency;

        if (isset($checkout['amount_total'])) {
            $transaction->amount = (int) $checkout['amount_total'];
        }

        if ($transaction->id) {
            $transaction->update();
        } else {
            $transaction->add();
        }

        // Create order
        $orderStatusId = $module->getOrderStatusForPaymentStatus('succeeded');
        $totalPaid = $cart->getOrderTotal(true, Cart::BOTH);
        $currencyObj = new Currency($cart->id_currency);

        $comment = 'Payment completed via Paypercut (Webhook fallback)' . PHP_EOL;
        $comment .= 'Checkout ID: ' . $checkoutId;

        $module->validateOrder(
            (int) $cart->id,
            $orderStatusId,
            $totalPaid,
            'Paypercut',
            $comment,
            array('transaction_id' => $paymentId),
            (int) $currencyObj->id,
            false,
            $customer->secure_key
        );

        $orderId = (int) $module->currentOrder;
        $transaction->id_order = $orderId;
        $transaction->update();

        $module->logDebug('checkout.completed: created order #' . $orderId . ' for cart #' . $cartId);
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * Extract data.object from webhook payload
     *
     * @param array $data
     *
     * @return array|null
     */
    private function extractObject(array $data)
    {
        return isset($data['data']['object']) ? $data['data']['object'] : null;
    }

    /**
     * Find the PrestaShop order ID from a payment object
     *
     * @param array $payment
     *
     * @return int|false
     */
    private function findOrderId(array $payment)
    {
        // Strategy 1: client_reference_id is the cart id
        $clientRef = isset($payment['client_reference_id']) ? $payment['client_reference_id'] : '';

        if ($clientRef) {
            $orderId = Order::getOrderByCartId((int) $clientRef);
            if ($orderId) {
                return (int) $orderId;
            }
        }

        // Strategy 2: look up by payment_id in our transaction table
        $paymentId = isset($payment['id']) ? $payment['id'] : '';
        if ($paymentId) {
            $transaction = PaypercutTransaction::getByPaymentId($paymentId);
            if ($transaction && $transaction->id_order) {
                return (int) $transaction->id_order;
            }
        }

        return false;
    }

    /**
     * Send JSON response and exit
     *
     * @param array $data
     * @param int   $httpCode
     */
    private function respondJson(array $data, $httpCode = 200)
    {
        http_response_code($httpCode);
        header('Content-Type: application/json');
        echo json_encode($data);
        exit;
    }
}
