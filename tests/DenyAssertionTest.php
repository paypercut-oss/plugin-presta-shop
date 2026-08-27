<?php

/**
 * The privacy boundary: what may never leave the store, whatever the call site.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

Assert::suite('DenyAssertion');

// ── Rule 1: denied field names, at any nesting level ──
foreach (array('api_client_secret', 'telemetry_token', 'api_key', 'nonce', 'authorization', 'webhook_secret', 'password', 'credential') as $key) {
    Assert::true(
        PaypercutTelemetryEvent::isDenied(array($key => 'anything')),
        'denies the field name ' . $key
    );
}

Assert::true(
    PaypercutTelemetryEvent::isDenied(array('error' => array('stack' => array('webhook_secret' => 'x')))),
    'denies a denied key two levels deep'
);

Assert::false(
    PaypercutTelemetryEvent::isDenied(array('order_ref' => 'WC-2026/8891', 'http_status' => 503)),
    'permits ordinary correlation fields'
);

// ── Rule 2: denied value shapes, whatever the field name ──
foreach (array('sk_live_abcdef', 'rejected ppc_live_store_secret', 'whsec_abc', 'eyJhbGciOi.rest') as $value) {
    Assert::true(
        PaypercutTelemetryEvent::isDenied(array('message' => $value)),
        'denies the credential shape in "' . $value . '"'
    );
}

// Bare sk_/pk_ unanchored would bin these whole events for nothing.
foreach (array('disk_usage exceeded', 'backpack_pk_none missing', 'risk_free window elapsed') as $value) {
    Assert::false(
        PaypercutTelemetryEvent::isDenied(array('message' => $value)),
        'permits "' . $value . '"'
    );
}

// ── Rule 3: a Luhn-valid PAN anywhere in the value ──
Assert::true(PaypercutTelemetryEvent::containsCardNumber('Card 4111111111111111 was declined'), 'denies an embedded PAN');
Assert::true(PaypercutTelemetryEvent::containsCardNumber('card 4111 1111 1111 1111 declined'), 'denies a spaced PAN');
Assert::false(PaypercutTelemetryEvent::containsCardNumber('transaction 1234567890123456 not found'), 'permits a non-Luhn 16-digit id');
Assert::false(PaypercutTelemetryEvent::containsCardNumber('expired at 1787250271000'), 'permits a millisecond timestamp');
Assert::false(PaypercutTelemetryEvent::containsCardNumber('amount 4250 refused'), 'permits a short amount');

Assert::true(
    PaypercutTelemetryEvent::isDenied(array('message' => 'Card 4111111111111111 was declined')),
    'the deny assertion screens for a PAN'
);

// ── Rule 4: literal comparison against the store's real credentials ──
Assert::true(
    PaypercutTelemetryEvent::isDenied(array('message' => 'rejected: XYZ-unguessable-format'), array('XYZ-unguessable-format')),
    'denies a secret in a shape nobody anticipated'
);

Assert::false(
    PaypercutTelemetryEvent::isDenied(array('message' => 'anything at all'), array('', null)),
    'an empty credential does not match every string'
);

// ── Rule 5: recursion covers the contract's two levels, and fails closed past them ──
Assert::true(
    PaypercutTelemetryEvent::isDenied(array('error' => array('stack' => array('at sk_live_leak')))),
    'denies a credential shape inside error.stack'
);

// The screen used to give up below error.stack, so a container nested deeper
// than the contract declares passed unread. Anything the screen has not been
// read against is denied rather than skipped.
Assert::true(
    PaypercutTelemetryEvent::isDenied(array('error' => array('stack' => array(array('at' => 'anything at all'))))),
    'denies a structure nested deeper than the screen has been read against'
);

// ── The whole event is dropped, never redacted ──
$secrets = array('sk_live_realsecret');
$safe = array(
    'event' => 'checkout.hosted.redirected',
    'occurred_at' => '2026-08-26T10:00:00Z',
    'attrs' => array('order_status' => 'pending'),
);
$leaky = array(
    'event' => 'api.request_failed',
    'occurred_at' => '2026-08-26T10:00:01Z',
    'attrs' => array('api_context' => 'checkout_create'),
    'error' => array('code' => 'http_401', 'message' => 'key sk_live_realsecret is invalid'),
);

$method = new ReflectionMethod('PaypercutTelemetryQueue', 'assertSafe');
$method->setAccessible(true);
Configuration::$values[Paypercut::CONFIG_API_KEY] = 'sk_live_realsecret';
$screened = $method->invoke(null, array($safe, $leaky));

Assert::same(1, count($screened), 'a tripped assertion removes the whole event');
Assert::same('checkout.hosted.redirected', $screened[0]['event'], 'the clean event survives');
Assert::true(
    count(PrestaShopLogger::$lines) > 0
        && strpos(PrestaShopLogger::$lines[count(PrestaShopLogger::$lines) - 1], 'api.request_failed') !== false,
    'the audit line names the event and nothing else'
);
Assert::false(
    strpos(implode("\n", PrestaShopLogger::$lines), 'sk_live_realsecret') !== false,
    'the audit line never carries the envelope'
);

// `error` is a top-level sibling of `attrs`, so it must be named in the screen.
$errorOnly = array(
    'event' => 'webhook.error',
    'error' => array('code' => 'http_500', 'message' => 'sk_live_realsecret rejected'),
);
Assert::same(0, count($method->invoke(null, array($errorOnly))), 'the screen covers error, not just attrs');
Configuration::$values = array();

// ── Regression: EVERY field on the wire is screened, not a hand-picked subset ──
//
// The screen used to name `attrs` and `error` only, so the correlation fields
// about() writes — fed straight from upstream webhook JSON — carried a PAN or
// the store's own API key to the edge untouched. This walks the envelope as it
// will actually be serialised, so a field added later is covered on the day it
// appears rather than the day someone remembers to widen the screen.

/**
 * @param array $node
 * @param array $prefix
 *
 * @return array  One path per scalar leaf
 */
function paypercut_leaf_paths(array $node, array $prefix = array())
{
    $paths = array();

    foreach ($node as $key => $value) {
        $path = array_merge($prefix, array($key));

        if (is_array($value)) {
            $paths = array_merge($paths, paypercut_leaf_paths($value, $path));

            continue;
        }

        $paths[] = $path;
    }

    return $paths;
}

/**
 * @param array $node
 * @param array $path
 * @param mixed $value
 *
 * @return array
 */
function paypercut_set_path(array $node, array $path, $value)
{
    $key = array_shift($path);

    if (empty($path)) {
        $node[$key] = $value;

        return $node;
    }

    $child = isset($node[$key]) && is_array($node[$key]) ? $node[$key] : array();
    $node[$key] = paypercut_set_path($child, $path, $value);

    return $node;
}

Configuration::$values[Paypercut::CONFIG_API_KEY] = 'sk_live_realsecret';
Configuration::$values[Paypercut::CONFIG_WEBHOOK_SECRET] = 'XYZ-unguessable-webhook-secret';

// One envelope carrying every shape a producer can put on the wire: correlation
// fields, caller attrs, an error map and a nested stack.
$wire = PaypercutTelemetryEvent::failure(
    'webhook.error',
    'http_500',
    array('webhook' => 'payment.updated', 'http_status' => 500),
    new RuntimeException('module blew up')
)
    ->because('threw RuntimeException')
    ->about(array(
        'payment_intent_id' => 'pi_9RTvK2',
        'payment_id' => 'pay_9RTvK2',
        'order_ref' => 'WC-2026/8891',
    ))
    ->envelope(1787250271);

$wire['error']['stack'] = array('somemodule/some.php:12');

Assert::same(1, count($method->invoke(null, array($wire))), 'the representative envelope is not over-screened');

$leaves = paypercut_leaf_paths($wire);
Assert::true(count($leaves) >= 9, 'the walk covers every field of a fully populated envelope');

// Plus two fields nobody has declared yet: a new envelope key must be screened
// the moment it exists, top level or nested.
$leaves[] = array('future_field');
$leaves[] = array('error', 'future_field');

$canaries = array(
    'a Luhn-valid PAN' => '4111111111111111',
    'a spaced PAN' => '4111 1111 1111 1111',
    'the store API key' => 'sk_live_realsecret',
    'the store webhook secret' => 'XYZ-unguessable-webhook-secret',
    'a bearer token' => 'eyJhbGciOiJSUzI1NiJ9.body',
);

foreach ($leaves as $path) {
    foreach ($canaries as $label => $canary) {
        $mutated = paypercut_set_path($wire, $path, $canary);

        Assert::same(
            0,
            count($method->invoke(null, array($mutated))),
            $label . ' at ' . implode('.', $path) . ' is denied'
        );
    }
}

// text() clamps to MAX_TEXT_BYTES before the screen ever sees the value, so a
// secret straddling the cut reaches the assertion as a leading fragment.
$clipped = PaypercutTelemetryEvent::text(str_repeat('x', 240) . 'sk_live_realsecret');
Assert::true(
    strpos($clipped, 'sk_live_realsecret') === false,
    'the clamp really does cut the credential in half'
);
Assert::same(
    0,
    count($method->invoke(null, array(paypercut_set_path($wire, array('order_ref'), $clipped)))),
    'a credential clipped by the byte clamp is still denied'
);

// Below MIN_SECRET_FRAGMENT a leading run is no longer a credential, and binning
// events over six shared characters would cost more than it saves.
$stub = PaypercutTelemetryEvent::text(str_repeat('x', 250) . 'sk_live_realsecret');
Assert::same(
    1,
    count($method->invoke(null, array(paypercut_set_path($wire, array('order_ref'), $stub)))),
    'a fragment too short to be a credential does not bin the event'
);

// Screening the whole envelope must not start binning ordinary events: every
// shape a real producer emits still has to reach the queue.
$producers = array(
    PaypercutTelemetryEvent::of('checkout.hosted.redirected', array('mode' => 'hosted'))
        ->about(array('order_ref' => 'cart_12', 'payment_id' => 'pay_1')),
    PaypercutTelemetryEvent::sessionStarted('dbg_abcdefgh', 'production', 1787250271),
    PaypercutTelemetryEvent::sessionStopped('dbg_abcdefgh', 'merchant_stopped', 4, 0),
    PaypercutTelemetryEvent::environmentSnapshot(array(
        'plugin_version' => '1.3.0',
        'platform_version' => '8.1.7',
        'php_version' => '8.1.27',
        'theme_name' => 'Θέμα Ελλάδα',
        'is_multistore' => false,
        'is_ssl' => true,
    )),
    PaypercutTelemetryEvent::environmentConfiguration(array(
        'checkout_mode' => 'hosted',
        'connection_environment' => 'production',
        'api_key_mode' => 'live',
        'webhook_configured' => true,
    )),
    PaypercutTelemetryEvent::apiFailure(
        'api.request_failed',
        new PaypercutApiException('rejected', 401, array('error' => array('code' => 'token_invalid'), 'trace_id' => 'da74bc')),
        array('api_context' => 'checkout_create')
    ),
);

$envelopes = array();
foreach ($producers as $producer) {
    $envelopes[] = $producer->envelope(1787250271);
}

foreach (PaypercutTelemetryEvent::environmentModules(array('ps_checkout' => '2.0', 'blockcart' => '1.0')) as $inventory) {
    $envelopes[] = $inventory->envelope(1787250271);
}

Configuration::$values[Paypercut::CONFIG_API_KEY] = 'sk_live_realsecret';
Assert::same(
    count($envelopes),
    count($method->invoke(null, $envelopes)),
    'the wider screen does not bin the events the module actually emits'
);

Configuration::$values = array();
