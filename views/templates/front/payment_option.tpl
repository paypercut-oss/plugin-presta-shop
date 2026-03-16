{**
 * Paypercut - Payment option info shown below the radio button on checkout
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 *}

<link rel="stylesheet" href="{$paypercut_module_path|escape:'html':'UTF-8'}views/css/paypercut.css" />
{if !empty($paypercut_ajax_url)}
<link rel="preconnect" href="https://buy.paypercut.io" />
<link rel="dns-prefetch" href="https://buy.paypercut.io" />
<script src="{$paypercut_module_path|escape:'html':'UTF-8'}views/js/paypercut-checkout.js" defer></script>
{/if}
<div class="paypercut-payment-option">
    <p class="paypercut-secure">
        <i class="material-icons">lock</i>
        {l s='Secure payment powered by Paypercut' mod='paypercut'}
    </p>
</div>

{if !empty($paypercut_ajax_url)}
<div id="paypercut-embedded-checkout"
     data-ajax-url="{$paypercut_ajax_url|escape:'html':'UTF-8'}"
     data-token="{$paypercut_token|escape:'html':'UTF-8'}"
     data-loading-text="{l s='Loading payment form...' mod='paypercut'}">
    <div id="paypercut-checkout-container">
        {* SDK mounts the checkout form here *}
    </div>
</div>
{/if}
