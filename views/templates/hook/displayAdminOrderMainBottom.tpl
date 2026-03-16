{**
 * Paypercut - Admin Order Panel
 *
 * Shown in the back-office order detail page via
 * displayAdminOrderLeft / displayAdminOrderMainBottom hooks.
 * Shows transaction details and refund interface.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 *}

<div class="panel paypercut-admin-order-panel">
    <div class="panel-heading">
        <img src="{$moduleLogoSrc|escape:'html':'UTF-8'}" alt="Paypercut" height="16" />
        {$moduleDisplayName|escape:'html':'UTF-8'} — {l s='Payment Details' mod='paypercut'}
    </div>
    <div class="panel-body">
        {if $transaction}
        <table class="table table-striped paypercut-transaction-table">
            <tr>
                <td><strong>{l s='Payment ID' mod='paypercut'}</strong></td>
                <td>
                    <code>{$transaction.payment_id|escape:'html':'UTF-8'}</code>
                    <a href="{$dashboard_url|escape:'html':'UTF-8'}" target="_blank" class="btn btn-xs btn-default" title="{l s='View in Paypercut Dashboard' mod='paypercut'}">
                        <i class="material-icons" style="font-size:14px;vertical-align:middle">open_in_new</i>
                    </a>
                </td>
            </tr>
            {if !empty($transaction.checkout_id)}
            <tr>
                <td><strong>{l s='Checkout ID' mod='paypercut'}</strong></td>
                <td><code>{$transaction.checkout_id|escape:'html':'UTF-8'}</code></td>
            </tr>
            {/if}
            {if !empty($transaction.payment_intent_id)}
            <tr>
                <td><strong>{l s='Payment Intent' mod='paypercut'}</strong></td>
                <td><code>{$transaction.payment_intent_id|escape:'html':'UTF-8'}</code></td>
            </tr>
            {/if}
            <tr>
                <td><strong>{l s='Status' mod='paypercut'}</strong></td>
                <td>
                    <span class="label label-{if $transaction.payment_status == 'succeeded'}success{elseif $transaction.payment_status == 'pending'}warning{else}danger{/if}">
                        {$transaction.payment_status|escape:'html':'UTF-8'}
                    </span>
                </td>
            </tr>
            {if !empty($transaction.payment_method)}
            <tr>
                <td><strong>{l s='Payment Method' mod='paypercut'}</strong></td>
                <td>{$transaction.payment_method|escape:'html':'UTF-8'}</td>
            </tr>
            {/if}
            <tr>
                <td><strong>{l s='Amount' mod='paypercut'}</strong></td>
                <td>{($transaction.amount / 100)|number_format:2} {$transaction.currency|escape:'html':'UTF-8'}</td>
            </tr>
        </table>

        {* Refunds *}
        {if $refunds && count($refunds) > 0}
        <h4>{l s='Refunds' mod='paypercut'}</h4>
        <table class="table table-condensed paypercut-refund-table">
            <thead>
                <tr>
                    <th>{l s='Refund ID' mod='paypercut'}</th>
                    <th>{l s='Amount' mod='paypercut'}</th>
                    <th>{l s='Status' mod='paypercut'}</th>
                    <th>{l s='Date' mod='paypercut'}</th>
                </tr>
            </thead>
            <tbody>
                {foreach $refunds as $refund}
                <tr>
                    <td><code>{$refund.refund_id|escape:'html':'UTF-8'}</code></td>
                    <td>{$refund.amount|number_format:2} {$transaction.currency|escape:'html':'UTF-8'}</td>
                    <td>
                        <span class="label label-{if $refund.status == 'succeeded'}success{elseif $refund.status == 'pending'}warning{else}danger{/if}">
                            {$refund.status|escape:'html':'UTF-8'}
                        </span>
                    </td>
                    <td>{$refund.date_add|escape:'html':'UTF-8'}</td>
                </tr>
                {/foreach}
            </tbody>
        </table>

        <p>
            <strong>{l s='Total Refunded:' mod='paypercut'}</strong> {$total_refunded|number_format:2} {$transaction.currency|escape:'html':'UTF-8'}
        </p>
        {/if}

        {* Refund form *}
        {if $transaction.payment_status == 'succeeded' && $max_refundable > 0}
        <hr />
        <h4>{l s='Issue Refund' mod='paypercut'}</h4>
        <div class="paypercut-refund-form" id="paypercut-refund-form">
            <div class="form-group">
                <label for="paypercut-refund-amount">{l s='Amount' mod='paypercut'}</label>
                <div class="input-group">
                    <input type="number" id="paypercut-refund-amount" class="form-control"
                           step="0.01" min="0.01" max="{$max_refundable|number_format:2}"
                           value="{$max_refundable|number_format:2}" />
                    <span class="input-group-addon">{$transaction.currency|escape:'html':'UTF-8'}</span>
                </div>
                <p class="help-block">{l s='Max:' mod='paypercut'} {$max_refundable|number_format:2} {$transaction.currency|escape:'html':'UTF-8'}</p>
            </div>
            <div class="form-group">
                <label for="paypercut-refund-reason">{l s='Reason (optional)' mod='paypercut'}</label>
                <select id="paypercut-refund-reason" class="form-control">
                    <option value="">{l s='-- Select reason --' mod='paypercut'}</option>
                    <option value="duplicate">{l s='Duplicate' mod='paypercut'}</option>
                    <option value="fraudulent">{l s='Fraudulent' mod='paypercut'}</option>
                    <option value="requested_by_customer">{l s='Requested by customer' mod='paypercut'}</option>
                </select>
            </div>
            <button type="button" class="btn btn-warning" id="paypercut-refund-btn"
                    data-url="{$refund_url|escape:'html':'UTF-8'}"
                    data-order-id="{$id_order|intval}">
                <i class="material-icons" style="font-size:14px;vertical-align:middle">undo</i> {l s='Refund' mod='paypercut'}
            </button>
            <div id="paypercut-refund-result" class="mt-2"></div>
        </div>
        {/if}

        {else}
        <div class="alert alert-info">
            {l s='No transaction details available for this order.' mod='paypercut'}
        </div>
        {/if}
    </div>
</div>
