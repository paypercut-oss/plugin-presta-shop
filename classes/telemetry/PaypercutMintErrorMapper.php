<?php

/**
 * Maps a telemetry mint rejection onto merchant-facing copy.
 *
 * Branches on the HTTP status first and consults the body only to refine copy:
 * the mint surfaces its gates as bare gRPC statuses with no public-error
 * metadata, so "telemetry_token_key_inactive" and friends arrive capitalised in
 * `message` with no `code` key at all — hence substring matching.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaypercutMintErrorMapper
{
    const NETWORK_ERROR = 'network_error';

    /**
     * @param int   $status  HTTP status, or 0 for a transport failure
     * @param array $body    Decoded response body, empty when absent
     *
     * @return array  { reason_code, message, retryable }
     */
    public static function map($status, array $body = array())
    {
        $detail = self::detail($body);

        switch ((int) $status) {
            case 0:
                return self::result(
                    self::NETWORK_ERROR,
                    self::t('Your server couldn\'t reach Paypercut to start the debug session. Check that outbound HTTPS requests are allowed by your host or firewall, then try again.'),
                    true
                );

            case 401:
                return self::result(
                    'key_invalid',
                    self::t('Paypercut couldn\'t verify this store\'s API key, so the debug session was not started and nothing was sent. Use Test Connection above, or re-enter your API key, then try again.'),
                    false
                );

            case 400:
                if (strpos($detail, 'ineligible') !== false) {
                    return self::result(
                        'key_ineligible',
                        self::t('This store\'s Paypercut API key isn\'t eligible for debug sessions yet — this usually means the key isn\'t fully activated on your Paypercut account. Nothing has been sent. Contact Paypercut support and quote your account name from the API Configuration tab.'),
                        false
                    );
                }

                return self::result(
                    'request_rejected',
                    self::t('Paypercut rejected the debug session request. Nothing has been sent. Contact Paypercut support if this keeps happening.'),
                    false
                );

            case 403:
                return self::result(
                    'account_refused',
                    self::t('This store\'s Paypercut account isn\'t allowed to start debug sessions. Contact Paypercut support.'),
                    false
                );

            case 404:
                return self::result(
                    'not_available',
                    self::t('Debug sessions aren\'t available for this store\'s Paypercut environment yet. Nothing was sent.'),
                    false
                );

            case 429:
                return self::result(
                    'rate_limited',
                    self::t('Too many attempts. Wait about a minute and try again.'),
                    true
                );

            case 503:
            case 504:
                return self::result(
                    'temporarily_unavailable',
                    self::t('Paypercut\'s debug service is temporarily unavailable. Please try again in a few minutes.'),
                    true
                );
        }

        if ((int) $status >= 500) {
            return self::result(
                'service_error',
                self::t('Paypercut couldn\'t issue a debug token. Please try again — if it keeps happening, contact support and quote the reference below.'),
                true
            );
        }

        return self::result(
            'unexpected_response',
            self::t('Paypercut returned an unexpected response. The debug session was not started — please try again.'),
            true
        );
    }

    /**
     * Copy for a 200 whose payload cannot be used.
     *
     * @return array
     */
    public static function badResponse()
    {
        return self::result(
            'bad_response',
            self::t('Paypercut returned an unexpected response. The debug session was not started — please try again.'),
            true
        );
    }

    /**
     * Copy for a store whose clock is too far from Paypercut's to trust.
     *
     * Deliberately not reported as a Paypercut failure: it is a local NTP
     * problem, and saying otherwise sends the merchant to the wrong place.
     *
     * @param int $skewSeconds  Signed difference between the mint's clock and this server's
     *
     * @return array
     */
    public static function clockSkew($skewSeconds)
    {
        $minutes = (int) round(abs((int) $skewSeconds) / 60);

        return self::result(
            'clock_skew',
            sprintf(
                self::t('This server\'s clock appears to be out of sync with Paypercut (off by about %d minutes), so a debug session can\'t be started. Ask your host to enable time synchronisation (NTP), then try again.'),
                $minutes
            ),
            false
        );
    }

    /**
     * Lowercased haystack of the body fields worth matching against.
     *
     * @param array $body
     *
     * @return string
     */
    private static function detail(array $body)
    {
        $parts = array();

        foreach (array('code', 'message', 'error', 'reason') as $key) {
            if (isset($body[$key]) && is_string($body[$key])) {
                $parts[] = $body[$key];
            }
        }

        return Tools::strtolower(implode(' ', $parts));
    }

    /**
     * @param string $reasonCode
     * @param string $message
     * @param bool   $retryable
     *
     * @return array
     */
    private static function result($reasonCode, $message, $retryable)
    {
        return array(
            'reason_code' => $reasonCode,
            'message' => $message,
            'retryable' => (bool) $retryable,
        );
    }

    /**
     * @param string $string
     *
     * @return string
     */
    private static function t($string)
    {
        return Translate::getModuleTranslation('paypercut', $string, 'paypercutminterrormapper');
    }
}
