<?php

/**
 * Every event a call site emits must be documented, and the payment-outcome
 * paths must still report.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

Assert::suite('EventCatalog');

$root = dirname(__FILE__) . '/..';
$docs = file_get_contents($root . '/docs/telemetry.md');

$sources = array(
    'paypercut.php',
    'classes/PaypercutApi.php',
    'controllers/front/checkout.php',
    'controllers/front/redirect.php',
    'controllers/front/validation.php',
    'controllers/front/webhook.php',
    'controllers/admin/AdminPaypercutController.php',
);

$names = array();

foreach ($sources as $source) {
    $code = file_get_contents($root . '/' . $source);

    if (preg_match_all("/PaypercutTelemetryEvent::(?:of|failure|apiFailure)\(\s*'([a-z0-9_.]+)'/", $code, $matches)) {
        foreach ($matches[1] as $name) {
            $names[$name] = true;
        }
    }
}

Assert::true(count($names) > 20, 'the call sites emit a meaningful catalogue');

foreach (array_keys($names) as $name) {
    Assert::true(
        strpos($docs, '`' . $name . '`') !== false,
        'docs/telemetry.md documents ' . $name
    );
}

// Each of these decides whether a shopper's money became an order. Each was
// silent before, which is why "it just did nothing" was unanswerable.
$paymentPaths = array(
    'controllers/front/redirect.php',
    'controllers/front/checkout.php',
    'controllers/front/validation.php',
    'controllers/front/webhook.php',
    'controllers/admin/AdminPaypercutController.php',
    'classes/PaypercutApi.php',
);

foreach ($paymentPaths as $path) {
    Assert::true(
        strpos(file_get_contents($root . '/' . $path), 'PaypercutTelemetryRecorder::record(') !== false,
        $path . ' still reports what it decided'
    );
}
