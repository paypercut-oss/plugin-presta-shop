<?php

/**
 * Delivers a batch of diagnostic events to the public telemetry edge.
 *
 * The edge verifies the bearer token offline and never calls back into the
 * platform, so a request never blocks on the payment platform.
 *
 * The body is worth reading. A 202 carries {"accepted":N,"dropped":M} — the
 * only way a client learns the edge discarded part of a batch it accepted — and
 * a 413 carries the limits a batch must be split to satisfy.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaypercutEdgeClient
{
    const PATH = 'v1/telemetry';

    /** The edge's own responses are a few dozen bytes; anything larger is not one. */
    const MAX_RESPONSE_BYTES = 4096;

    /**
     * POST one batch.
     *
     * @param string $edgeBase
     * @param string $jwt
     * @param string $jsonBody
     *
     * @return array  { status, retry_after, body }; status 0 means the request
     *                never completed
     */
    public function send($edgeBase, $jwt, $jsonBody)
    {
        $base = PaypercutEnvironment::allowedPaypercutBase($edgeBase);

        if ($base === '') {
            return array('status' => 0, 'retry_after' => 0, 'body' => array());
        }

        $response = PaypercutTelemetryHttp::postJson(
            $base . self::PATH,
            array(
                'Authorization: Bearer ' . $jwt,
                'Content-Type: application/json',
            ),
            (string) $jsonBody,
            PaypercutTelemetrySession::EDGE_TIMEOUT_SECONDS,
            PaypercutTelemetrySession::EDGE_CONNECT_TIMEOUT_SECONDS
        );

        return array(
            'status' => (int) $response['status'],
            'retry_after' => (int) PaypercutTelemetryHttp::header($response['headers'], 'Retry-After'),
            'body' => self::decode((string) $response['body']),
        );
    }

    /**
     * Anything that is not a JSON object is no answer at all.
     *
     * A 413 from a proxy in front of the edge is an HTML page, and a captive
     * portal will happily return 200 with a login form.
     *
     * @param string $body
     *
     * @return array
     */
    private static function decode($body)
    {
        if ($body === '' || strlen($body) > self::MAX_RESPONSE_BYTES) {
            return array();
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : array();
    }
}
