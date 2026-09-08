<?php

/**
 * Exchanges the store's API key for a short-lived telemetry token.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaypercutTokenMinter
{
    const PATH = 'v1/telemetry/tokens';

    /**
     * Request a telemetry token.
     *
     * @param string $secret    The store's API key
     * @param string $mintBase  Base URI of the Payment Engine for this environment
     *
     * @return array  { status, body, token, expires_at, date, trace_id, request_id }
     */
    public function mint($secret, $mintBase)
    {
        // The store's long-lived API key travels on this request, so the
        // destination is re-validated here rather than trusted.
        $base = PaypercutEnvironment::allowedPaypercutBase($mintBase);

        if ($base === '') {
            return self::failure();
        }

        $response = PaypercutTelemetryHttp::postJson(
            $base . self::PATH,
            array(
                'Authorization: Bearer ' . $secret,
                'Accept: application/json',
            ),
            null,
            PaypercutTelemetrySession::MINT_TIMEOUT_SECONDS,
            PaypercutTelemetrySession::MINT_CONNECT_TIMEOUT_SECONDS
        );

        $decoded = json_decode((string) $response['body'], true);
        $body = is_array($decoded) ? $decoded : array();

        $traceId = PaypercutTelemetryHttp::header($response['headers'], 'Trace-Id');

        if ($traceId === '' && isset($body['trace_id']) && is_string($body['trace_id'])) {
            // On an error the gateway repeats the trace id in the body, which
            // is the more reliable of the two.
            $traceId = $body['trace_id'];
        }

        return array(
            'status' => (int) $response['status'],
            'body' => $body,
            'token' => isset($body['token']) && is_string($body['token']) ? $body['token'] : '',
            'expires_at' => isset($body['expires_at']) && is_string($body['expires_at']) ? $body['expires_at'] : '',
            'date' => PaypercutTelemetryHttp::header($response['headers'], 'Date'),
            'trace_id' => $traceId,
            'request_id' => PaypercutTelemetryHttp::header($response['headers'], 'X-Request-Id'),
        );
    }

    /**
     * How long the token is good for, measured on the MINT's clock.
     *
     * expires_at is stamped by the mint; time() is this server's idea of now.
     * Stores routinely drift by minutes, so the two are not comparable: copying
     * the timestamp would either overrun the token (clock behind) or make Start
     * permanently impossible (clock ahead). expires_at - Date is a duration,
     * which is portable to any clock.
     *
     * @param string $expiresAt   RFC3339 expiry from the response body
     * @param string $dateHeader  The response Date header, '' when absent
     * @param int    $now         This server's current unix timestamp
     *
     * @return int  Lifetime in seconds; 0 when expires_at cannot be parsed
     */
    public static function deriveLifetime($expiresAt, $dateHeader, $now)
    {
        $expiry = strtotime((string) $expiresAt);

        if ($expiry === false) {
            return 0;
        }

        $issued = (string) $dateHeader !== '' ? strtotime((string) $dateHeader) : false;

        if ($issued === false) {
            $issued = (int) $now;
        }

        return (int) $expiry - (int) $issued;
    }

    /**
     * Signed difference between the mint's clock and this server's, in seconds.
     *
     * Logged on every successful mint so support can spot a drifting store
     * before it becomes an unexplainable failure.
     *
     * @param string $dateHeader
     * @param int    $now
     *
     * @return int
     */
    public static function skew($dateHeader, $now)
    {
        if ((string) $dateHeader === '') {
            return 0;
        }

        $issued = strtotime((string) $dateHeader);

        return $issued === false ? 0 : (int) $issued - (int) $now;
    }

    /**
     * @return array
     */
    private static function failure()
    {
        return array(
            'status' => 0,
            'body' => array(),
            'token' => '',
            'expires_at' => '',
            'date' => '',
            'trace_id' => '',
            'request_id' => '',
        );
    }
}
