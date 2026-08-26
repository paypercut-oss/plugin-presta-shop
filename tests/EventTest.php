<?php

/**
 * The named constructors and the bounds they enforce.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

Assert::suite('Event');

// ── The snapshot constructors walk their OWN schema ──
$envelope = PaypercutTelemetryEvent::environmentSnapshot(array(
    'plugin_version' => '1.3.0',
    'api_client_secret' => 'sk_live_secret',
    'webhook_secret' => 'whsec_secret',
))->envelope(1787250271);

Assert::same(
    array('plugin_version' => '1.3.0'),
    $envelope['attrs'],
    'a credential passed into the snapshot is not read out of it'
);

$configuration = PaypercutTelemetryEvent::environmentConfiguration(array(
    'checkout_mode' => 'hosted',
    'webhook_secret' => 'whsec_secret',
    'webhook_configured' => true,
))->envelope(1787250271);

Assert::same(
    array('checkout_mode' => 'hosted', 'webhook_configured' => true),
    $configuration['attrs'],
    'the configuration snapshot reports presence, never the value'
);

// ── An API failure drops the platform's prose ──
$exception = new PaypercutApiException(
    "The provided access token 'sk_test_probe' is invalid.",
    401,
    array('error' => array('type' => 'invalid_request_error', 'code' => 'token_invalid', 'param' => 'api_key'), 'trace_id' => 'da74bc')
);

$failure = PaypercutTelemetryEvent::apiFailure('api.request_failed', $exception, array('api_context' => 'checkout_create'))
    ->envelope(1787250271);

Assert::false(isset($failure['error']['message']), 'api_failure drops error.message');
Assert::same('http_401', $failure['error']['code'], 'api_failure names the status');
Assert::same('invalid_request_error', $failure['error']['type'], 'api_failure keeps the error type');
Assert::same('token_invalid', $failure['attrs']['api_code'], 'api_code carries the diagnosis');
Assert::same('da74bc', $failure['attrs']['trace_id'], 'trace_id carries the reference');
Assert::same(401, $failure['attrs']['http_status'], 'http_status stays an int');

// A message this module authored is the diagnosis and stays.
$authored = PaypercutTelemetryEvent::failure('webhook.registration_failed', 'rejected')
    ->because('threw RuntimeException')
    ->envelope(1787250271);
Assert::same('threw RuntimeException', $authored['error']['message'], 'a message we wrote survives');

// ── The wire envelope ──
Assert::same('2026-08-20T18:24:31Z', $envelope['occurred_at'], 'occurred_at is an RFC3339 string in UTC');

$bare = PaypercutTelemetryEvent::of('webhook.deleted')->envelope(1787250271);
Assert::false(isset($bare['attrs']), 'an empty attrs map is omitted rather than sent as []');
Assert::false(isset($bare['error']), 'an empty error map is omitted');

$correlated = PaypercutTelemetryEvent::of('payment.succeeded')
    ->about(array('order_ref' => 'ABCDEFGHI', 'payment_id' => 'pay_1', 'nothing' => 'dropped'))
    ->envelope(1787250271);
Assert::same('ABCDEFGHI', $correlated['order_ref'], 'order_ref is a top-level correlation field');
Assert::false(isset($correlated['nothing']), 'about() only accepts the three declared keys');

// ── Bounds ──
Assert::same(256, strlen(PaypercutTelemetryEvent::text(str_repeat('a', 400))), 'text() clamps on a byte budget');
Assert::same('Θέμα Ελλάδα', PaypercutTelemetryEvent::text('Θέμα Ελλάδα'), 'UTF-8 survives; only control characters go');
Assert::same('abc', PaypercutTelemetryEvent::text("a\x00b\x1Fc"), 'control characters are stripped');
Assert::true(strlen(PaypercutTelemetryEvent::text(str_repeat('日', 200))) <= 256, 'a CJK string is cut on bytes, not codepoints');

Assert::same('checkout_create', PaypercutTelemetryEvent::identifier('checkout_create'), 'an identifier-shaped value passes');
Assert::same('', PaypercutTelemetryEvent::identifier('jane@example.com'), 'an email is dropped, not mangled');
Assert::same('', PaypercutTelemetryEvent::identifier('12 Sunset Road'), 'an address is dropped');
Assert::same('', PaypercutTelemetryEvent::identifier(str_repeat('a', 65)), 'an over-long value is dropped');

// Booleans and ints pass through intact; containers do not.
$attrs = PaypercutTelemetryEvent::of('checkout.blocks.fell_back', array(
    'duplicate' => false,
    'http_status' => 503,
    'payload' => array('nested' => 'value'),
))->envelope(1787250271);
Assert::same(false, $attrs['attrs']['duplicate'], 'false stays false');
Assert::same(503, $attrs['attrs']['http_status'], 'an int stays an int');
Assert::false(isset($attrs['attrs']['payload']), 'a container is not a scalar diagnostic');

// ── Stack scrubbing and origin ──
Assert::same(
    'other-module/other.php',
    PaypercutTelemetryEvent::relativePath('/var/www/html/modules/other-module/other.php'),
    'a module path is relative'
);
Assert::same(
    '[external]',
    PaypercutTelemetryEvent::relativePath('/home/merchant-account/secret/place.php'),
    'a path outside every known root becomes [external]'
);
Assert::same(
    array('origin' => 'plugin', 'origin_plugin' => 'other-module'),
    PaypercutTelemetryEvent::origin(array('/var/www/html/modules/paypercut/classes/PaypercutApi.php', '/var/www/html/modules/other-module/other.php')),
    'the first frame outside our own module names the culprit'
);
Assert::same(
    array('origin' => 'theme'),
    PaypercutTelemetryEvent::origin(array('/var/www/html/themes/classic/templates/x.tpl')),
    'a theme frame is attributed to the theme'
);
Assert::same(
    array('origin' => 'core'),
    PaypercutTelemetryEvent::origin(array('/var/www/html/classes/Order.php')),
    'anything else is core'
);
Assert::same(
    array('origin' => 'paypercut'),
    PaypercutTelemetryEvent::origin(array('/var/www/html/modules/paypercut/paypercut.php')),
    'no foreign frame means it is ours'
);

// ── A fatal never carries the trace or an absolute path ──
$fatal = PaypercutTelemetryEvent::fatal(
    "Uncaught Error: boom in /var/www/html/modules/other-module/other.php:12\nStack trace:\n#0 /var/www/html/index.php(1)",
    '/var/www/html/modules/other-module/other.php',
    12,
    E_ERROR
)->envelope(1787250271);

Assert::false(strpos($fatal['error']['message'], 'Stack trace:') !== false, 'the inlined trace is cut off');
Assert::false(strpos($fatal['error']['message'], '/var/www/html') !== false, 'absolute paths are stripped from the message');
Assert::same(array('other-module/other.php:12'), $fatal['error']['stack'], 'the stack is one relative frame');
Assert::same('other-module', $fatal['attrs']['origin_plugin'], 'the fatal names the module that died');

// ── The plugin inventory is chunked so nothing is silently truncated ──
$modules = array();
for ($i = 0; $i < 70; ++$i) {
    $modules['module' . str_pad((string) $i, 2, '0', STR_PAD_LEFT)] = '1.0.' . $i;
}

$seen = array();
foreach (PaypercutTelemetryEvent::environmentModules($modules) as $event) {
    $attrs = $event->envelope(1787250271);
    foreach ($attrs['attrs'] as $key => $value) {
        if ($key !== 'module_count' && $key !== 'chunk') {
            $seen[$key] = $value;
        }
    }
}

Assert::same(70, count($seen), 'every module appears exactly once across the chunks');
Assert::same($modules, $seen, 'the inventory is carried intact');
