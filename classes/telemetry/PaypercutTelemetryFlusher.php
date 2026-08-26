<?php

/**
 * Delivers queued diagnostic events to the telemetry edge.
 *
 * Runs only from authenticated back-office requests: the panel's status poll,
 * the Stop handler, and one guarded backstop on back-office page loads. Never
 * from a storefront request, never from a webhook, never from cron.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaypercutTelemetryFlusher
{
    /** Backoff ladder applied after consecutive delivery failures, in seconds. */
    const BACKOFF_SECONDS = array(30, 120, 300);

    /** @var PaypercutEdgeClient */
    private $client;

    /**
     * @param PaypercutEdgeClient|null $client
     */
    public function __construct($client = null)
    {
        $this->client = $client instanceof PaypercutEdgeClient ? $client : new PaypercutEdgeClient();
    }

    /**
     * Attempt to deliver at most one batch.
     *
     * @return bool  Whether a delivery was attempted
     */
    public function flushOnce()
    {
        $record = PaypercutTelemetrySession::record();

        if (!isset($record['status']) || $record['status'] !== 'active') {
            return false;
        }

        if ((int) (isset($record['expires_at']) ? $record['expires_at'] : 0) <= time()) {
            return false;
        }

        $runtime = PaypercutTelemetrySession::runtime();

        if ((int) (isset($runtime['next_attempt_at']) ? $runtime['next_attempt_at'] : 0) > time()) {
            return false;
        }

        if (!PaypercutTelemetrySession::claimFlushLock()) {
            return false;
        }

        try {
            return $this->deliver($record);
        } finally {
            PaypercutTelemetrySession::releaseFlushLock();
        }
    }

    /**
     * @param array $record
     *
     * @return bool
     */
    private function deliver(array $record)
    {
        $maxEvents = self::maxEvents();

        // A parked batch always drains first, so a retry never reorders delivery.
        $batch = PaypercutTelemetryQueue::inflight();

        if (empty($batch)) {
            $batch = PaypercutTelemetryQueue::takeBatch(PaypercutTelemetrySession::MAX_BATCH_BYTES, $maxEvents);
        }

        if (empty($batch)) {
            return false;
        }

        $token = PaypercutTelemetrySession::token();

        if ($token === '') {
            PaypercutTelemetrySession::end('token_lost');

            return false;
        }

        $edgeBase = (string) (isset($record['edge_base']) ? $record['edge_base'] : '');

        if ($edgeBase === '') {
            PaypercutTelemetrySession::end('environment_changed');

            return false;
        }

        $client = self::clientIdentity();
        $split = PaypercutTelemetryQueue::splitBatch($batch, self::eventsBudget($client), $maxEvents);
        $head = $split['batch'];
        $tail = $split['remainder'];

        $body = json_encode(array(
            'client' => $client,
            'events' => $head,
        ));

        if (!is_string($body)) {
            PaypercutTelemetryQueue::clearInflight();
            $this->countDropped(count($batch), 'encode_failed');

            return false;
        }

        $result = $this->client->send($edgeBase, $token, $body);

        return $this->settle(
            (int) $result['status'],
            (int) $result['retry_after'],
            $head,
            $tail,
            is_array($result['body']) ? $result['body'] : array()
        );
    }

    /**
     * Decide what an edge response means, with no side effects.
     *
     * Kept pure and separate from settle() so the whole branch table — the
     * give-up ladder included — can be exercised without an edge, a database or
     * a running session.
     *
     * @param int $status      HTTP status, or 0 for a transport failure
     * @param int $retryAfter  Value of the Retry-After header, 0 when absent
     * @param int $failures    Consecutive failures BEFORE this attempt
     *
     * @return array  { outcome, end_session, retry_in, clears_batch }
     */
    public static function decide($status, $retryAfter, $failures)
    {
        $status = (int) $status;

        if ($status === 202) {
            return self::outcome('accepted', false, 0, true);
        }

        if ($status === 401) {
            // Never re-mint. Every mint issues a token with a fresh expiry and
            // nothing can revoke one, so a re-mint would leave a credential
            // valid past the window the merchant agreed to.
            return self::outcome('token_rejected', true, 0, true);
        }

        if ($status === 413) {
            // Not a failure — the batch is being reshaped. A backoff rung would
            // punish a successful negotiation, and a step towards giving up
            // would end a session over a batch we can simply cut in half.
            return self::outcome('split', false, 0, false);
        }

        // Nothing in the edge answers 429; this covers infrastructure in front
        // of it. A hostile Retry-After must not park the session forever.
        if ($status === 429) {
            return self::outcome('throttled', false, (int) $retryAfter > 0 ? min((int) $retryAfter, 900) : 60, false);
        }

        if ($status === 503 || $status === 504) {
            // "My verification keys aren't ready" is a statement about the edge,
            // not about this token. Ending the session on a rolling deploy would
            // be a one-way door: there is no re-mint, so the merchant would have
            // to consent all over again.
            return self::outcome('unready', false, 120, false);
        }

        $attempt = (int) $failures + 1;
        $giveUp = $attempt >= PaypercutTelemetrySession::MAX_CONSECUTIVE_SEND_FAILURES;
        $ladder = self::BACKOFF_SECONDS;
        $retryIn = $ladder[min($attempt, count($ladder)) - 1];

        // Our bug, not the merchant's: drop the batch so the queue drains, but
        // still count it. An edge that rejects every batch we build makes the
        // session useless, and it should end rather than burn an hour silently
        // incrementing a dropped counter.
        if ($status === 400) {
            return self::outcome('poison', $giveUp, $retryIn, true);
        }

        return self::outcome('failed', $giveUp, $retryIn, false);
    }

    /**
     * @return array
     */
    private static function outcome($outcome, $endSession, $retryIn, $clearsBatch)
    {
        return array(
            'outcome' => $outcome,
            'end_session' => (bool) $endSession,
            'retry_in' => (int) $retryIn,
            'clears_batch' => (bool) $clearsBatch,
        );
    }

    /**
     * Apply the edge's answer to the parked batch.
     *
     * @param int   $status
     * @param int   $retryAfter
     * @param array $head  The events actually POSTed
     * @param array $tail  What stayed parked behind them
     * @param array $body  The edge's decoded response
     *
     * @return bool
     */
    private function settle($status, $retryAfter, array $head, array $tail, array $body)
    {
        $runtime = PaypercutTelemetrySession::runtime();
        $failures = (int) (isset($runtime['consecutive_edge_failures']) ? $runtime['consecutive_edge_failures'] : 0);
        $decision = self::decide($status, $retryAfter, $failures);
        $events = count($head);

        if ($decision['outcome'] === 'split') {
            return $this->resize($head, $tail, $body);
        }

        if ($decision['clears_batch']) {
            // Only the delivered head is settled; anything behind it stays parked.
            PaypercutTelemetryQueue::retainInflight($tail);
        }

        if ($decision['outcome'] === 'accepted') {
            // The edge drops malformed events individually and still answers 202,
            // so the counts it returns are the only honest accounting available.
            $accepted = isset($body['accepted']) ? (int) $body['accepted'] : $events;
            $dropped = isset($body['dropped']) ? (int) $body['dropped'] : 0;

            PaypercutSentLog::append($head);

            PaypercutTelemetrySession::updateRuntime(array(
                'events_sent' => (int) (isset($runtime['events_sent']) ? $runtime['events_sent'] : 0) + $accepted,
                'consecutive_edge_failures' => 0,
                'next_attempt_at' => 0,
                'last_error' => '',
            ));

            if ($dropped > 0) {
                $this->countDropped($dropped, 'edge_dropped');
            }

            return true;
        }

        if ($decision['outcome'] === 'poison') {
            $this->countDropped($events, 'malformed_batch');
        }

        if ($decision['end_session']) {
            if ($decision['outcome'] !== 'token_rejected') {
                PaypercutTelemetrySession::audit('Telemetry: giving up on delivery', array(
                    'status' => $status,
                    'failures' => $failures + 1,
                ));
            }

            PaypercutTelemetrySession::end($decision['outcome'] === 'token_rejected' ? 'edge_rejected' : 'send_failed');

            return true;
        }

        $countsAsFailure = in_array($decision['outcome'], array('failed', 'poison'), true);

        PaypercutTelemetrySession::updateRuntime(array(
            'consecutive_edge_failures' => $countsAsFailure ? $failures + 1 : $failures,
            'next_attempt_at' => time() + $decision['retry_in'] + rand(0, 30),
            'last_error' => 'edge_' . $status,
        ));

        return true;
    }

    /**
     * Answer a 413 by making the next batch smaller.
     *
     * The queue is never touched: the head stays parked and is re-split on the
     * next flush, one round trip later on purpose — each attempt blocks the
     * merchant's browser for up to the edge timeout.
     *
     * @param array $head
     * @param array $tail
     * @param array $body
     *
     * @return bool
     */
    private function resize(array $head, array $tail, array $body)
    {
        if (count($head) === 1) {
            // A one-event batch cannot be split further, and `split` does not
            // advance the give-up ladder, so nothing else would break the loop.
            // Name and size only: the envelope is the one thing not to log.
            PaypercutTelemetryQueue::retainInflight($tail);
            $this->countDropped(1, 'oversize_event');
            PaypercutTelemetrySession::audit('Telemetry: event too large to deliver', array(
                'event' => (string) (isset($head[0]['event']) ? $head[0]['event'] : 'unknown'),
                'bytes' => PaypercutTelemetryQueue::bytes($head),
            ));
        } else {
            // Halving guarantees progress on its own: a 413 raised by a proxy in
            // front of the edge carries no limits at all, and the edge's own
            // byte cap is larger than ours, so neither would shrink the batch.
            $advertised = self::advertisedEvents($body);
            $halved = (int) max(1, (int) floor(count($head) / 2));

            PaypercutTelemetrySession::updateRuntime(array(
                'edge_max_events' => $advertised > 0 ? min($advertised, $halved) : $halved,
            ));
        }

        PaypercutTelemetrySession::updateRuntime(array(
            'next_attempt_at' => 0,
            'last_error' => 'edge_413',
        ));

        return true;
    }

    /**
     * The event cap the edge named in its 413, or 0 when it named none.
     *
     * @param array $body
     *
     * @return int
     */
    private static function advertisedEvents(array $body)
    {
        $limits = isset($body['limits']) && is_array($body['limits']) ? $body['limits'] : array();

        return (int) (isset($limits['max_events']) ? $limits['max_events'] : 0);
    }

    /**
     * Identifies the software that produced the batch.
     *
     * @return array
     */
    private static function clientIdentity()
    {
        $version = PaypercutTelemetryEvent::text((string) Paypercut::moduleVersion());

        return array(
            'platform' => 'prestashop',
            'version' => $version !== '' ? $version : 'dev',
        );
    }

    /**
     * Bytes left for the events array once the wrapper is paid for: the edge
     * caps the request body, not the events array.
     *
     * @param array $client
     *
     * @return int
     */
    private static function eventsBudget(array $client)
    {
        $wrapper = json_encode(array('client' => $client, 'events' => array()));

        return PaypercutTelemetrySession::MAX_BATCH_BYTES - (is_string($wrapper) ? strlen($wrapper) : 128);
    }

    /**
     * The event cap a batch must satisfy, as last advertised by the edge.
     *
     * Clamped on the way in: the edge may only ever make us more conservative.
     *
     * @return int
     */
    private static function maxEvents()
    {
        $runtime = PaypercutTelemetrySession::runtime();
        $events = (int) (isset($runtime['edge_max_events']) ? $runtime['edge_max_events'] : 0);

        return $events > 0
            ? max(1, min($events, PaypercutTelemetrySession::MAX_BATCH_EVENTS))
            : PaypercutTelemetrySession::MAX_BATCH_EVENTS;
    }

    /**
     * @param int    $events
     * @param string $reason
     */
    private function countDropped($events, $reason)
    {
        PaypercutTelemetrySession::audit('Telemetry: batch dropped', array(
            'events' => (int) $events,
            'reason' => (string) $reason,
        ));

        $runtime = PaypercutTelemetrySession::runtime();

        PaypercutTelemetrySession::updateRuntime(array(
            'events_dropped' => (int) (isset($runtime['events_dropped']) ? $runtime['events_dropped'] : 0) + (int) $events,
        ));
    }
}
