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
foreach (array(
    'api_client_secret', 'telemetry_token', 'api_key', 'nonce', 'authorization',
    'webhook_secret', 'password', 'credential', 'auth', 'auth_token', 'x-auth',
    'csrf-token', 'access_token', 'client_secret', 'SECRET',
) as $key) {
    Assert::true(
        PaypercutTelemetryEvent::isDenied(array($key => 'anything')),
        'denies the field name ' . $key
    );
}

// …as whole words. The module inventory turns merchant slugs into attribute
// keys, and a bare substring match dropped Authorize.net from the one artefact
// whose only purpose is diagnosing a conflict with another payment module.
foreach (array('authorizeaim', 'authorizenet_aim', 'tokenizer_pro', 'nonceguard', 'author', 'authorised', 'monkey') as $key) {
    Assert::false(
        PaypercutTelemetryEvent::isDenied(array($key => '1.0.0')),
        'permits the field name ' . $key
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
Assert::false(PaypercutTelemetryEvent::containsCardNumber('expired at 1787250271000'), 'permits a millisecond timestamp');
Assert::false(PaypercutTelemetryEvent::containsCardNumber('amount 4250 refused'), 'permits a short amount');

// The scan slides across the whole digit run: anchoring it meant 40 filler
// digits in front of a PAN passed, which is not a redaction.
Assert::true(
    PaypercutTelemetryEvent::containsCardNumber(str_repeat('7', 40) . '4111111111111111'),
    'denies a PAN behind a longer digit run'
);
Assert::true(
    PaypercutTelemetryEvent::containsCardNumber('4111111111111111' . str_repeat('7', 40)),
    'denies a PAN in front of a longer digit run'
);
Assert::true(
    PaypercutTelemetryEvent::containsCardNumber('99887766554433 4012888888881881 00112233445566'),
    'denies a PAN between two other long digit runs'
);

// The price of sliding used to be that a long digit run that is not a card
// number very often contains a Luhn-valid window: 10 windows in a 16-digit run,
// each about one in ten to pass Luhn by chance, denied 65.8% of random 16-digit
// identifiers (329/500 measured). A window now has to start with a prefix an
// issuer actually assigns, at a length that issuer really uses, which brings
// that to 7.0% (35/500) while every brand below is still caught.
Assert::false(
    PaypercutTelemetryEvent::containsCardNumber('transaction 1234567890123456 not found'),
    'a 16-digit id under no issuer prefix is not mistaken for a card'
);

foreach (array(
    'visa-13' => '4222222222222',
    'visa-16' => '4012888888881881',
    'visa-19' => '4111111111111111110',
    'mastercard-16' => '5555555555554444',
    'mastercard-2-series' => '2223003122003222',
    'amex-15' => '378282246310005',
    'discover-16' => '6011111111111117',
    'diners-14' => '30569309025904',
    'jcb-16' => '3530111333300000',
    'unionpay-16' => '6250947000000014',
    'maestro-16' => '6759649826438453',
) as $brand => $pan) {
    Assert::true(
        PaypercutTelemetryEvent::containsCardNumber('Card ' . $pan . ' declined'),
        'denies a ' . $brand . ' PAN'
    );

    // Filler digits on either side are not a redaction, so the scan still slides.
    Assert::true(
        PaypercutTelemetryEvent::containsCardNumber(str_repeat('7', 40) . $pan . str_repeat('7', 40)),
        'denies a ' . $brand . ' PAN buried in a longer digit run'
    );
}

// Group separators: space and hyphen alone let a dotted or slashed PAN through.
foreach (array(' ', '-', '.', '/', '_', ',', ':') as $separator) {
    $formatted = '4111' . $separator . '1111' . $separator . '1111' . $separator . '1111';

    Assert::true(
        PaypercutTelemetryEvent::containsCardNumber('card ' . $formatted . ' declined'),
        'denies a PAN grouped with "' . $separator . '"'
    );
}

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

/**
 * Rename the leaf at $path to $key, keeping its value.
 *
 * @param array  $node
 * @param array  $path
 * @param string $key
 *
 * @return array
 */
function paypercut_rekey_path(array $node, array $path, $key)
{
    $name = array_shift($path);

    if (empty($path)) {
        $value = isset($node[$name]) ? $node[$name] : 'x';
        unset($node[$name]);
        $node[$key] = $value;

        return $node;
    }

    $child = isset($node[$name]) && is_array($node[$name]) ? $node[$name] : array();
    $node[$name] = paypercut_rekey_path($child, $path, $key);

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

        // Keys are serialised beside their values. The screen used to test a key
        // against the field-NAME regex only, so a PAN or the store's own key
        // used as an attribute name went out whole.
        Assert::same(
            0,
            count($method->invoke(null, array(paypercut_rekey_path($wire, $path, $canary)))),
            $label . ' as the KEY at ' . implode('.', $path) . ' is denied'
        );
    }
}

// The same, through a real producer rather than a hand-built envelope: cleanAttrs
// and environmentModules are the two places a key is not a literal.
foreach ($canaries as $label => $canary) {
    Assert::same(
        0,
        count($method->invoke(null, array(PaypercutTelemetryEvent::of('checkout.started', array($canary => 'x'))->envelope(1787250271)))),
        $label . ' as an attribute key from of() is denied'
    );
}

$poisoned = PaypercutTelemetryEvent::environmentModules(array('ps_checkout' => '2.0', '4111111111111111' => '1.0', 'blockcart' => '1.0'));
$inventory = $method->invoke(null, array($poisoned[0]->envelope(1787250271)));
Assert::same(1, count($inventory), 'a poisoned module slug does not bin the inventory event');
Assert::same(1, $inventory[0]['attrs']['omitted'], 'the poisoned slug is the one thing omitted');
Assert::true(isset($inventory[0]['attrs']['ps_checkout']), 'the ordinary slugs still report');

// ── The screen runs BEFORE the clamp ──
//
// text() used to clamp to MAX_TEXT_BYTES and let the assertion read the
// survivor, so a PAN starting at byte 241 went out as 15 of its 16 digits —
// enough to complete by Luhn, which is no redaction at all. The raw value is
// screened first and the field is replaced by a token the assertion denies.
foreach (array(241, 244, 250) as $pad) {
    $raw = str_repeat('x', $pad) . '4111111111111111';

    Assert::same(
        PaypercutTelemetryEvent::DENIED_MARKER,
        PaypercutTelemetryEvent::text($raw),
        'a PAN straddling the clamp is screened before the cut, pad ' . $pad
    );

    Assert::same(
        0,
        count($method->invoke(null, array(
            PaypercutTelemetryEvent::of('webhook.received', array('note' => $raw))->envelope(1787250271),
        ))),
        'an attribute whose raw value straddles the clamp is denied, pad ' . $pad
    );

    Assert::same(
        0,
        count($method->invoke(null, array(
            PaypercutTelemetryEvent::of('webhook.received')->about(array('order_ref' => $raw))->envelope(1787250271),
        ))),
        'a correlation id whose raw value straddles the clamp is denied, pad ' . $pad
    );
}

// The store's own credential, in a shape no pattern anticipates, at every
// alignment the clamp can leave behind.
foreach (array(2, 6, 11, 12, 18) as $surviving) {
    $raw = str_repeat('x', 256 - $surviving) . 'sk_live_realsecret';

    Assert::same(
        0,
        count($method->invoke(null, array(
            PaypercutTelemetryEvent::of('webhook.received', array('note' => $raw))->envelope(1787250271),
        ))),
        'a credential straddling the clamp is denied with ' . $surviving . ' characters surviving'
    );
}

// The queue's own screen keeps MIN_SECRET_FRAGMENT: at that gate the value has
// already been bounded, and binning events over six shared characters would
// cost more than it saves.
Assert::same(
    1,
    count($method->invoke(null, array(paypercut_set_path($wire, array('order_ref'), 'xxxxxxsk_liv')))),
    'a fragment too short to be a credential does not bin the event'
);

// …and it is read at every offset, not just the head. The comparison used to
// test the value's TAIL against the secret's HEAD, so a slice out of the middle
// of the store's API key went out.
Configuration::$values = array();
$opaque = 'ZmFrZXN0b3JlYXBpa2V5MTIzNDU2Nzg5MEFCQ0RFRkdISUo';
Configuration::$values[Paypercut::CONFIG_API_KEY] = $opaque;

foreach (array(array(0, 20), array(5, 20), array(12, 20), array(27, 20), array(35, 12)) as $slice) {
    list($offset, $length) = $slice;

    Assert::same(
        0,
        count($method->invoke(null, array(
            PaypercutTelemetryEvent::of('webhook.received', array('note' => 'rejected ' . substr($opaque, $offset, $length)))->envelope(1787250271),
        ))),
        'a ' . $length . '-character slice of the API key at offset ' . $offset . ' is denied'
    );
}

Assert::same(
    1,
    count($method->invoke(null, array(
        PaypercutTelemetryEvent::of('webhook.received', array('note' => substr($opaque, 9, 11)))->envelope(1787250271),
    ))),
    'eleven shared characters are still too few to bin the event'
);

Configuration::$values = array();
Configuration::$values[Paypercut::CONFIG_API_KEY] = 'sk_live_realsecret';
Configuration::$values[Paypercut::CONFIG_WEBHOOK_SECRET] = 'XYZ-unguessable-webhook-secret';

// ── Non-string scalars are screened too ──
//
// The screen ran on is_string() alone and cleanAttrs() passes ints and floats
// through untouched, so a PAN passed as a PHP INTEGER — it fits in 64 bits —
// was serialised and shipped whole.
Assert::same(
    0,
    count($method->invoke(null, array(
        PaypercutTelemetryEvent::of('checkout.started', array('amount' => 4111111111111111))->envelope(1787250271),
    ))),
    'a PAN passed as an int is denied'
);

Assert::same(
    0,
    count($method->invoke(null, array(
        PaypercutTelemetryEvent::of('checkout.started', array('amount' => 4111111111111111.0))->envelope(1787250271),
    ))),
    'a PAN passed as a float is denied'
);

Assert::same(
    0,
    count($method->invoke(null, array(
        array('event' => 'checkout.started', 'error' => array('stack' => array('n' => 4111111111111111))),
    ))),
    'a PAN as an int two levels deep is denied'
);

Assert::same(
    1,
    count($method->invoke(null, array(
        PaypercutTelemetryEvent::of('webhook.received', array(
            'http_status' => 503,
            'attempt' => 2,
            'duration_ms' => 1421,
            'occurred_ms' => 1787250271000,
            'is_partial' => true,
            'duplicate' => false,
        ))->envelope(1787250271),
    ))),
    'ordinary ints, a millisecond timestamp and bools still ship'
);

// ── Correlation ids are bounded to a reference charset, not free text ──
$hostile = array(
    'markup' => '<script>alert(1)</script>',
    'a URL' => 'https://evil.example/collect?a=1',
    'an RTL override' => "order\xE2\x80\xAE9188",
    'a quoted statement' => "0'; DROP--",
    'an email address' => 'jane@example.com',
    // Reference-shaped, and a path: order_ref is quoted back in support tools.
    'a path traversal' => '../../etc/passwd',
    'a relative segment' => 'a/../b',
    'an absolute path' => '/etc/passwd',
    'a trailing separator' => 'x/',
);

foreach ($hostile as $label => $value) {
    $envelope = PaypercutTelemetryEvent::of('webhook.received')
        ->about(array('payment_id' => $value, 'payment_intent_id' => $value, 'order_ref' => $value))
        ->envelope(1787250271);

    $shipped = $method->invoke(null, array($envelope));

    Assert::true(
        count($shipped) === 0
            || (!isset($shipped[0]['payment_id']) && !isset($shipped[0]['payment_intent_id']) && !isset($shipped[0]['order_ref'])),
        $label . ' never reaches the wire as a correlation id'
    );
}

// …and the references this module really builds stay lossless: a PrestaShop
// order reference, cart_/order_ fallbacks, Paypercut ids, and a merchant
// numbering scheme with a slash.
foreach (array('XKBKNABJK', 'cart_12', 'order_9931', 'INV/2026-0001', 'pay_3d9f8c2b1a', 'pi_9RTvK2') as $reference) {
    $shipped = $method->invoke(null, array(
        PaypercutTelemetryEvent::of('webhook.received')
            ->about(array('order_ref' => $reference, 'payment_id' => $reference))
            ->envelope(1787250271),
    ));

    Assert::same(1, count($shipped), 'the reference ' . $reference . ' still delivers');
    Assert::same($reference, $shipped[0]['order_ref'], 'the reference ' . $reference . ' is carried intact');
}

Assert::same(
    0,
    count($method->invoke(null, array(
        PaypercutTelemetryEvent::of('webhook.received')->about(array('order_ref' => '4111111111111111'))->envelope(1787250271),
    ))),
    'a bare PAN is reference-shaped, and still denied'
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
