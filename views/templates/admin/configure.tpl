{**
 * Paypercut - Admin Configuration Page
 *
 * Tabbed interface for module settings:
 *   1. API Configuration
 *   2. Payment Settings
 *   3. Webhooks
 *   4. General
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 *}

<div class="paypercut-admin-config">
    {* Module header *}
    <div class="panel">
        <div class="panel-heading">
            <img src="{$paypercut_module_path|escape:'html':'UTF-8'}logo.png" alt="Paypercut" height="24" />
            Paypercut Payments
            {if $paypercut_mode}
                <span class="label label-{if $paypercut_mode == 'live'}success{elseif $paypercut_mode == 'test'}warning{else}default{/if} pull-right">
                    {$paypercut_mode|upper|escape:'html':'UTF-8'} {l s='MODE' mod='paypercut'}
                </span>
            {/if}
        </div>
    </div>

    {* Currency warning *}
    {if !$paypercut_currency_supported}
    <div class="alert alert-danger">
        <i class="material-icons" style="font-size:16px;vertical-align:middle">warning</i>
        {l s='Your store default currency' mod='paypercut'} (<strong>{$paypercut_store_currency|escape:'html':'UTF-8'}</strong>)
        {l s='is not supported by Paypercut. Supported currencies: BGN, DKK, SEK, NOK, GBP, EUR, USD, CHF, CZK, HUF, PLN, RON.' mod='paypercut'}
    </div>
    {/if}

    {* Tabs *}
    <ul class="nav nav-tabs" role="tablist">
        <li class="active">
            <a href="#paypercut-tab-api" data-toggle="tab">
                <i class="material-icons" style="font-size:14px;vertical-align:middle">vpn_key</i> {l s='API Configuration' mod='paypercut'}
            </a>
        </li>
        <li>
            <a href="#paypercut-tab-payment" data-toggle="tab">
                <i class="material-icons" style="font-size:14px;vertical-align:middle">credit_card</i> {l s='Payment Settings' mod='paypercut'}
            </a>
        </li>
        <li>
            <a href="#paypercut-tab-webhooks" data-toggle="tab">
                <i class="material-icons" style="font-size:14px;vertical-align:middle">sync_alt</i> {l s='Webhooks' mod='paypercut'}
            </a>
        </li>
        <li>
            <a href="#paypercut-tab-general" data-toggle="tab">
                <i class="material-icons" style="font-size:14px;vertical-align:middle">settings</i> {l s='General' mod='paypercut'}
            </a>
        </li>
    </ul>

    <form id="paypercut-config-form" class="form-horizontal" method="post" action="{$paypercut_admin_ajax_url|escape:'html':'UTF-8'}">
        <input type="hidden" name="submitPaypercutSettings" value="1" />

        <div class="tab-content">
            {* ─── Tab 1: API Configuration ─── *}
            <div class="tab-pane active" id="paypercut-tab-api">
                <div class="panel">
                    <div class="panel-heading">{l s='API Configuration' mod='paypercut'}</div>
                    <div class="panel-body">
                        <div class="form-group">
                            <label for="PAYPERCUT_API_KEY" class="control-label col-lg-3">
                                {l s='API Key' mod='paypercut'} <sup class="text-danger">*</sup>
                            </label>
                            <div class="col-lg-6">
                                <input type="text" id="PAYPERCUT_API_KEY" name="PAYPERCUT_API_KEY"
                                       class="form-control" value="{$paypercut_api_key|escape:'html':'UTF-8'}"
                                       placeholder="sk_test_... or sk_live_..." />
                                <p class="help-block">{l s='Your Paypercut secret API key.' mod='paypercut'}</p>
                            </div>
                            <div class="col-lg-3">
                                <button type="button" class="btn btn-default" id="paypercut-test-connection"
                                        data-url="{$paypercut_admin_ajax_url|escape:'html':'UTF-8'}">
                                    <i class="material-icons" style="font-size:14px;vertical-align:middle">power</i> {l s='Test Connection' mod='paypercut'}
                                </button>
                                <span id="paypercut-connection-result"></span>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            {* ─── Tab 2: Payment Settings ─── *}
            <div class="tab-pane" id="paypercut-tab-payment">
                <div class="panel">
                    <div class="panel-heading">{l s='Payment Settings' mod='paypercut'}</div>
                    <div class="panel-body">
                        {* Checkout mode *}
                        <div class="form-group">
                            <label class="control-label col-lg-3">{l s='Checkout Mode' mod='paypercut'}</label>
                            <div class="col-lg-6">
                                <select name="PAYPERCUT_CHECKOUT_MODE" class="form-control">
                                    <option value="hosted" {if $paypercut_checkout_mode == 'hosted'}selected{/if}>
                                        {l s='Hosted (Redirect to Paypercut)' mod='paypercut'}
                                    </option>
                                    <option value="embedded" {if $paypercut_checkout_mode == 'embedded'}selected{/if}>
                                        {l s='Embedded (Inline form on checkout page)' mod='paypercut'}
                                    </option>
                                </select>
                            </div>
                        </div>

                        {* Google Pay *}
                        <div class="form-group">
                            <label class="control-label col-lg-3">{l s='Google Pay' mod='paypercut'}</label>
                            <div class="col-lg-6">
                                <span class="switch prestashop-switch">
                                    <input type="radio" name="PAYPERCUT_GOOGLE_PAY" id="PAYPERCUT_GOOGLE_PAY_on" value="1"
                                           {if $paypercut_google_pay}checked{/if} />
                                    <label for="PAYPERCUT_GOOGLE_PAY_on">{l s='Yes' mod='paypercut'}</label>
                                    <input type="radio" name="PAYPERCUT_GOOGLE_PAY" id="PAYPERCUT_GOOGLE_PAY_off" value="0"
                                           {if !$paypercut_google_pay}checked{/if} />
                                    <label for="PAYPERCUT_GOOGLE_PAY_off">{l s='No' mod='paypercut'}</label>
                                    <a class="slide-button btn"></a>
                                </span>
                                <p class="help-block">{l s='Applies to embedded checkout only. The hosted checkout always displays all available payment methods.' mod='paypercut'}</p>
                            </div>
                        </div>

                        {* Apple Pay *}
                        <div class="form-group">
                            <label class="control-label col-lg-3">{l s='Apple Pay' mod='paypercut'}</label>
                            <div class="col-lg-6">
                                <span class="switch prestashop-switch">
                                    <input type="radio" name="PAYPERCUT_APPLE_PAY" id="PAYPERCUT_APPLE_PAY_on" value="1"
                                           {if $paypercut_apple_pay}checked{/if} />
                                    <label for="PAYPERCUT_APPLE_PAY_on">{l s='Yes' mod='paypercut'}</label>
                                    <input type="radio" name="PAYPERCUT_APPLE_PAY" id="PAYPERCUT_APPLE_PAY_off" value="0"
                                           {if !$paypercut_apple_pay}checked{/if} />
                                    <label for="PAYPERCUT_APPLE_PAY_off">{l s='No' mod='paypercut'}</label>
                                    <a class="slide-button btn"></a>
                                </span>
                                <p class="help-block">{l s='Applies to embedded checkout only. The hosted checkout always displays all available payment methods.' mod='paypercut'}</p>
                            </div>
                        </div>

                        {* Statement descriptor *}
                        <div class="form-group">
                            <label for="PAYPERCUT_STATEMENT_DESCRIPTOR" class="control-label col-lg-3">
                                {l s='Statement Descriptor' mod='paypercut'}
                            </label>
                            <div class="col-lg-6">
                                <input type="text" id="PAYPERCUT_STATEMENT_DESCRIPTOR" name="PAYPERCUT_STATEMENT_DESCRIPTOR"
                                       class="form-control" maxlength="22"
                                       value="{$paypercut_statement_descriptor|escape:'html':'UTF-8'}" />
                                <p class="help-block">{l s='Appears on customer bank statements. Max 22 characters.' mod='paypercut'}</p>
                            </div>
                        </div>

                        {* Order status *}
                        <div class="form-group">
                            <label class="control-label col-lg-3">{l s='Success Order Status' mod='paypercut'}</label>
                            <div class="col-lg-6">
                                <select name="PAYPERCUT_ORDER_STATUS_ID" class="form-control">
                                    {foreach $paypercut_order_statuses as $status}
                                    <option value="{$status.id_order_state|intval}"
                                            {if $status.id_order_state == $paypercut_order_status_id}selected{/if}>
                                        {$status.name|escape:'html':'UTF-8'}
                                    </option>
                                    {/foreach}
                                </select>
                                <p class="help-block">{l s='Order status set when payment succeeds.' mod='paypercut'}</p>
                            </div>
                        </div>


                    </div>
                </div>
            </div>

            {* ─── Tab 3: Webhooks ─── *}
            <div class="tab-pane" id="paypercut-tab-webhooks">
                <div class="panel">
                    <div class="panel-heading">{l s='Webhook Configuration' mod='paypercut'}</div>
                    <div class="panel-body">
                        <div class="form-group">
                            <label class="control-label col-lg-3">{l s='Webhook URL' mod='paypercut'}</label>
                            <div class="col-lg-6">
                                <input type="text" class="form-control" readonly
                                       value="{$paypercut_webhook_url|escape:'html':'UTF-8'}" />
                                <p class="help-block">{l s='This URL receives payment notifications from Paypercut.' mod='paypercut'}</p>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="control-label col-lg-3">{l s='Status' mod='paypercut'}</label>
                            <div class="col-lg-6">
                                {if $paypercut_webhook_status.configured}
                                    <span class="label label-success">
                                        <i class="material-icons" style="font-size:14px;vertical-align:middle">check_circle</i> {$paypercut_webhook_status.message|escape:'html':'UTF-8'}
                                    </span>
                                    <br /><br />
                                    {if !empty($paypercut_webhook_id)}
                                    <p class="text-muted">
                                        {l s='Webhook ID:' mod='paypercut'} <code>{$paypercut_webhook_id|escape:'html':'UTF-8'}</code>
                                    </p>
                                    {/if}
                                {else}
                                    <span class="label label-warning">
                                        <i class="material-icons" style="font-size:14px;vertical-align:middle">warning</i> {$paypercut_webhook_status.message|escape:'html':'UTF-8'}
                                    </span>
                                {/if}
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="control-label col-lg-3">&nbsp;</label>
                            <div class="col-lg-6">
                                {if $paypercut_webhook_status.configured}
                                    <button type="button" class="btn btn-danger" id="paypercut-delete-webhook"
                                            data-url="{$paypercut_admin_ajax_url|escape:'html':'UTF-8'}">
                                        <i class="material-icons" style="font-size:14px;vertical-align:middle">delete</i> {l s='Delete Webhook' mod='paypercut'}
                                    </button>
                                {else}
                                    <button type="button" class="btn btn-success" id="paypercut-create-webhook"
                                            data-url="{$paypercut_admin_ajax_url|escape:'html':'UTF-8'}">
                                        <i class="material-icons" style="font-size:14px;vertical-align:middle">add</i> {l s='Create Webhook' mod='paypercut'}
                                    </button>
                                {/if}
                                <span id="paypercut-webhook-result"></span>
                            </div>
                        </div>

                        {if !empty($paypercut_webhook_secret)}
                        <div class="form-group">
                            <label class="control-label col-lg-3">{l s='Webhook Secret' mod='paypercut'}</label>
                            <div class="col-lg-6">
                                <input type="text" class="form-control" readonly
                                       value="{$paypercut_webhook_secret|escape:'html':'UTF-8'}" />
                                <p class="help-block">{l s='Used for webhook signature verification. Stored securely.' mod='paypercut'}</p>
                            </div>
                        </div>
                        {/if}
                    </div>
                </div>
            </div>

            {* ─── Tab 4: General ─── *}
            <div class="tab-pane" id="paypercut-tab-general">
                <div class="panel">
                    <div class="panel-heading">{l s='General Settings' mod='paypercut'}</div>
                    <div class="panel-body">
                        {* Logging *}
                        <div class="form-group">
                            <label class="control-label col-lg-3">{l s='Debug Logging' mod='paypercut'}</label>
                            <div class="col-lg-6">
                                <span class="switch prestashop-switch">
                                    <input type="radio" name="PAYPERCUT_LOGGING" id="PAYPERCUT_LOGGING_on" value="1"
                                           {if $paypercut_logging}checked{/if} />
                                    <label for="PAYPERCUT_LOGGING_on">{l s='Yes' mod='paypercut'}</label>
                                    <input type="radio" name="PAYPERCUT_LOGGING" id="PAYPERCUT_LOGGING_off" value="0"
                                           {if !$paypercut_logging}checked{/if} />
                                    <label for="PAYPERCUT_LOGGING_off">{l s='No' mod='paypercut'}</label>
                                    <a class="slide-button btn"></a>
                                </span>
                                <p class="help-block">{l s='Enable to log API calls and errors to PrestaShop logs.' mod='paypercut'}</p>
                            </div>
                        </div>

                        {* Module info *}
                        <div class="form-group">
                            <label class="control-label col-lg-3">{l s='Module Version' mod='paypercut'}</label>
                            <div class="col-lg-6">
                                <p class="form-control-static">1.0.0</p>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="control-label col-lg-3">{l s='PrestaShop Version' mod='paypercut'}</label>
                            <div class="col-lg-6">
                                <p class="form-control-static">{$paypercut_ps_version|escape:'html':'UTF-8'}</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {* Save button *}
        <div class="panel-footer">
            <button type="submit" class="btn btn-default pull-right" name="submitPaypercutSettings">
                <i class="process-icon-save"></i> {l s='Save' mod='paypercut'}
            </button>
        </div>
    </form>
</div>
