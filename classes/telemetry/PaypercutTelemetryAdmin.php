<?php

/**
 * Back-office hooks that keep a debug session honest between page loads.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaypercutTelemetryAdmin
{
    /** The panel's own endpoints, which reap and flush for themselves. */
    const PANEL_ACTIONS = array('startDebugSession', 'stopDebugSession', 'debugSessionStatus');

    /**
     * Is this an ordinary, authenticated back-office page request?
     *
     * The guard is deliberately stricter than "are we in the admin folder".
     * Delivery blocks the browser for up to the edge timeout and writes to the
     * database, so it must never happen on a storefront request, an AJAX call
     * or a cron run.
     *
     * @return bool
     */
    public static function isBackOfficeRequest()
    {
        if (!defined('_PS_ADMIN_DIR_')) {
            return false;
        }

        $context = Context::getContext();

        if (!$context || !isset($context->employee) || !Validate::isLoadedObject($context->employee)) {
            return false;
        }

        return true;
    }

    /**
     * Expire the session and drain the queue on back-office page loads.
     *
     * The panel's status poll is the primary delivery trigger; this is the
     * backstop for a merchant who started a session and navigated away.
     */
    public static function maybeReapAndFlush()
    {
        if (!self::isBackOfficeRequest() || Tools::getValue('ajax')) {
            return;
        }

        // The panel's own endpoints flush for themselves. The back-office
        // header hook fires before they run, so without this a single poll
        // would make two edge round trips and block the browser twice.
        if (in_array(Tools::getValue('action'), self::PANEL_ACTIONS, true)) {
            return;
        }

        $record = PaypercutTelemetrySession::record();

        if (!isset($record['status']) || $record['status'] !== 'active') {
            return;
        }

        PaypercutTelemetrySession::reap();

        if (!PaypercutTelemetrySession::isActiveFast() || PaypercutTelemetryQueue::size() === 0) {
            return;
        }

        $flusher = new PaypercutTelemetryFlusher();
        $flusher->flushOnce();
    }

    /**
     * The live session as the global back-office notice should present it.
     *
     * Every employee who can reach the module sees this: the module's own
     * logger is gated on a merchant preference, so without it a session could
     * run with no visible trace for anyone but the person who started it.
     *
     * @return array|null  null when no session is live
     */
    public static function liveNotice()
    {
        if (!self::isBackOfficeRequest()) {
            return null;
        }

        $record = PaypercutTelemetrySession::record();

        if (!isset($record['status']) || $record['status'] !== 'active') {
            return null;
        }

        $expiresAt = (int) (isset($record['expires_at']) ? $record['expires_at'] : 0);

        if ($expiresAt <= time()) {
            return null;
        }

        return array(
            'started_by_name' => (string) (isset($record['started_by_name']) ? $record['started_by_name'] : ''),
            'ends_at' => date('H:i', $expiresAt),
        );
    }

    /**
     * One line summarising a delivered event, so the sent-event table is
     * scannable without opening the JSON.
     *
     * @param array $entry  A delivered envelope
     *
     * @return string
     */
    public static function eventDetail(array $entry)
    {
        $parts = array();
        $error = isset($entry['error']) && is_array($entry['error']) ? $entry['error'] : array();

        if (isset($error['code'])) {
            $parts[] = (string) $error['code'];
        }

        foreach (array('order_ref', 'payment_id', 'payment_intent_id') as $key) {
            if (!empty($entry[$key])) {
                $parts[] = $key . '=' . (string) $entry[$key];
            }
        }

        $attrs = isset($entry['attrs']) && is_array($entry['attrs']) ? $entry['attrs'] : array();

        foreach (array('origin_plugin', 'http_status', 'reason', 'webhook') as $key) {
            if (isset($attrs[$key]) && is_scalar($attrs[$key])) {
                $parts[] = $key . '=' . (string) $attrs[$key];
            }
        }

        // Lifecycle events carry none of the keys above, and a row of dashes
        // tells the merchant nothing. Fall back to whatever the event does have.
        if (empty($parts)) {
            foreach ($attrs as $key => $value) {
                if (count($parts) >= 3) {
                    break;
                }

                if (is_scalar($value)) {
                    $parts[] = $key . '=' . (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);
                }
            }
        }

        return empty($parts) ? '—' : implode(' · ', $parts);
    }

    /**
     * The sent log as rows the admin template can render.
     *
     * @return array
     */
    public static function sentLogRows()
    {
        $rows = array();

        foreach (PaypercutSentLog::all() as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $rows[] = array(
                'occurred_at' => (string) (isset($entry['occurred_at']) ? $entry['occurred_at'] : '—'),
                'event' => (string) (isset($entry['event']) ? $entry['event'] : '—'),
                'detail' => self::eventDetail($entry),
            );
        }

        return $rows;
    }
}
