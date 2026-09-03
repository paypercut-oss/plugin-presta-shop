<?php

/**
 * One environment value resolves both hosts, or no session at all.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

Assert::suite('Environment');

// ── The pairing: both hosts come from the same value, in the same call ──
$pairs = array(
    'dev' => array('https://api.dev.paypercut.net/', 'https://telemetry.dev.paypercut.net/'),
    'stage' => array('https://api.stage.paypercut.net/', 'https://telemetry.stage.paypercut.net/'),
    'production' => array('https://api.paypercut.io/', 'https://telemetry.paypercut.io/'),
);

foreach ($pairs as $environment => $expected) {
    Assert::same($expected[0], PaypercutEnvironment::apiBaseUri($environment), $environment . ' resolves the API base');
    Assert::same($expected[1], PaypercutEnvironment::telemetryBaseUri($environment), $environment . ' resolves the edge base');
}

// ── An unknown environment: production for payments, no session for telemetry ──
foreach (array('', 'local', 'staging', 'PRODUCTION ', 'nonsense') as $environment) {
    Assert::same(
        'https://api.paypercut.io/',
        PaypercutEnvironment::apiBaseUri($environment),
        'the API base falls back to production for "' . $environment . '"'
    );
}

foreach (array('', 'local', 'staging', 'nonsense') as $environment) {
    Assert::same(
        '',
        PaypercutEnvironment::telemetryBaseUri($environment),
        'the edge base refuses to guess for "' . $environment . '"'
    );
}

// Case and whitespace are normalised rather than rejected.
Assert::same('production', PaypercutEnvironment::normalize(' Production '), 'normalises case and whitespace');
Assert::same('', PaypercutEnvironment::normalize('local'), 'local is not a supported environment');

// ── The destination allow-list, which guards a credential in transit ──
$rejected = array(
    'https://paypercut.io.evil.com/',
    'https://notpaypercut.io/',
    'https://paypercut.io.co/',
    'http://api.paypercut.io/',
    'https://paypercut.com/',
    'ftp://api.paypercut.io/',
    '',
    'not a url',
);

foreach ($rejected as $url) {
    Assert::same('', PaypercutEnvironment::allowedPaypercutBase($url), 'rejects "' . $url . '"');
}

$accepted = array(
    'https://api.paypercut.io' => 'https://api.paypercut.io/',
    'https://api.paypercut.io/' => 'https://api.paypercut.io/',
    'https://telemetry.dev.paypercut.net/' => 'https://telemetry.dev.paypercut.net/',
    'https://paypercut.io' => 'https://paypercut.io/',
);

foreach ($accepted as $url => $expected) {
    Assert::same($expected, PaypercutEnvironment::allowedPaypercutBase($url), 'accepts "' . $url . '"');
}

// Every host the released map can reach has to survive the allow-list.
foreach (PaypercutEnvironment::API_BASE_URIS as $url) {
    Assert::same($url, PaypercutEnvironment::allowedPaypercutBase($url), 'the API map entry ' . $url . ' is allow-listed');
}

foreach (PaypercutEnvironment::TELEMETRY_BASE_URIS as $url) {
    Assert::same($url, PaypercutEnvironment::allowedPaypercutBase($url), 'the edge map entry ' . $url . ' is allow-listed');
}

// ── The host-side override: named non-production environments only ──
// Defined here rather than in the bootstrap so the assertions above run against
// a released build's behaviour first.
define('PAYPERCUT_TELEMETRY_BASE_URI', 'https://telemetry.dev.paypercut.net/');

foreach (array('', 'garbage', 'local', 'staging') as $environment) {
    Assert::same(
        '',
        PaypercutEnvironment::telemetryBaseUri($environment),
        'the override does not hand "' . $environment . '" an edge the mint host would not follow'
    );
}

Assert::same(
    'https://telemetry.paypercut.io/',
    PaypercutEnvironment::telemetryBaseUri('production'),
    'the override cannot retarget a live store'
);

Assert::same(
    'https://telemetry.dev.paypercut.net/',
    PaypercutEnvironment::telemetryBaseUri('dev'),
    'the override applies on dev'
);
