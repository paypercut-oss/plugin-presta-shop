{**
 * Paypercut - Order detail (customer account)
 *
 * Shown on the customer's order detail page.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 *}

{if !empty($transaction_id)}
<div class="paypercut-order-detail box">
    <h4>{l s='Payment Information' mod='paypercut'}</h4>
    <dl>
        <dt>{l s='Payment Method' mod='paypercut'}</dt>
        <dd>Paypercut</dd>

        <dt>{l s='Transaction ID' mod='paypercut'}</dt>
        <dd><code>{$transaction_id|escape:'html':'UTF-8'}</code></dd>

        {if !empty($status)}
        <dt>{l s='Status' mod='paypercut'}</dt>
        <dd>
            <span class="badge badge-{if $status == 'succeeded'}success{elseif $status == 'pending'}warning{else}danger{/if}">
                {$status|escape:'html':'UTF-8'}
            </span>
        </dd>
        {/if}
    </dl>
</div>
{/if}
