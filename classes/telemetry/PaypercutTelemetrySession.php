<?php

/**
 * Owns the debug (telemetry) session: its state, its storage, and its teardown.
 *
 * A debug session is a merchant-granted, self-expiring window during which the
 * store may send diagnostic events to Paypercut. The deadline is an absolute
 * unix timestamp in a durable, cheaply-readable record, and every read
 * recomputes liveness against it. That is what makes the session end on time
 * with no scheduled job: there is no timer to miss and nothing to orphan if the
 * process dies. The token's own exp is the matching bound on the server side.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaypercutTelemetrySession
{
    /**
     * Hard ceiling on a session, independent of what the mint hands back.
     *
     * With no revocation anywhere, this ceiling IS the consent: the merchant is
     * told "about 60 minutes", so the module must not run for longer even if a
     * future deployment issues longer-lived tokens.
     */
    const SESSION_MAX_SECONDS = 3600;

    /** Give up this long before the token expires, so we stop before the edge does. */
    const SKEW_SECONDS = 30;

    const MIN_LIFETIME_SECONDS = 60;

    /**
     * The queue is appended to by anonymous storefront requests. Unbounded
     * growth on a busy store is a denial of service against the store.
     */
    const MAX_QUEUE_EVENTS = 200;

    const MAX_QUEUE_BYTES = 65536;

    /**
     * Kept well under the edge's 64 KiB body cap: the edge does not deduplicate,
     * so a bigger batch only means losing more events per failed POST.
     */
    const MAX_BATCH_BYTES = 16384;

    /** The edge's own MaxEventsPerBatch. A batch over it is refused with 413. */
    const MAX_BATCH_EVENTS = 50;

    const MAX_CONSECUTIVE_SEND_FAILURES = 4;

    const MINT_TIMEOUT_SECONDS = 10;

    const MINT_CONNECT_TIMEOUT_SECONDS = 5;

    const EDGE_TIMEOUT_SECONDS = 5;

    const EDGE_CONNECT_TIMEOUT_SECONDS = 3;

    const START_LOCK_TTL = 60;

    const FLUSH_LOCK_TTL = 60;

    const FAILED_NOTICE_TTL = 600;

    const ENDED_NOTICE_TTL = 86400;

    const POLL_INTERVAL_SECONDS = 60;

    const SLOW_REQUEST_MS = 3000;

    /** Configuration key holding the durable record. Not a settings-form field. */
    const RECORD_KEY = 'PAYPERCUT_TELEMETRY_SESSION';

    const TOKEN_KEY = 'paypercut_telemetry_token';

    const QUEUE_KEY = 'paypercut_telemetry_queue';

    const INFLIGHT_KEY = 'paypercut_telemetry_inflight';

    const RUNTIME_KEY = 'paypercut_telemetry_runtime';

    const START_LOCK_KEY = 'paypercut_telemetry_start_lock';

    const FLUSH_LOCK_KEY = 'paypercut_telemetry_flush_lock';

    const SENT_LOG_KEY = 'paypercut_telemetry_sent_log';

    /** @var bool|null Per-request memo for the storefront gate */
    private static $activeMemo = null;

    // ──────────────────────────────────────────────
    // The storefront gate
    // ──────────────────────────────────────────────

    /**
     * Is a session live right now?
     *
     * Reads one already-preloaded Configuration value and nothing else — no
     * extra queries, no writes, no HTTP. This runs on anonymous checkout
     * requests, so anything more expensive belongs behind an admin guard.
     *
     * @return bool
     */
    public static function isActiveFast()
    {
        if (self::$activeMemo !== null) {
            return self::$activeMemo;
        }

        $record = self::record();

        self::$activeMemo = isset($record['status'])
            && $record['status'] === 'active'
            && (int) (isset($record['expires_at']) ? $record['expires_at'] : 0) > time();

        return self::$activeMemo;
    }

    /**
     * Forget the per-request memo. Only state transitions need this.
     */
    public static function flushMemo()
    {
        self::$activeMemo = null;
    }

    // ──────────────────────────────────────────────
    // The durable record
    // ──────────────────────────────────────────────

    /**
     * @return array  Empty when no session was ever written
     */
    public static function record()
    {
        $raw = Configuration::get(self::RECORD_KEY);

        if (!$raw) {
            return array();
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : array();
    }

    /**
     * @param array $record
     */
    private static function writeRecord(array $record)
    {
        Configuration::updateValue(self::RECORD_KEY, (string) json_encode($record));
    }

    /**
     * The session state as the admin panel should present it.
     *
     * @return array
     */
    public static function describe()
    {
        $record = self::record();
        $runtime = self::runtime();
        $now = time();

        $status = isset($record['status']) ? (string) $record['status'] : '';
        $endedAt = (int) (isset($record['ended_at']) ? $record['ended_at'] : 0);
        $state = 'idle';

        if ($status === 'active') {
            $state = (int) (isset($record['expires_at']) ? $record['expires_at'] : 0) > $now ? 'running' : 'ended';
        } elseif ($status === 'failed') {
            $state = ($now - $endedAt) < self::FAILED_NOTICE_TTL ? 'failed' : 'idle';
        } elseif ($status === 'stopped' || $status === 'expired') {
            $state = ($now - $endedAt) < self::ENDED_NOTICE_TTL ? 'ended' : 'idle';
        }

        return array(
            'state' => $state,
            'session_id' => isset($record['session_id']) ? (string) $record['session_id'] : '',
            'expires_at' => (int) (isset($record['expires_at']) ? $record['expires_at'] : 0),
            'started_at' => (int) (isset($record['started_at']) ? $record['started_at'] : 0),
            'ended_at' => $endedAt,
            'started_by_name' => isset($record['started_by_name']) ? (string) $record['started_by_name'] : '',
            'reason_code' => isset($record['reason_code']) ? (string) $record['reason_code'] : '',
            'trace_id' => isset($record['trace_id']) ? (string) $record['trace_id'] : '',
            'request_id' => isset($record['request_id']) ? (string) $record['request_id'] : '',
            'retryable' => (bool) (isset($record['retryable']) ? $record['retryable'] : false),
            'message' => isset($record['message']) ? (string) $record['message'] : '',
            // Live counters come from the runtime row; stop() folds them into
            // the record and deletes that row, so an ended session has to be
            // read back from the record or the panel reports zero events after
            // having sent some.
            'events_sent' => (int) (isset($runtime['events_sent'])
                ? $runtime['events_sent']
                : (isset($record['events_sent']) ? $record['events_sent'] : 0)),
            'events_dropped' => (int) (isset($runtime['events_dropped'])
                ? $runtime['events_dropped']
                : (isset($record['events_dropped']) ? $record['events_dropped'] : 0)),
            'queued' => PaypercutTelemetryQueue::size(),
        );
    }

    // ──────────────────────────────────────────────
    // Token custody
    // ──────────────────────────────────────────────

    /**
     * The telemetry token, or '' when there is not a usable one.
     *
     * Every condition here is a reason the token must not be used, and each is
     * checked rather than assumed: the stored TTL is a backstop, never the
     * authority.
     *
     * @return string
     */
    public static function token()
    {
        $record = self::record();

        if (!isset($record['status']) || $record['status'] !== 'active') {
            return '';
        }

        $expiresAt = (int) (isset($record['expires_at']) ? $record['expires_at'] : 0);

        if ($expiresAt <= time()) {
            return '';
        }

        $stored = PaypercutTelemetryStore::get(self::TOKEN_KEY);

        if (!is_array($stored) || !isset($stored['token']) || !is_string($stored['token'])) {
            return '';
        }

        if ((int) (isset($stored['expires_at']) ? $stored['expires_at'] : 0) !== $expiresAt) {
            return '';
        }

        if (!self::credentialMatches($record)) {
            return '';
        }

        $decoded = base64_decode($stored['token'], true);

        return is_string($decoded) ? $decoded : '';
    }

    /**
     * Does the stored record still describe the connection the store has today?
     *
     * @param array $record
     *
     * @return bool
     */
    public static function credentialMatches(array $record)
    {
        $connection = self::connection();
        $fingerprint = self::fingerprint($connection['secret']);

        if ($fingerprint === '' || $fingerprint !== (string) (isset($record['key_fingerprint']) ? $record['key_fingerprint'] : '')) {
            return false;
        }

        return $connection['environment'] === (string) (isset($record['environment']) ? $record['environment'] : '');
    }

    /**
     * The stored credential and environment.
     *
     * @return array  { secret, environment }
     */
    public static function connection()
    {
        return array(
            'secret' => (string) Configuration::get(Paypercut::CONFIG_API_KEY),
            'environment' => PaypercutEnvironment::current(),
        );
    }

    /**
     * Every credential the store holds, for the deny assertion to compare against.
     *
     * This list must enumerate every credential-bearing setting: comparing a
     * value against the actual secret is the only screen that catches a format
     * nobody anticipated, and it is silently useless for a setting not named
     * here. A future gateway adding its own credential breaks it.
     *
     * @return array
     */
    public static function credentials()
    {
        $secrets = array(
            self::token(),
            (string) Configuration::get(Paypercut::CONFIG_API_KEY),
            (string) Configuration::get(Paypercut::CONFIG_WEBHOOK_SECRET),
        );

        // An empty secret would match every string, so filter before returning.
        return array_values(array_filter($secrets, 'strlen'));
    }

    /**
     * A short, non-reversing marker for "the same API key as before".
     *
     * @param string $secret
     *
     * @return string
     */
    public static function fingerprint($secret)
    {
        return (string) $secret === '' ? '' : substr(hash('sha256', (string) $secret), 0, 12);
    }

    // ──────────────────────────────────────────────
    // Lifecycle
    // ──────────────────────────────────────────────

    /**
     * Publish a new session and store its token.
     *
     * @param array  $record
     * @param string $jwt
     */
    public static function begin(array $record, $jwt)
    {
        $expiresAt = (int) $record['expires_at'];

        PaypercutTelemetryStore::put(
            self::TOKEN_KEY,
            array(
                'token' => base64_encode($jwt),
                'expires_at' => $expiresAt,
            ),
            max(60, $expiresAt - time())
        );

        // Never inherit a previous session's buffer: those events were gathered
        // under a different consent and would ship under this session's id.
        PaypercutTelemetryStore::delete(self::QUEUE_KEY);
        PaypercutTelemetryStore::delete(self::INFLIGHT_KEY);

        // The log shows what this session sent, so a previous one's tail would
        // misattribute events the merchant is reading to decide what happened.
        PaypercutSentLog::clear();

        self::writeRecord($record);

        PaypercutTelemetryStore::put(self::RUNTIME_KEY, array(
            'events_sent' => 0,
            'events_dropped' => 0,
            'consecutive_edge_failures' => 0,
            'next_attempt_at' => 0,
            'last_error' => '',
        ));

        self::flushMemo();
    }

    /**
     * Record a start that never happened, so the merchant sees why.
     *
     * @param array  $mapped     { reason_code, message, retryable }
     * @param string $traceId
     * @param string $requestId
     */
    public static function fail(array $mapped, $traceId = '', $requestId = '')
    {
        $record = self::record();

        // Never overwrite a live session with a failure notice: a concurrent
        // start that loses a race would otherwise erase the winner's record and
        // strand its token beyond the reach of every teardown path.
        if (isset($record['status']) && $record['status'] === 'active') {
            return;
        }

        self::writeRecord(array(
            'status' => 'failed',
            'ended_at' => time(),
            'reason_code' => $mapped['reason_code'],
            'message' => $mapped['message'],
            'retryable' => (bool) $mapped['retryable'],
            'trace_id' => (string) $traceId,
            'request_id' => (string) $requestId,
        ));

        self::flushMemo();
    }

    /**
     * End the session and destroy every trace of its credential.
     *
     * Idempotent, and the single teardown path: expiry, the Stop button, a
     * re-key, an environment change, uninstall and the edge rejecting a token
     * all arrive here, so there is exactly one place that can forget something.
     *
     * @param string $reason
     */
    public static function end($reason)
    {
        $record = self::record();

        PaypercutTelemetryStore::delete(self::TOKEN_KEY);
        PaypercutTelemetryStore::delete(self::QUEUE_KEY);
        PaypercutTelemetryStore::delete(self::INFLIGHT_KEY);

        if (empty($record) || !isset($record['status']) || $record['status'] !== 'active') {
            PaypercutTelemetryStore::delete(self::RUNTIME_KEY);
            self::flushMemo();

            return;
        }

        $runtime = self::runtime();

        self::writeRecord(array(
            'status' => $reason === 'expired' ? 'expired' : 'stopped',
            'session_id' => (string) (isset($record['session_id']) ? $record['session_id'] : ''),
            'environment' => (string) (isset($record['environment']) ? $record['environment'] : ''),
            'started_at' => (int) (isset($record['started_at']) ? $record['started_at'] : 0),
            'expires_at' => (int) (isset($record['expires_at']) ? $record['expires_at'] : 0),
            'started_by' => (int) (isset($record['started_by']) ? $record['started_by'] : 0),
            'started_by_name' => (string) (isset($record['started_by_name']) ? $record['started_by_name'] : ''),
            'ended_at' => time(),
            'reason_code' => (string) $reason,
            'events_sent' => (int) (isset($runtime['events_sent']) ? $runtime['events_sent'] : 0),
            'events_dropped' => (int) (isset($runtime['events_dropped']) ? $runtime['events_dropped'] : 0),
        ));

        PaypercutTelemetryStore::delete(self::RUNTIME_KEY);

        self::flushMemo();

        self::audit('Telemetry: debug session ended', array(
            'session_id' => (string) (isset($record['session_id']) ? $record['session_id'] : ''),
            'reason' => (string) $reason,
        ));
    }

    /**
     * Tear down a session whose deadline has passed, or whose connection changed.
     *
     * Admin context only — it writes. This is what turns "the gate is closed"
     * into "the token is gone": the gate flips the instant the deadline passes,
     * but the stored copy is removed by the next admin request that runs this.
     */
    public static function reap()
    {
        $record = self::record();

        if (!isset($record['status']) || $record['status'] !== 'active') {
            // No live session, but a stored token means the record was lost
            // without one. The credential is now referenced by nothing, so
            // destroy it here rather than leave it to expire.
            if (PaypercutTelemetryStore::exists(self::TOKEN_KEY)) {
                self::end('token_orphaned');
            }

            return;
        }

        if ((int) (isset($record['expires_at']) ? $record['expires_at'] : 0) <= time()) {
            self::end('expired');

            return;
        }

        if (!self::credentialMatches($record)) {
            self::end('connection_changed');

            return;
        }

        if (self::token() === '') {
            self::end('token_lost');
        }
    }

    // ──────────────────────────────────────────────
    // Runtime counters
    // ──────────────────────────────────────────────

    /**
     * @return array
     */
    public static function runtime()
    {
        $runtime = PaypercutTelemetryStore::get(self::RUNTIME_KEY);

        return is_array($runtime) ? $runtime : array();
    }

    /**
     * @param array $values
     */
    public static function updateRuntime(array $values)
    {
        PaypercutTelemetryStore::put(self::RUNTIME_KEY, array_merge(self::runtime(), $values));
    }

    // ──────────────────────────────────────────────
    // Locks
    // ──────────────────────────────────────────────

    /**
     * @return bool
     */
    public static function claimStartLock()
    {
        return PaypercutTelemetryStore::claimLock(self::START_LOCK_KEY, self::START_LOCK_TTL);
    }

    public static function releaseStartLock()
    {
        PaypercutTelemetryStore::releaseLock(self::START_LOCK_KEY);
    }

    /**
     * @return bool
     */
    public static function claimFlushLock()
    {
        return PaypercutTelemetryStore::claimLock(self::FLUSH_LOCK_KEY, self::FLUSH_LOCK_TTL);
    }

    public static function releaseFlushLock()
    {
        PaypercutTelemetryStore::releaseLock(self::FLUSH_LOCK_KEY);
    }

    // ──────────────────────────────────────────────
    // Audit
    // ──────────────────────────────────────────────

    /**
     * Write a log line whatever the merchant's logging preference is.
     *
     * Starting and stopping a session is an audit event: a store with logging
     * switched off must still leave a record that data left it.
     *
     * @param string $message
     * @param array  $context
     */
    public static function audit($message, array $context = array())
    {
        PrestaShopLogger::addLog(
            'Paypercut: ' . $message . ' ' . (string) json_encode($context),
            1,
            null,
            'Paypercut',
            null,
            true
        );
    }

    /**
     * Delete every stored trace of the feature. Used on uninstall.
     */
    public static function purge()
    {
        Configuration::deleteByName(self::RECORD_KEY);
        PaypercutTelemetryStore::purge();
        self::flushMemo();
    }
}
