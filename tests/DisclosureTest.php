<?php

/**
 * The merchant is shown one promise; the store listing must repeat it exactly.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

Assert::suite('Disclosure');

$root = dirname(__FILE__) . '/..';
$panel = file_get_contents($root . '/views/templates/admin/debug_session_disclosure.tpl');
$readme = file_get_contents($root . '/README.md');

function paypercut_normalise($text)
{
    $text = preg_replace('/\s+/u', ' ', (string) $text);

    return rtrim(Tools::strtolower(trim($text)), '.;:,');
}

$notShared = 'customer names, email addresses, billing or shipping addresses, order totals, line items, payment card data, the reason text you type when issuing a refund, or any API key, webhook secret or password.';

Assert::true(
    strpos(paypercut_normalise($panel), paypercut_normalise($notShared)) !== false,
    'the consent panel carries the "not shared" sentence'
);
Assert::true(
    strpos(paypercut_normalise($readme), paypercut_normalise($notShared)) !== false,
    'README.md carries the same "not shared" sentence, word for word'
);

// The hosts themselves are resolved from the store's environment, so the shared
// promise is compared up to the host name and no further.
foreach (array(
    'Your API key is never sent to the telemetry service. It is used once, over HTTPS, to obtain a short-lived diagnostic token from',
    'Paypercut keeps this diagnostic data for 30 days.',
) as $sentence) {
    Assert::true(
        strpos(paypercut_normalise($panel), paypercut_normalise($sentence)) !== false,
        'the panel states: ' . $sentence
    );
    Assert::true(
        strpos(paypercut_normalise($readme), paypercut_normalise($sentence)) !== false,
        'README.md states: ' . $sentence
    );
}

// The panel and the modal must read the same copy rather than duplicate it.
$sessionPanel = file_get_contents($root . '/views/templates/admin/debug_session.tpl');
Assert::same(
    2,
    substr_count($sessionPanel, 'debug_session_disclosure.tpl'),
    'the panel and the consent modal include one disclosure file, not two copies'
);

// Starting clears the log server-side; the rendered block must go with it.
$begin = file_get_contents($root . '/classes/telemetry/PaypercutTelemetrySession.php');
Assert::true(strpos($begin, 'PaypercutSentLog::clear();') !== false, 'begin() clears the sent log');
Assert::true(strpos($sessionPanel, 'data-paypercut-log') !== false, 'the sent log block is addressable');

// A released build can reach more than one edge, so the copy must not name one.
Assert::false(
    strpos($panel, 'api.paypercut.io') !== false || strpos($panel, 'telemetry.paypercut.io') !== false,
    'the consent copy resolves its hosts instead of hardcoding them'
);

$script = file_get_contents($root . '/views/js/paypercut-debug-session.js');
Assert::true(
    preg_match('/if \(started\) \{.*?dropSentLog\(\);/s', $script) === 1,
    'the panel drops the stale log block on the idle-to-running transition'
);
