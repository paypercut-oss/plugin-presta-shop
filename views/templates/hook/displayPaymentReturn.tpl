{**
 * Paypercut - Payment return display (order confirmation page)
 *
 * Shown on the order-confirmation page via hookPaymentReturn.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 *}

<div class="paypercut-payment-return">
    <p>
        <strong>{l s='Your payment has been processed successfully.' mod='paypercut'}</strong>
    </p>

    {if !empty($transaction_id)}
    <p>
        {l s='Transaction ID:' mod='paypercut'} <code>{$transaction_id|escape:'html':'UTF-8'}</code>
    </p>
    {/if}

    <p>
        {l s='Thank you for your purchase!' mod='paypercut'}
    </p>
</div>
