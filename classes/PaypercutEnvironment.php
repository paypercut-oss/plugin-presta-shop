<?php

/**
 * Paypercut environment resolution
 *
 * One stored environment value resolves BOTH the API host and the telemetry
 * edge host. A telemetry token minted for one environment is rejected by every
 * other environment's edge with a 401 that is indistinguishable from a forged
 * token, so the two must never be resolved from independent settings.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaypercutEnvironment
{
    const PRODUCTION = 'production';

    const ENVIRONMENTS = array('dev', 'stage', 'production');

    const API_BASE_URIS = array(
        'dev' => 'https://api.dev.paypercut.net/',
        'stage' => 'https://api.stage.paypercut.net/',
        'production' => 'https://api.paypercut.io/',
    );

    const DASHBOARD_URLS = array(
        'dev' => 'https://dashboard.dev.paypercut.net/',
        'stage' => 'https://dashboard.stage.paypercut.net/',
        'production' => 'https://dashboard.paypercut.io/',
    );

    const CHECKOUT_ORIGINS = array(
        'dev' => 'https://buy.dev.paypercut.net',
        'stage' => 'https://buy.stage.paypercut.net',
        'production' => 'https://buy.paypercut.io',
    );

    const TELEMETRY_BASE_URIS = array(
        'dev' => 'https://telemetry.dev.paypercut.net/',
        'stage' => 'https://telemetry.stage.paypercut.net/',
        'production' => 'https://telemetry.paypercut.io/',
    );

    /**
     * Normalise a stored environment value.
     *
     * @param string $environment
     *
     * @return string  One of self::ENVIRONMENTS, or '' when unrecognised
     */
    public static function normalize($environment)
    {
        $environment = Tools::strtolower(trim((string) $environment));

        return in_array($environment, self::ENVIRONMENTS, true) ? $environment : '';
    }

    /**
     * The environment this store is connected to, from module configuration.
     *
     * @return string  One of self::ENVIRONMENTS, or '' when unrecognised
     */
    public static function current()
    {
        return self::normalize(Configuration::get(Paypercut::CONFIG_ENVIRONMENT));
    }

    /**
     * Core API base URI.
     *
     * Falls back to production for an unknown environment: this is the payment
     * API, and an existing store that never recorded an environment must keep
     * taking payments.
     *
     * @param string $environment
     *
     * @return string  Always a usable https base URI, with a trailing slash
     */
    public static function apiBaseUri($environment = '')
    {
        $environment = self::normalize($environment);

        if ($environment === '' || !isset(self::API_BASE_URIS[$environment])) {
            $environment = self::PRODUCTION;
        }

        $base = self::allowedPaypercutBase(self::API_BASE_URIS[$environment]);

        return $base !== '' ? $base : self::API_BASE_URIS[self::PRODUCTION];
    }

    /**
     * Telemetry edge base URI.
     *
     * Unlike apiBaseUri() this does NOT fall back to production: an unknown
     * environment must yield no debug session rather than a confusing one that
     * the edge refuses with an unexplainable 401.
     *
     * @param string $environment
     *
     * @return string  '' when this environment has no telemetry edge
     */
    public static function telemetryBaseUri($environment = '')
    {
        $environment = self::normalize($environment);

        // Named environments only. A leftover debug constant must not retarget a
        // live store's telemetry, and must not give an unknown environment an
        // edge the mint host would not follow either — that pairs a production
        // token with a dev edge, which 401s and burns the merchant's consent.
        if (defined('PAYPERCUT_TELEMETRY_BASE_URI') && in_array($environment, array('dev', 'stage'), true)) {
            return self::allowedPaypercutBase((string) constant('PAYPERCUT_TELEMETRY_BASE_URI'));
        }

        if ($environment === '' || !isset(self::TELEMETRY_BASE_URIS[$environment])) {
            return '';
        }

        return self::allowedPaypercutBase(self::TELEMETRY_BASE_URIS[$environment]);
    }

    /**
     * Merchant-facing dashboard, for the deep links on the admin order panel.
     *
     * @param string $environment
     *
     * @return string  Always a usable URL, with a trailing slash
     */
    public static function dashboardUrl($environment = '')
    {
        $environment = self::normalize($environment);

        if ($environment === '' || !isset(self::DASHBOARD_URLS[$environment])) {
            $environment = self::PRODUCTION;
        }

        return self::DASHBOARD_URLS[$environment];
    }

    /**
     * Hosted-checkout origin, used only as a preconnect hint on the payment
     * step. The real URL always comes from the checkout session response.
     *
     * @param string $environment
     *
     * @return string  No trailing slash
     */
    public static function checkoutOrigin($environment = '')
    {
        $environment = self::normalize($environment);

        if ($environment === '' || !isset(self::CHECKOUT_ORIGINS[$environment])) {
            $environment = self::PRODUCTION;
        }

        return self::CHECKOUT_ORIGINS[$environment];
    }

    /**
     * Accept a base URI only on an https Paypercut host.
     *
     * A credential travels on the mint request, so the destination is checked
     * rather than trusted. The end-of-string anchor is load-bearing: it rejects
     * https://paypercut.io.evil.com/, https://notpaypercut.io/ and any http://.
     *
     * @param string $url
     *
     * @return string  '' when the host is not ours; trailing-slashed otherwise
     */
    public static function allowedPaypercutBase($url)
    {
        $url = trim((string) $url);

        if ($url === '') {
            return '';
        }

        $parts = parse_url($url);
        $scheme = isset($parts['scheme']) ? Tools::strtolower((string) $parts['scheme']) : '';
        $host = isset($parts['host']) ? Tools::strtolower((string) $parts['host']) : '';

        if ($scheme !== 'https' || $host === '' || !preg_match('/(^|\.)paypercut\.(net|io)\z/D', $host)) {
            return '';
        }

        return rtrim($url, '/') . '/';
    }
}
