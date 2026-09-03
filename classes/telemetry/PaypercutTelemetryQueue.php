<?php

/**
 * The buffered, best-effort store of diagnostic events awaiting delivery.
 *
 * Storefront requests only ever append here; delivery happens later, from an
 * authenticated back-office request. Everything is capped, because a queue that
 * can grow without bound on a busy store is a denial of service against the
 * store.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaypercutTelemetryQueue
{
    /**
     * Append envelopes, dropping the oldest if that overflows the caps.
     *
     * @param array $envelopes
     */
    public static function append(array $envelopes)
    {
        $envelopes = self::assertSafe($envelopes);

        if (empty($envelopes)) {
            return;
        }

        $capped = self::cap(array_merge(self::read(PaypercutTelemetrySession::QUEUE_KEY), $envelopes));

        self::write(PaypercutTelemetrySession::QUEUE_KEY, $capped['envelopes']);

        // Counted only from back-office requests. A storefront request must make
        // at most one write, and the queue write above is it — the panel already
        // presents this counter as approximate.
        if ($capped['dropped'] > 0 && PaypercutTelemetryAdmin::isBackOfficeRequest()) {
            $runtime = PaypercutTelemetrySession::runtime();
            PaypercutTelemetrySession::updateRuntime(array(
                'events_dropped' => (int) (isset($runtime['events_dropped']) ? $runtime['events_dropped'] : 0) + $capped['dropped'],
            ));
        }
    }

    /**
     * The last gate before anything is persisted for delivery.
     *
     * Every producer funnels through here — the storefront recorder and the
     * admin-side lifecycle events alike — so the deny assertion cannot be
     * bypassed by adding a new call site. A tripped assertion drops the WHOLE
     * event, not the offending field: an event assembled wrongly cannot be
     * trusted in its other parts either.
     *
     * @param array $envelopes
     *
     * @return array
     */
    private static function assertSafe(array $envelopes)
    {
        if (empty($envelopes)) {
            return array();
        }

        $secrets = PaypercutTelemetrySession::credentials();
        $safe = array();

        foreach ($envelopes as $envelope) {
            // The WHOLE envelope, exactly as it will be serialised. attrs and
            // error are not the only things on the wire: the correlation fields
            // about() writes are top-level siblings fed from upstream webhook
            // JSON, and any hand-picked subset stops covering whatever the next
            // envelope field turns out to be.
            if (PaypercutTelemetryEvent::isDenied($envelope, $secrets)) {
                // The event name only — never the envelope.
                PaypercutTelemetrySession::audit(
                    'Telemetry: event dropped by the deny assertion',
                    array('event' => (string) (isset($envelope['event']) ? $envelope['event'] : 'unknown'))
                );

                continue;
            }

            $safe[] = $envelope;
        }

        return $safe;
    }

    /**
     * Enforce the queue caps, dropping the oldest entries first.
     *
     * @param array $envelopes
     *
     * @return array  { envelopes, dropped }
     */
    public static function cap(array $envelopes)
    {
        $dropped = 0;

        if (count($envelopes) > PaypercutTelemetrySession::MAX_QUEUE_EVENTS) {
            $dropped = count($envelopes) - PaypercutTelemetrySession::MAX_QUEUE_EVENTS;
            $envelopes = array_slice($envelopes, -PaypercutTelemetrySession::MAX_QUEUE_EVENTS);
        }

        // Stop at one, mirroring splitBatch(): a single oversized envelope must
        // not empty the queue behind it.
        while (count($envelopes) > 1 && self::bytes($envelopes) > PaypercutTelemetrySession::MAX_QUEUE_BYTES) {
            array_shift($envelopes);
            ++$dropped;
        }

        return array(
            'envelopes' => $envelopes,
            'dropped' => $dropped,
        );
    }

    /**
     * Split a batch off the front of the queue, within both edge bounds.
     *
     * Always takes at least one envelope: a single oversized envelope would
     * otherwise wedge the queue forever, and the edge rejecting it once is a
     * cheaper outcome than never draining. Never drops and never reorders.
     *
     * @param array $envelopes
     * @param int   $maxBytes
     * @param int   $maxEvents
     *
     * @return array  { batch, remainder }
     */
    public static function splitBatch(array $envelopes, $maxBytes, $maxEvents)
    {
        $batch = array();

        foreach ($envelopes as $envelope) {
            if (count($batch) >= (int) $maxEvents) {
                break;
            }

            $candidate = array_merge($batch, array($envelope));

            if (!empty($batch) && self::bytes($candidate) > (int) $maxBytes) {
                break;
            }

            $batch = $candidate;
        }

        return array(
            'batch' => $batch,
            'remainder' => array_slice($envelopes, count($batch)),
        );
    }

    /**
     * Take a batch, shortening the stored queue immediately.
     *
     * The remainder is written back BEFORE the network call, and the batch is
     * parked under its own key. Holding the remainder across the request would
     * discard anything storefront requests appended while the POST was in
     * flight, and could resurrect an already-delivered batch.
     *
     * @param int $maxBytes
     * @param int $maxEvents
     *
     * @return array
     */
    public static function takeBatch($maxBytes, $maxEvents)
    {
        $split = self::splitBatch(self::read(PaypercutTelemetrySession::QUEUE_KEY), $maxBytes, $maxEvents);

        if (empty($split['batch'])) {
            return array();
        }

        self::write(PaypercutTelemetrySession::QUEUE_KEY, $split['remainder']);
        self::write(PaypercutTelemetrySession::INFLIGHT_KEY, $split['batch']);

        return $split['batch'];
    }

    /**
     * A batch that was taken but whose delivery has not been settled.
     *
     * @return array
     */
    public static function inflight()
    {
        return self::read(PaypercutTelemetrySession::INFLIGHT_KEY);
    }

    public static function clearInflight()
    {
        PaypercutTelemetryStore::delete(PaypercutTelemetrySession::INFLIGHT_KEY);
    }

    /**
     * Shorten the parked batch to what is left to deliver.
     *
     * The flusher may only ever SHORTEN in-flight, never write the queue: the
     * flush lock excludes other flushers, but append() is an unlocked
     * read-modify-write from anonymous storefront requests, and takeBatch() has
     * already removed this batch from the queue.
     *
     * @param array $envelopes
     */
    public static function retainInflight(array $envelopes)
    {
        self::write(PaypercutTelemetrySession::INFLIGHT_KEY, $envelopes);
    }

    /**
     * @return int
     */
    public static function size()
    {
        return count(self::read(PaypercutTelemetrySession::QUEUE_KEY))
            + count(self::read(PaypercutTelemetrySession::INFLIGHT_KEY));
    }

    /**
     * @param array $envelopes
     *
     * @return int
     */
    public static function bytes(array $envelopes)
    {
        $json = json_encode($envelopes);

        return is_string($json) ? strlen($json) : 0;
    }

    /**
     * @param string $key
     *
     * @return array
     */
    private static function read($key)
    {
        $stored = PaypercutTelemetryStore::get($key);

        return is_array($stored) ? $stored : array();
    }

    /**
     * @param string $key
     * @param array  $envelopes
     */
    private static function write($key, array $envelopes)
    {
        PaypercutTelemetryStore::put($key, $envelopes, self::ttl());
    }

    /**
     * Outlive the session slightly, so a final flush still finds its batch.
     *
     * @return int
     */
    private static function ttl()
    {
        $record = PaypercutTelemetrySession::record();
        $expiresAt = (int) (isset($record['expires_at']) ? $record['expires_at'] : 0);

        return max(300, ($expiresAt - time()) + 300);
    }
}
