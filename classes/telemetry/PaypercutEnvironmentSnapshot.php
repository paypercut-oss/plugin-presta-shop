<?php

/**
 * Builds the one-off environment snapshot sent when a session starts.
 *
 * Reads the store's own configuration only. Every value it collects is named
 * explicitly here and cast against a declared schema; nothing is harvested by
 * walking a settings array, which is how a credential would end up on the wire.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaypercutEnvironmentSnapshot
{
    /**
     * @return array
     */
    public static function values()
    {
        $apiKey = (string) Configuration::get(Paypercut::CONFIG_API_KEY);
        $theme = self::theme();
        $currency = Currency::getDefaultCurrency();

        return array(
            'plugin_version' => (string) Paypercut::moduleVersion(),
            'platform_version' => (string) _PS_VERSION_,
            'php_version' => PHP_VERSION,
            'theme_name' => $theme['name'],
            'theme_version' => $theme['version'],
            'is_multistore' => (bool) Shop::isFeatureActive(),
            'is_ssl' => (bool) Configuration::get('PS_SSL_ENABLED'),
            'checkout_mode' => (string) Configuration::get(Paypercut::CONFIG_CHECKOUT_MODE),
            'order_status' => (string) (int) Configuration::get(Paypercut::CONFIG_ORDER_STATUS_ID),
            // Presence booleans derived from settings whose values never travel.
            'statement_descriptor_set' => '' !== (string) Configuration::get(Paypercut::CONFIG_STATEMENT_DESCRIPTOR),
            'google_pay_enabled' => (bool) Configuration::get(Paypercut::CONFIG_GOOGLE_PAY),
            'apple_pay_enabled' => (bool) Configuration::get(Paypercut::CONFIG_APPLE_PAY),
            'logging_enabled' => (bool) Configuration::get(Paypercut::CONFIG_LOGGING),
            'card_enabled' => $apiKey !== '',
            'connection_environment' => PaypercutEnvironment::current(),
            'api_key_mode' => self::apiKeyMode($apiKey),
            'webhook_configured' => '' !== (string) Configuration::get(Paypercut::CONFIG_WEBHOOK_SECRET),
            'payment_domain_registered' => '' !== (string) Configuration::get(Paypercut::CONFIG_DOMAIN_ID),
            'currency_supported' => $currency && in_array(Tools::strtoupper($currency->iso_code), Paypercut::SUPPORTED_CURRENCIES, true),
        );
    }

    /**
     * The key's mode, never the key. Mirrors PaypercutApi::detectMode() without
     * constructing a client.
     *
     * @param string $apiKey
     *
     * @return string
     */
    private static function apiKeyMode($apiKey)
    {
        if (strpos($apiKey, 'sk_test') === 0) {
            return 'test';
        }

        if (strpos($apiKey, 'sk_live') === 0) {
            return 'live';
        }

        return 'unknown';
    }

    /**
     * @return array  { name, version }
     */
    private static function theme()
    {
        $context = Context::getContext();

        if ($context && isset($context->shop) && Validate::isLoadedObject($context->shop)) {
            $theme = $context->shop->theme;

            if (is_object($theme) && method_exists($theme, 'getName')) {
                return array(
                    'name' => (string) $theme->getName(),
                    'version' => method_exists($theme, 'get') ? (string) $theme->get('version') : '',
                );
            }
        }

        return array(
            'name' => (string) Configuration::get('PS_THEME_NAME'),
            'version' => '',
        );
    }
}
