{**
 * Paypercut - Global back-office notice while a debug session is running
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 *}

<div class="alert alert-info paypercut-debug-notice">
    <strong>Paypercut:</strong>
    {l s='a debug session started by' mod='paypercut'}
    <strong>{$paypercut_debug_started_by|escape:'html':'UTF-8'}</strong>
    {l s='is running until' mod='paypercut'}
    <strong>{$paypercut_debug_ends_at|escape:'html':'UTF-8'}</strong>.
    <a href="{$paypercut_debug_manage_url|escape:'html':'UTF-8'}">{l s='Manage it' mod='paypercut'}</a>
</div>
