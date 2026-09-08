<?php

/**
 * Paypercut API Client
 *
 * cURL wrapper for all Paypercut REST API interactions.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/telemetry/bootstrap.php';

class PaypercutApi
{
    const TIMEOUT = 30;
    const CONNECT_TIMEOUT = 10;

    /** @var string */
    private $apiKey;

    /** @var string Trailing-slashed base URI for this store's environment */
    private $baseUrl;

    /**
     * @param string      $apiKey
     * @param string|null $environment  Overrides this store's stored environment
     */
    public function __construct($apiKey, $environment = null)
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = PaypercutEnvironment::apiBaseUri(
            $environment === null ? PaypercutEnvironment::current() : $environment
        );
    }

    // ──────────────────────────────────────────────
    // Checkout Sessions
    // ──────────────────────────────────────────────

    /**
     * Create a checkout session
     *
     * @param array $data
     *
     * @return array
     */
    public function createCheckout(array $data)
    {
        return $this->post('/v1/checkouts', $data, 'checkout_create');
    }

    /**
     * Retrieve a checkout session
     *
     * @param string $checkoutId
     *
     * @return array|null
     */
    public function getCheckout($checkoutId)
    {
        return $this->get('/v1/checkouts/' . $checkoutId, 'checkout_lookup');
    }

    // ──────────────────────────────────────────────
    // Payments
    // ──────────────────────────────────────────────

    /**
     * Retrieve a payment
     *
     * @param string $paymentId
     *
     * @return array|null
     */
    public function getPayment($paymentId)
    {
        return $this->get('/v1/payments/' . $paymentId, 'payment_lookup');
    }

    // ──────────────────────────────────────────────
    // Payment Intents
    // ──────────────────────────────────────────────

    /**
     * Confirm (capture) a payment intent
     *
     * @param string $paymentIntentId
     *
     * @return array|null
     */
    public function capturePaymentIntent($paymentIntentId)
    {
        return $this->post('/v1/payment_intents/' . $paymentIntentId . '/confirm', array(), 'payment_intent_confirm');
    }

    /**
     * Cancel a payment intent
     *
     * @param string $paymentIntentId
     *
     * @return array|null
     */
    public function cancelPaymentIntent($paymentIntentId)
    {
        return $this->post('/v1/payment_intents/' . $paymentIntentId . '/cancel', array(), 'payment_intent_cancel');
    }

    // ──────────────────────────────────────────────
    // Refunds
    // ──────────────────────────────────────────────

    /**
     * Create a refund
     *
     * @param array $data  Must include: payment_id, amount; optional: reason
     *
     * @return array
     */
    public function createRefund(array $data)
    {
        return $this->post('/v1/refunds', $data, 'refund_create');
    }

    /**
     * Retrieve a refund
     *
     * @param string $refundId
     *
     * @return array|null
     */
    public function getRefund($refundId)
    {
        return $this->get('/v1/refunds/' . $refundId, 'refund_lookup');
    }

    // ──────────────────────────────────────────────
    // Customers
    // ──────────────────────────────────────────────

    /**
     * Create a customer
     *
     * @param array $data  email, name
     *
     * @return array
     */
    public function createCustomer(array $data)
    {
        return $this->post('/v1/customers', $data, 'customer_create');
    }

    /**
     * Retrieve a customer
     *
     * @param string $customerId
     *
     * @return array|null
     */
    public function getCustomer($customerId)
    {
        return $this->get('/v1/customers/' . $customerId, 'customer_lookup');
    }

    /**
     * Update a customer
     *
     * @param string $customerId
     * @param array  $data
     *
     * @return array|null
     */
    public function updateCustomer($customerId, array $data)
    {
        return $this->patch('/v1/customers/' . $customerId, $data, 'customer_update');
    }

    // ──────────────────────────────────────────────
    // Webhooks
    // ──────────────────────────────────────────────

    /**
     * List webhooks
     *
     * @return array|null
     */
    public function listWebhooks()
    {
        return $this->get('/v1/webhooks', 'webhook_list');
    }

    /**
     * Create a webhook
     *
     * @param array $data  name, url, enabled_events
     *
     * @return array
     */
    public function createWebhook(array $data)
    {
        return $this->post('/v1/webhooks', $data, 'webhook_create');
    }

    /**
     * Delete a webhook
     *
     * @param string $webhookId
     *
     * @return array|null
     */
    public function deleteWebhook($webhookId)
    {
        return $this->delete('/v1/webhooks/' . $webhookId, 'webhook_delete');
    }

    /**
     * Get a webhook
     *
     * @param string $webhookId
     *
     * @return array|null
     */
    public function getWebhook($webhookId)
    {
        return $this->get('/v1/webhooks/' . $webhookId, 'webhook_lookup');
    }

    // ──────────────────────────────────────────────
    // Payment Method Domains
    // ──────────────────────────────────────────────

    /**
     * List payment method domains
     *
     * @return array|null
     */
    public function listPaymentMethodDomains()
    {
        return $this->get('/v1/payment_method_domains', 'payment_domain_list');
    }

    /**
     * Register a payment method domain
     *
     * @param string $domainName
     *
     * @return array
     */
    public function registerPaymentMethodDomain($domainName)
    {
        return $this->post('/v1/payment_method_domains', array('domain_name' => $domainName), 'payment_domain_register');
    }

    // ──────────────────────────────────────────────
    // Account / Connection test
    // ──────────────────────────────────────────────

    /**
     * Test connection by fetching account info
     *
     * @return array
     */
    public function testConnection()
    {
        return $this->get('/v1/account', 'connection_test');
    }

    /**
     * Detect API key mode (test vs live)
     *
     * @return string  test|live|unknown
     */
    public function detectMode()
    {
        if (strpos($this->apiKey, 'sk_test') === 0) {
            return 'test';
        }
        if (strpos($this->apiKey, 'sk_live') === 0) {
            return 'live';
        }

        return 'unknown';
    }

    // ──────────────────────────────────────────────
    // HTTP helpers
    // ──────────────────────────────────────────────

    /**
     * @param string $endpoint
     * @param string $context   Fixed phrase naming the call, never the path
     *
     * @return array|null
     */
    private function get($endpoint, $context)
    {
        return $this->request('GET', $endpoint, null, $context);
    }

    /**
     * @param string $endpoint
     * @param array  $data
     * @param string $context
     *
     * @return array
     */
    private function post($endpoint, array $data, $context)
    {
        return $this->request('POST', $endpoint, $data, $context);
    }

    /**
     * @param string $endpoint
     * @param array  $data
     * @param string $context
     *
     * @return array|null
     */
    private function patch($endpoint, array $data, $context)
    {
        return $this->request('PATCH', $endpoint, $data, $context);
    }

    /**
     * @param string $endpoint
     * @param string $context
     *
     * @return array|null
     */
    private function delete($endpoint, $context)
    {
        return $this->request('DELETE', $endpoint, null, $context);
    }

    /**
     * Execute an HTTP request against the Paypercut API
     *
     * @param string     $method
     * @param string     $endpoint
     * @param array|null $data
     * @param string     $context
     *
     * @return array
     *
     * @throws PaypercutApiException on cURL error or non-2xx response
     */
    private function request($method, $endpoint, $data = null, $context = 'api')
    {
        $url = rtrim($this->baseUrl, '/') . $endpoint;
        $startedAt = microtime(true);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $headers = array(
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'User-Agent: Paypercut-PrestaShop/' . Paypercut::VERSION,
        );

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'PATCH') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            if ($data !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $connectTime = (float) curl_getinfo($ch, CURLINFO_CONNECT_TIME);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($curlError) {
            // A connect failure that took the full timeout is a network black
            // hole; one that returned at once is DNS or a refused port. Both are
            // distinct from a server that answered badly.
            $reason = $connectTime > 0 ? 'transport' : 'connect';
            $this->reportFailure($context, $reason, $durationMs);

            throw new PaypercutApiException('Paypercut API connection error: ' . $curlError, 0);
        }

        if ($httpCode == 0) {
            $this->reportFailure($context, 'transport', $durationMs);

            throw new PaypercutApiException('Paypercut API timeout: no response received.', 0);
        }

        $result = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            if ($result === null && trim((string) $response) !== '') {
                // Byte count only — never the body.
                PaypercutTelemetryRecorder::record(
                    PaypercutTelemetryEvent::failure('api.response_unparsable', 'decode_failed', array(
                        'api_context' => $context,
                        'body_bytes' => strlen((string) $response),
                    ))
                );
            }

            if ($durationMs >= PaypercutTelemetrySession::SLOW_REQUEST_MS) {
                // Only slow calls are timed as events: timing every call would
                // fill the queue with the requests nobody is investigating.
                PaypercutTelemetryRecorder::record(
                    PaypercutTelemetryEvent::of('api.request_slow', array(
                        'api_context' => $context,
                        'method' => $method,
                        'duration_ms' => $durationMs,
                    ))
                );
            }

            return $result ? $result : array();
        }

        // Build error message
        $errorMessage = 'API error (HTTP ' . $httpCode . ')';
        if ($result && isset($result['error']['message'])) {
            $errorMessage = $result['error']['message'];
        } elseif ($result && isset($result['message'])) {
            $errorMessage = $result['message'];
        }

        $exception = new PaypercutApiException(
            $errorMessage,
            $httpCode,
            is_array($result) ? $result : array()
        );

        PaypercutTelemetryRecorder::record(
            PaypercutTelemetryEvent::apiFailure('api.request_failed', $exception, array(
                'api_context' => $context,
                'duration_ms' => $durationMs,
                'body_parsable' => is_array($result),
            ))
        );

        throw $exception;
    }

    /**
     * A transport failure carries no status and no structured body, so it is
     * reported under its own reason code rather than as an HTTP failure.
     *
     * @param string $context
     * @param string $reason
     * @param int    $durationMs
     */
    private function reportFailure($context, $reason, $durationMs)
    {
        PaypercutTelemetryRecorder::record(
            PaypercutTelemetryEvent::failure('api.request_failed', $reason, array(
                'api_context' => $context,
                'duration_ms' => $durationMs,
            ))
        );
    }
}
