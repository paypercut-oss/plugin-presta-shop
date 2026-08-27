{**
 * Paypercut - Debug session panel
 *
 * Four server-rendered states plus the consent modal. The server paints the
 * current state so the panel is correct with no round trip; paypercut-debug-session.js
 * then keeps the countdown and the counters live.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 *}

<div class="paypercut-debug-session" id="paypercut-debug-session"
     data-url="{$paypercut_admin_ajax_url|escape:'html':'UTF-8'}"
     data-now="{$paypercut_debug_session_now|intval}"
     data-poll="{$paypercut_debug_session_poll|intval}"
     data-state="{$paypercut_debug_session.state|escape:'html':'UTF-8'}"
     data-expires-at="{$paypercut_debug_session.expires_at|intval}"
     {* Every field render() writes. It runs once at load, and anything absent
        here overwrites what this template just rendered with a blank. *}
     data-session-id="{$paypercut_debug_session.session_id|escape:'html':'UTF-8'}"
     data-started-by="{$paypercut_debug_session.started_by_name|escape:'html':'UTF-8'}"
     data-events-sent="{$paypercut_debug_session.events_sent|intval}"
     data-events-dropped="{$paypercut_debug_session.events_dropped|intval}"
     data-message="{$paypercut_debug_session.message|escape:'html':'UTF-8'}"
     data-trace-id="{$paypercut_debug_session.trace_id|escape:'html':'UTF-8'}">

    <div id="paypercut-debug-session-status" class="alert" role="status" style="display:none"></div>

    {* ── idle ── *}
    <div data-paypercut-state="idle"{if $paypercut_debug_session.state != 'idle'} style="display:none"{/if}>
        <p><strong>{l s='Off. Nothing is sent to Paypercut until you start a session.' mod='paypercut'}</strong></p>
        <p class="help-block">{l s='Turn on detailed diagnostics for about an hour so Paypercut support can see what your store is doing. The session ends by itself.' mod='paypercut'}</p>
        {include file='./debug_session_disclosure.tpl'}
        <p>
            <button type="button" class="btn btn-primary paypercut-debug-session__start">
                {l s='Start debug session' mod='paypercut'}
            </button>
        </p>
    </div>

    {* ── running ── *}
    <div data-paypercut-state="running"{if $paypercut_debug_session.state != 'running'} style="display:none"{/if}>
        <p>
            <span class="paypercut-debug-session__dot"></span>
            {l s='Debug session running' mod='paypercut'} —
            <span data-paypercut-countdown>&mdash;</span> {l s='remaining' mod='paypercut'}
        </p>
        <p class="help-block">
            {l s='Started by' mod='paypercut'}
            <span data-paypercut-started-by>{$paypercut_debug_session.started_by_name|escape:'html':'UTF-8'}</span>
            &middot; {l s='ends at' mod='paypercut'}
            <span data-paypercut-ends-at>{$paypercut_debug_session_ends_at|escape:'html':'UTF-8'}</span>
        </p>
        <p>
            {l s='Session ID' mod='paypercut'}
            <code data-paypercut-session-id>{$paypercut_debug_session.session_id|escape:'html':'UTF-8'}</code>
            <button type="button" class="btn btn-link paypercut-debug-session__copy" data-paypercut-copy>{l s='Copy' mod='paypercut'}</button>
        </p>
        <p class="help-block">
            <span data-paypercut-sent>{$paypercut_debug_session.events_sent|intval}</span> {l s='events sent' mod='paypercut'}
            &middot; <span data-paypercut-dropped>{$paypercut_debug_session.events_dropped|intval}</span> {l s='dropped (approximate)' mod='paypercut'}
        </p>
        <p>
            <button type="button" class="btn btn-danger paypercut-debug-session__stop">
                {l s='Stop now' mod='paypercut'}
            </button>
        </p>
    </div>

    {* ── ended ── *}
    <div data-paypercut-state="ended"{if $paypercut_debug_session.state != 'ended'} style="display:none"{/if}>
        <p>
            <strong>{l s='Debug session ended.' mod='paypercut'}</strong>
            {l s='Paypercut stops receiving data from this store.' mod='paypercut'}
        </p>
        <p>
            {l s='Last session ID' mod='paypercut'}
            <code data-paypercut-session-id>{$paypercut_debug_session.session_id|escape:'html':'UTF-8'}</code>
            &mdash; {l s='quote this in your support ticket.' mod='paypercut'}
            <button type="button" class="btn btn-link paypercut-debug-session__copy" data-paypercut-copy>{l s='Copy' mod='paypercut'}</button>
        </p>
        <p>
            <button type="button" class="btn btn-primary paypercut-debug-session__start">
                {l s='Start debug session' mod='paypercut'}
            </button>
        </p>
    </div>

    {* ── failed ── *}
    <div data-paypercut-state="failed"{if $paypercut_debug_session.state != 'failed'} style="display:none"{/if}>
        <div class="alert alert-danger">
            <span data-paypercut-failed-message>{$paypercut_debug_session.message|escape:'html':'UTF-8'}</span>
        </div>
        <p data-paypercut-reference{if $paypercut_debug_session.trace_id == ''} style="display:none"{/if}>
            {l s='Support reference' mod='paypercut'}
            <code data-paypercut-trace-id>{$paypercut_debug_session.trace_id|escape:'html':'UTF-8'}</code>
            <button type="button" class="btn btn-link paypercut-debug-session__copy" data-paypercut-copy>{l s='Copy' mod='paypercut'}</button>
        </p>
        <p>
            <button type="button" class="btn btn-primary paypercut-debug-session__start">
                {l s='Try again' mod='paypercut'}
            </button>
        </p>
    </div>

    {* ── the events this store actually sent ── *}
    {if $paypercut_debug_session_log|@count > 0}
    <details class="paypercut-debug-session__log" data-paypercut-log>
        <summary>{l s='Show the events sent' mod='paypercut'} ({$paypercut_debug_session_log|@count})</summary>
        <p class="help-block">
            {l s='Exactly what was sent to Paypercut, newest last. The most recent' mod='paypercut'}
            {$paypercut_debug_session_log_max|intval}
            {l s='are kept on this store and cleared when a new session starts.' mod='paypercut'}
        </p>
        <table class="table paypercut-debug-session__log-table">
            <thead>
                <tr>
                    <th>{l s='Time (UTC)' mod='paypercut'}</th>
                    <th>{l s='Event' mod='paypercut'}</th>
                    <th>{l s='Detail' mod='paypercut'}</th>
                </tr>
            </thead>
            <tbody>
            {foreach $paypercut_debug_session_log as $row}
                <tr>
                    <td><code>{$row.occurred_at|escape:'html':'UTF-8'}</code></td>
                    <td><code>{$row.event|escape:'html':'UTF-8'}</code></td>
                    <td>{$row.detail|escape:'html':'UTF-8'}</td>
                </tr>
            {/foreach}
            </tbody>
        </table>
    </details>
    {/if}
</div>

{* ── consent modal ── *}
<div class="paypercut-modal" id="paypercut-debug-session-modal" style="display:none">
    <div class="paypercut-modal__backdrop" data-paypercut-close-debug-session-modal></div>
    <div class="paypercut-modal__dialog" role="dialog" aria-modal="true">
        <div class="paypercut-modal__header">
            <h4>{l s='Start a debug session?' mod='paypercut'}</h4>
        </div>
        <div class="paypercut-modal__body">
            <p>{l s='While the session is running, this store sends the diagnostic information below to Paypercut so support can see what is happening.' mod='paypercut'}</p>
            {include file='./debug_session_disclosure.tpl'}
            <p class="help-block">{l s='The session lasts about 60 minutes and then stops by itself. You can stop it sooner at any time.' mod='paypercut'}</p>
        </div>
        <div class="paypercut-modal__footer">
            <button type="button" class="btn btn-default" data-paypercut-close-debug-session-modal>{l s='Cancel' mod='paypercut'}</button>
            <button type="button" class="btn btn-primary paypercut-debug-session-modal__confirm">{l s='Start session' mod='paypercut'}</button>
        </div>
    </div>
</div>
