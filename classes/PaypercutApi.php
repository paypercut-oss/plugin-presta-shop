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

class PaypercutApi
{
    const BASE_URL = 'https://api.paypercut.io';
    const TIMEOUT = 30;
    const CONNECT_TIMEOUT = 10;

    /** @var string */
    private $apiKey;

    /**
     * @param string $apiKey
     */
    public function __construct($apiKey)
    {
        $this->apiKey = $apiKey;
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
        return $this->post('/v1/checkouts', $data);
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
        return $this->get('/v1/checkouts/' . $checkoutId);
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
        return $this->get('/v1/payments/' . $paymentId);
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
        return $this->post('/v1/payment_intents/' . $paymentIntentId . '/confirm', array());
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
        return $this->post('/v1/payment_intents/' . $paymentIntentId . '/cancel', array());
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
        return $this->post('/v1/refunds', $data);
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
        return $this->get('/v1/refunds/' . $refundId);
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
        return $this->post('/v1/customers', $data);
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
        return $this->get('/v1/customers/' . $customerId);
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
        return $this->patch('/v1/customers/' . $customerId, $data);
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
        return $this->get('/v1/webhooks');
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
        return $this->post('/v1/webhooks', $data);
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
        return $this->delete('/v1/webhooks/' . $webhookId);
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
        return $this->get('/v1/webhooks/' . $webhookId);
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
        return $this->get('/v1/payment_method_domains');
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
        return $this->post('/v1/payment_method_domains', array('domain_name' => $domainName));
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
        return $this->get('/v1/account');
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
     *
     * @return array|null
     */
    private function get($endpoint)
    {
        return $this->request('GET', $endpoint);
    }

    /**
     * @param string $endpoint
     * @param array  $data
     *
     * @return array
     */
    private function post($endpoint, array $data)
    {
        return $this->request('POST', $endpoint, $data);
    }

    /**
     * @param string $endpoint
     * @param array  $data
     *
     * @return array|null
     */
    private function patch($endpoint, array $data)
    {
        return $this->request('PATCH', $endpoint, $data);
    }

    /**
     * @param string $endpoint
     *
     * @return array|null
     */
    private function delete($endpoint)
    {
        return $this->request('DELETE', $endpoint);
    }

    /**
     * Execute an HTTP request against the Paypercut API
     *
     * @param string     $method
     * @param string     $endpoint
     * @param array|null $data
     *
     * @return array
     *
     * @throws Exception on cURL error or non-2xx response
     */
    private function request($method, $endpoint, $data = null)
    {
        $url = self::BASE_URL . $endpoint;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

        $headers = array(
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'User-Agent: Paypercut-PrestaShop/1.0.0',
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
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError) {
            throw new Exception('Paypercut API connection error: ' . $curlError);
        }

        if ($httpCode == 0) {
            throw new Exception('Paypercut API timeout: no response received.');
        }

        $result = json_decode($response, true);

        if ($httpCode >= 200 && $httpCode < 300) {
            return $result ? $result : array();
        }

        // Build error message
        $errorMessage = 'API error (HTTP ' . $httpCode . ')';
        if ($result && isset($result['error']['message'])) {
            $errorMessage = $result['error']['message'];
        } elseif ($result && isset($result['message'])) {
            $errorMessage = $result['message'];
        }

        throw new Exception($errorMessage);
    }
}
