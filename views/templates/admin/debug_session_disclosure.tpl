{**
 * Paypercut - "What is shared" disclosure
 *
 * The single source for this copy: the panel and the consent modal both
 * include this file, and README.md repeats the same wording for the store
 * listing. tests/DisclosureTest asserts the two do not drift.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 *}

<details class="paypercut-debug-session__disclosure">
    <summary>{l s='What is shared' mod='paypercut'}</summary>
    <p>
        {l s='Module, PrestaShop, PHP and theme versions; the modules active on this store and their versions; how this store has the Paypercut module configured (which checkout mode is selected and which options are switched on — never the values of your credentials); a record of each checkout, refund and payment notification the module handled and whether it succeeded, identified by PrestaShop order reference and Paypercut payment reference; when something fails, the error message, the file and line it came from, and which module or theme raised it; and when the session started and stopped.' mod='paypercut'}
    </p>
    <p>
        <strong>{l s='Not shared:' mod='paypercut'}</strong>
        {l s='customer names, email addresses, billing or shipping addresses, order totals, line items, payment card data, the reason text you type when issuing a refund, or any API key, webhook secret or password.' mod='paypercut'}
    </p>
    <p>
        {l s='Your API key is never sent to the telemetry service. It is used once, over HTTPS, to obtain a short-lived diagnostic token from api.paypercut.io.' mod='paypercut'}
    </p>
    <p>
        {l s='Paypercut keeps this diagnostic data for 30 days.' mod='paypercut'}
    </p>
</details>
