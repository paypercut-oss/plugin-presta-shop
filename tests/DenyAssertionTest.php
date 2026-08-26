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

// ── Rule 5: recursion, exactly two levels ──
Assert::true(
    PaypercutTelemetryEvent::isDenied(array('error' => array('stack' => array('at sk_live_leak')))),
    'denies a credential shape inside error.stack'
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
