<?php

/**
 * A local copy of the events this store actually delivered.
 *
 * The queue is emptied as it drains, so by the time anyone looks there is
 * nothing left to see: the panel could report "37 events sent" and offer no way
 * to find out what they were. Consent to send diagnostics is worth more when
 * the sender can inspect what left.
 *
 * Nothing here runs on a storefront request — the flusher delivers only from
 * authenticated back-office requests.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaypercutSentLog
{
    /**
     * Entries kept before the oldest are discarded.
     *
     * A session is an hour; a busy store can deliver far more than this, so the
     * log is a tail rather than a transcript. The panel says so.
     */
    const MAX_ENTRIES = 100;

    const MAX_BYTES = 131072;

    /**
     * Record envelopes the edge accepted, newest last.
     *
     * @param array $envelopes  Exactly what was POSTed
     */
    public static function append(array $envelopes)
    {
        if (empty($envelopes)) {
            return;
        }

        $entries = array_merge(self::all(), $envelopes);

        if (count($entries) > self::MAX_ENTRIES) {
            $entries = array_slice($entries, -self::MAX_ENTRIES);
        }

        while (count($entries) > 1 && self::bytes($entries) > self::MAX_BYTES) {
            array_shift($entries);
        }

        PaypercutTelemetryStore::put(PaypercutTelemetrySession::SENT_LOG_KEY, $entries);
    }

    /**
     * @return array
     */
    public static function all()
    {
        $entries = PaypercutTelemetryStore::get(PaypercutTelemetrySession::SENT_LOG_KEY);

        return is_array($entries) ? $entries : array();
    }

    public static function clear()
    {
        PaypercutTelemetryStore::delete(PaypercutTelemetrySession::SENT_LOG_KEY);
    }

    /**
     * @param array $entries
     *
     * @return int
     */
    private static function bytes(array $entries)
    {
        $json = json_encode($entries);

        return is_string($json) ? strlen($json) : 0;
    }
}
