<?php

/**
 * The delivery decision table, exercised without an edge or a database.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

Assert::suite('FlusherDecide');

$accepted = PaypercutTelemetryFlusher::decide(202, 0, 0);
Assert::same('accepted', $accepted['outcome'], '202 is a delivery');
Assert::true($accepted['clears_batch'], '202 settles the batch');
Assert::false($accepted['end_session'], '202 never ends the session');

// 401 never re-mints: a fresh token would outlive the consented window.
$rejected = PaypercutTelemetryFlusher::decide(401, 0, 0);
Assert::same('token_rejected', $rejected['outcome'], '401 rejects the token');
Assert::true($rejected['end_session'], '401 ends the session on the first attempt');
Assert::true($rejected['clears_batch'], '401 settles the batch');

// 413 is a negotiation, not a failure.
$split = PaypercutTelemetryFlusher::decide(413, 0, 3);
Assert::same('split', $split['outcome'], '413 asks for a smaller batch');
Assert::false($split['end_session'], '413 never ends the session, however many times it happens');
Assert::false($split['clears_batch'], '413 keeps the batch parked');
Assert::same(0, $split['retry_in'], '413 does not back off');

// 429 covers infrastructure in front of the edge, and is bounded.
Assert::same(60, PaypercutTelemetryFlusher::decide(429, 0, 0)['retry_in'], '429 without Retry-After waits a minute');
Assert::same(120, PaypercutTelemetryFlusher::decide(429, 120, 0)['retry_in'], '429 honours Retry-After');
Assert::same(900, PaypercutTelemetryFlusher::decide(429, 99999, 0)['retry_in'], 'a hostile Retry-After cannot park the session forever');
Assert::false(PaypercutTelemetryFlusher::decide(429, 0, 3)['end_session'], '429 does not advance the give-up ladder');

// 503/504 are statements about the edge, not about this token.
foreach (array(503, 504) as $status) {
    $unready = PaypercutTelemetryFlusher::decide($status, 0, 3);
    Assert::same('unready', $unready['outcome'], $status . ' is the edge being unready');
    Assert::false($unready['end_session'], $status . ' never ends the session');
    Assert::same(120, $unready['retry_in'], $status . ' retries in two minutes');
}

// 400 is our bug: drop the batch so the queue drains, but still count it.
$poison = PaypercutTelemetryFlusher::decide(400, 0, 0);
Assert::same('poison', $poison['outcome'], '400 is a malformed batch');
Assert::true($poison['clears_batch'], '400 drops the batch so the queue drains');
Assert::false($poison['end_session'], '400 does not end the session on the first attempt');
Assert::true(PaypercutTelemetryFlusher::decide(400, 0, 3)['end_session'], '400 ends the session at the ladder end');

// Anything else, including a transport failure, walks the ladder.
foreach (array(0, 500, 418) as $status) {
    Assert::same('failed', PaypercutTelemetryFlusher::decide($status, 0, 0)['outcome'], $status . ' is a plain failure');
    Assert::false(PaypercutTelemetryFlusher::decide($status, 0, 0)['clears_batch'], $status . ' keeps the batch');
}

Assert::same(30, PaypercutTelemetryFlusher::decide(0, 0, 0)['retry_in'], 'the ladder starts at 30s');
Assert::same(120, PaypercutTelemetryFlusher::decide(0, 0, 1)['retry_in'], 'the ladder climbs to 120s');
Assert::same(300, PaypercutTelemetryFlusher::decide(0, 0, 2)['retry_in'], 'the ladder tops out at 300s');
Assert::same(300, PaypercutTelemetryFlusher::decide(0, 0, 9)['retry_in'], 'the ladder does not run off its end');
Assert::false(PaypercutTelemetryFlusher::decide(0, 0, 2)['end_session'], 'three failures is not yet giving up');
Assert::true(PaypercutTelemetryFlusher::decide(0, 0, 3)['end_session'], 'four consecutive failures ends the session');
