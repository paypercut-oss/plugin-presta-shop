{**
 * Paypercut - Embedded checkout form
 *
 * Rendered as the payment form when checkout mode is "embedded".
 * Contains a container for the Paypercut JS SDK to mount the checkout form.
 * The SDK is loaded lazily when the customer selects this payment option.
 *
 * Compatible with PrestaShop 8.x and 9.x.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 *}

<div id="paypercut-embedded-checkout"
     data-ajax-url="{$paypercut_ajax_url|escape:'html':'UTF-8'}"
     data-token="{$paypercut_token|escape:'html':'UTF-8'}"
     data-loading-text="{l s='Loading payment form...' mod='paypercut'}">
    <div id="paypercut-checkout-container">
        {* Spinner shown by JS when the customer selects this payment option *}
    </div>
</div>
