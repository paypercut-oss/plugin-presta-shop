<?php

/**
 * The queue caps and the batch split, which bound what a busy store pays.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

Assert::suite('Queue');

function paypercut_test_envelopes($count, $padding = 0)
{
    $envelopes = array();

    for ($i = 0; $i < $count; ++$i) {
        $envelopes[] = array(
            'event' => 'test.event',
            'occurred_at' => '2026-08-26T10:00:00Z',
            'attrs' => array('index' => $i, 'pad' => str_repeat('x', $padding)),
        );
    }

    return $envelopes;
}

// ── Capping drops the OLDEST first ──
$capped = PaypercutTelemetryQueue::cap(paypercut_test_envelopes(250));
Assert::same(200, count($capped['envelopes']), 'the queue is capped at MAX_QUEUE_EVENTS');
Assert::same(50, $capped['dropped'], 'the overflow is counted');
Assert::same(50, $capped['envelopes'][0]['attrs']['index'], 'the oldest entries are the ones dropped');

// The byte cap is enforced too, and never empties the queue entirely.
$fat = PaypercutTelemetryQueue::cap(paypercut_test_envelopes(20, 8000));
Assert::true(PaypercutTelemetryQueue::bytes($fat['envelopes']) <= 65536, 'the byte cap is enforced');
Assert::true(count($fat['envelopes']) >= 1, 'capping never empties the queue');

$single = PaypercutTelemetryQueue::cap(paypercut_test_envelopes(1, 100000));
Assert::same(1, count($single['envelopes']), 'a single oversized envelope is kept rather than binned');

// ── Splitting neither drops nor reorders ──
$input = paypercut_test_envelopes(120);
$split = PaypercutTelemetryQueue::splitBatch($input, 16384, 50);

Assert::same(
    $input,
    array_merge($split['batch'], $split['remainder']),
    'batch + remainder is exactly the input, in order'
);
Assert::true(count($split['batch']) <= 50, 'the batch respects the event cap');
Assert::true(count($split['batch']) >= 1, 'the batch always takes at least one envelope');

// A single oversized envelope must not wedge the queue forever.
$oversized = array_merge(paypercut_test_envelopes(1, 40000), paypercut_test_envelopes(3));
$wedge = PaypercutTelemetryQueue::splitBatch($oversized, 16384, 50);
Assert::same(1, count($wedge['batch']), 'an oversized envelope is taken alone');
Assert::same(3, count($wedge['remainder']), 'the rest stays behind it');

// Byte budgets are honoured once more than one envelope fits.
$budgeted = PaypercutTelemetryQueue::splitBatch(paypercut_test_envelopes(50, 1000), 4096, 50);
Assert::true(PaypercutTelemetryQueue::bytes($budgeted['batch']) <= 4096 || count($budgeted['batch']) === 1, 'the byte budget bounds the batch');
