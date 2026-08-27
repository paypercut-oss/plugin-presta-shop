<?php

/**
 * A single diagnostic event, and the allow-list that defines what may leave the store.
 *
 * There is deliberately no generic "record these fields" constructor. Every
 * event is built by a named constructor with declared scalar parameters, so the
 * set of things that can ever be transmitted is fixed when this file is written
 * rather than at each call site. is_scalar() is explicitly NOT the boundary:
 * every secret this module holds (the API key, the webhook secret) is a scalar
 * string living beside the values we do report.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaypercutTelemetryEvent
{
    /**
     * Longest string any single field may carry, in BYTES.
     *
     * Bytes rather than codepoints because the edge bounds the raw Go string:
     * a 128-codepoint CJK theme name is 384 bytes and would be dropped whole.
     */
    const MAX_TEXT_BYTES = 256;

    /**
     * The edge keeps the first attributes in sorted key order and drops the
     * rest, so a single over-wide event would silently lose its version fields.
     */
    const MAX_ATTRS = 16;

    const MAX_STACK_FRAMES = 8;

    /** Shortest leading run of a credential that still counts as carrying it. */
    const MIN_SECRET_FRAGMENT = 12;

    /** Field names that must never appear in an event, whatever their value. */
    const DENIED_KEY_PATTERN = '/secret|token|password|credential|nonce|auth|_key$/i';

    /**
     * Value shapes that must never appear, whatever their field name.
     *
     * Not anchored to the start of the string, because a stack frame or an HTTP
     * error carries the credential mid-string every time. Not left unanchored
     * either: bare sk_/pk_ also matches "disk_usage" and "risk_free", and a
     * tripped assertion bins the whole event.
     */
    const DENIED_VALUE_PATTERN = '/(?:^|[^A-Za-z0-9_])(ppc_|sk_|pk_|whsec_|eyJ[A-Za-z0-9_-]+\.)/i';

    /**
     * Host and platform versions. Read by environmentSnapshot().
     *
     * Both snapshot lists are iterated INSTEAD of the caller's array: pulling
     * keys from a settings array is how a credential ends up on the wire.
     */
    const SNAPSHOT_FIELDS = array(
        'plugin_version' => 'text',
        'platform_version' => 'text',
        'php_version' => 'text',
        'theme_name' => 'text',
        'theme_version' => 'text',
        'is_multistore' => 'bool',
        'is_ssl' => 'bool',
    );

    /** Module settings. Read by environmentConfiguration(). */
    const CONFIGURATION_FIELDS = array(
        'checkout_mode' => 'identifier',
        'order_status' => 'identifier',
        'statement_descriptor_set' => 'bool',
        'google_pay_enabled' => 'bool',
        'apple_pay_enabled' => 'bool',
        'logging_enabled' => 'bool',
        'card_enabled' => 'bool',
        'connection_environment' => 'identifier',
        'api_key_mode' => 'identifier',
        'webhook_configured' => 'bool',
        'payment_domain_registered' => 'bool',
        'currency_supported' => 'bool',
    );

    /** @var string */
    private $name;

    /** @var array */
    private $fields;

    /** @var array Correlation fields, sent outside attrs */
    private $correlation = array();

    /** @var array */
    private $error = array();

    /**
     * @param string $name
     * @param array  $fields
     */
    private function __construct($name, array $fields)
    {
        $this->name = $name;
        $this->fields = $fields;
    }

    // ──────────────────────────────────────────────
    // Named constructors
    // ──────────────────────────────────────────────

    /**
     * Report something that happened and did not fail.
     *
     * Failures alone cannot answer the commonest support question, which is
     * whether the shopper ever reached us: a session with no checkout events at
     * all and one with a silent early return look identical.
     *
     * @param string $name
     * @param array  $attrs
     *
     * @return PaypercutTelemetryEvent
     */
    public static function of($name, array $attrs = array())
    {
        return new self($name, self::cleanAttrs($attrs));
    }

    /**
     * Report a failure, under whichever event name describes where it happened.
     *
     * @param string         $name
     * @param string         $code
     * @param array          $attrs
     * @param Throwable|null $exception
     *
     * @return PaypercutTelemetryEvent
     */
    public static function failure($name, $code, array $attrs = array(), $exception = null)
    {
        $event = new self($name, self::cleanAttrs($attrs));

        $cleanCode = self::text((string) $code);
        $event->error = array('code' => $cleanCode !== '' ? $cleanCode : 'unknown');

        if ($exception instanceof Throwable) {
            // The message is the one string here this module did not author, and
            // the platform puts store data in it: PrestaShopDatabaseException
            // inlines the failing SQL and the database user@host whenever
            // _PS_DEBUG_SQL_ is on, which is the state a store under a debug
            // session is in. The class, the stack and the origin carry the
            // diagnosis; a call site with prose of its own uses because().
            $event->error['type'] = self::shortClassName($exception);
            $event->error['stack'] = self::stack($exception);

            $event->withDerived(self::origin(self::frameFiles($exception)));
        }

        return $event;
    }

    /**
     * Report a Paypercut API failure with the fields the platform returned.
     *
     * @param string                $name
     * @param PaypercutApiException $exception
     * @param array                 $attrs
     *
     * @return PaypercutTelemetryEvent
     */
    public static function apiFailure($name, PaypercutApiException $exception, array $attrs = array())
    {
        $event = self::failure($name, 'http_' . $exception->getStatusCode(), $attrs, $exception);

        // Belt and braces: failure() no longer copies an exception message, and
        // the platform quotes a rejected key back inside this one.
        unset($event->error['message']);

        $type = self::text($exception->getErrorType());
        if ($type !== '') {
            $event->error['type'] = $type;
        }

        $structured = array();

        foreach (array(
            'api_code' => $exception->getErrorCode(),
            'api_param' => $exception->getParam(),
            'trace_id' => $exception->getTraceId(),
        ) as $key => $value) {
            $clean = self::text((string) $value);
            if ($clean !== '') {
                $structured[$key] = $clean;
            }
        }

        $structured['http_status'] = $exception->getStatusCode();

        $event->withDerived($structured);

        return $event;
    }

    /**
     * Report the fatal that ended a request.
     *
     * Built from error_get_last(), which carries no exception and no trace —
     * the file that died is the only attribution available.
     *
     * @param string $message
     * @param string $file
     * @param int    $line
     * @param int    $level
     *
     * @return PaypercutTelemetryEvent
     */
    public static function fatal($message, $file, $line, $level)
    {
        $event = new self('php.fatal', array('level' => (int) $level));

        $event->withDerived(self::origin(array($file)));

        $event->error = array(
            'code' => 'php_fatal',
            'type' => 'FatalError',
            'stack' => array(self::relativePath($file) . ':' . (int) $line),
        );

        $uncaught = self::uncaughtClass($message);

        if ($uncaught !== '') {
            // PHP inlines the throwable's own prose here, and the platform writes
            // store data into it: PrestaShopDatabaseException carries the failing
            // SQL and the database user@host. Same rule as failure() — the class,
            // the file and the origin are the diagnosis; the prose is not sent.
            $event->error['type'] = $uncaught;
        } elseif ((int) $level !== E_USER_ERROR) {
            // What is left is the engine's own account of the request (memory
            // exhausted, execution time, parse error). trigger_error() prose is
            // written by whichever module called it, so it is not sent either.
            $event->error['message'] = self::text(self::fatalMessage($message));
        }

        return $event;
    }

    /**
     * The throwable class behind a fatal, when the fatal is an uncaught one.
     *
     * @param string $message  error_get_last()['message']
     *
     * @return string  '' when this fatal is the engine's own
     */
    private static function uncaughtClass($message)
    {
        $pattern = '/^Uncaught\s+([A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*)\s*:/';

        if (!preg_match($pattern, (string) $message, $matches)) {
            return '';
        }

        $parts = explode('\\', $matches[1]);
        $name = self::text((string) end($parts));

        return $name !== '' ? $name : 'Throwable';
    }

    /**
     * Note what is absent: the employee who started the session. The durable
     * record keeps it for the admin notice, but a store-user identifier is not
     * covered by the merchant-facing disclosure, so it does not go on the wire.
     *
     * @param string $sessionId
     * @param string $environment
     * @param int    $expiresAt
     *
     * @return PaypercutTelemetryEvent
     */
    public static function sessionStarted($sessionId, $environment, $expiresAt)
    {
        return new self('session.started', array(
            'session_id' => self::identifier($sessionId),
            'environment' => self::identifier($environment),
            'expires_at' => (int) $expiresAt,
        ));
    }

    /**
     * @param string $sessionId
     * @param string $reason
     * @param int    $eventsSent
     * @param int    $eventsDropped
     *
     * @return PaypercutTelemetryEvent
     */
    public static function sessionStopped($sessionId, $reason, $eventsSent, $eventsDropped)
    {
        return new self('session.stopped', array(
            'session_id' => self::identifier($sessionId),
            'reason' => self::identifier($reason),
            'events_sent' => (int) $eventsSent,
            'events_dropped' => (int) $eventsDropped,
        ));
    }

    /**
     * @param array $values  Candidate values; only SNAPSHOT_FIELDS keys are read
     *
     * @return PaypercutTelemetryEvent
     */
    public static function environmentSnapshot(array $values)
    {
        return new self('environment.snapshot', self::castFields(self::SNAPSHOT_FIELDS, $values));
    }

    /**
     * Separate from the environment snapshot only because the two together
     * exceed MAX_ATTRS; nothing else distinguishes them.
     *
     * @param array $values  Candidate values; only CONFIGURATION_FIELDS keys are read
     *
     * @return PaypercutTelemetryEvent
     */
    public static function environmentConfiguration(array $values)
    {
        return new self('environment.configuration', self::castFields(self::CONFIGURATION_FIELDS, $values));
    }

    /**
     * The installed-module inventory, chunked to fit the attribute cap.
     *
     * This is the list support compares against a working store when a conflict
     * is suspected. Slugs and versions only — no author, no path.
     *
     * @param array $modules  slug => version, sorted by the caller
     *
     * @return PaypercutTelemetryEvent[]
     */
    public static function environmentModules(array $modules)
    {
        $total = count($modules);
        // Three reserved slots: module_count, chunk, and omitted.
        $chunks = array_chunk($modules, self::MAX_ATTRS - 3, true);
        $events = array();

        foreach ($chunks as $index => $chunk) {
            $fields = array(
                'module_count' => $total,
                'chunk' => (int) $index + 1,
            );

            $omitted = 0;

            foreach ($chunk as $slug => $version) {
                $key = self::text((string) $slug);
                $value = self::text((string) $version);

                // A merchant slug becomes an attribute KEY here, and one slug
                // that trips the deny assertion (Authorize.net ships
                // "authorizeaim") would otherwise bin the whole chunk — the one
                // artefact whose only purpose is conflict diagnosis.
                if ($key === '' || self::isDenied(array($key => $value))) {
                    ++$omitted;

                    continue;
                }

                $fields[$key] = $value;
            }

            if ($omitted > 0) {
                $fields['omitted'] = $omitted;
            }

            $events[] = new self('environment.plugins', $fields);
        }

        return $events;
    }

    // ──────────────────────────────────────────────
    // Fluent modifiers
    // ──────────────────────────────────────────────

    /**
     * Attach the ids that join this event to a payment.
     *
     * @param array $correlation
     *
     * @return PaypercutTelemetryEvent
     */
    public function about(array $correlation)
    {
        foreach (array('payment_intent_id', 'payment_id', 'order_ref') as $field) {
            $value = trim((string) (isset($correlation[$field]) ? $correlation[$field] : ''));

            if ($value !== '') {
                $this->correlation[$field] = self::text($value);
            }
        }

        return $this;
    }

    /**
     * A message this module authored itself, for a failure with no exception
     * worth quoting.
     *
     * @param string $message
     *
     * @return PaypercutTelemetryEvent
     */
    public function because($message)
    {
        $clean = self::text((string) $message);

        if ($clean !== '') {
            $this->error['message'] = $clean;
        }

        return $this;
    }

    // ──────────────────────────────────────────────
    // Wire shape
    // ──────────────────────────────────────────────

    /**
     * @return string
     */
    public function name()
    {
        return $this->name;
    }

    /**
     * The wire shape of a single event inside a batch.
     *
     * The contract's field is occurred_at, an RFC3339 STRING. Sending a unix
     * int under that name fails the whole event, so name and type move together.
     *
     * @param int|null $now  Injected clock, so the suite can pin timestamps
     *
     * @return array
     */
    public function envelope($now = null)
    {
        $envelope = array(
            'event' => $this->name,
            'occurred_at' => gmdate('Y-m-d\TH:i:s\Z', $now === null ? time() : (int) $now),
        );

        foreach ($this->correlation as $field => $value) {
            $envelope[$field] = $value;
        }

        if (!empty($this->error)) {
            $envelope['error'] = $this->error;
        }

        // PHP renders an empty array as [], which the edge reads as "not an
        // object" and records as a drop against an otherwise clean event.
        if (!empty($this->fields)) {
            $envelope['attrs'] = $this->fields;
        }

        return $envelope;
    }

    // ──────────────────────────────────────────────
    // The deny assertion
    // ──────────────────────────────────────────────

    /**
     * Hard deny assertion: true when this event must be dropped entirely.
     *
     * A safety net behind the named constructors, not the primary control. It
     * drops the whole event rather than the offending field, because a field
     * that trips it means the event was assembled wrongly and the rest of it
     * cannot be trusted either.
     *
     * @param array $fields
     * @param array $secrets
     * @param int   $depth
     *
     * @return bool
     */
    public static function isDenied(array $fields, array $secrets = array(), $depth = 0)
    {
        // Fail closed past the two levels the contract declares: a structure
        // deeper than error.stack is one this screen has never been read against.
        if ($depth > 2) {
            return true;
        }

        foreach ($fields as $key => $value) {
            if (preg_match(self::DENIED_KEY_PATTERN, (string) $key)) {
                return true;
            }

            // The contract nests one level — error, and error.stack inside it.
            // Without recursion the assertion sees a non-string and gives up,
            // which is exactly where free text now lives.
            if (is_array($value)) {
                if (self::isDenied($value, $secrets, $depth + 1)) {
                    return true;
                }

                continue;
            }

            if (!is_string($value) || $value === '') {
                continue;
            }

            if (preg_match(self::DENIED_VALUE_PATTERN, $value)) {
                return true;
            }

            if (self::containsCardNumber($value)) {
                return true;
            }

            // Shape matching is a guess; comparing against the store's actual
            // credentials is not. This catches a secret in a format nobody
            // anticipated, including one a future Paypercut release introduces.
            foreach ($secrets as $secret) {
                if (self::carriesSecret($value, $secret)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Does this value carry the store's credential, whole or clipped?
     *
     * text() clamps every string to MAX_TEXT_BYTES before the assertion ever
     * sees it, so a secret straddling the cut survives only as the value's
     * tail — where a plain substring comparison against the whole secret misses
     * it. Anything shorter than MIN_SECRET_FRAGMENT is not enough of a
     * credential to be worth the false positives.
     *
     * @param string $value
     * @param mixed  $secret
     *
     * @return bool
     */
    private static function carriesSecret($value, $secret)
    {
        if (!is_string($secret) || $secret === '') {
            return false;
        }

        if (strpos($value, $secret) !== false) {
            return true;
        }

        $length = min(strlen($value), strlen($secret) - 1);

        for ($n = $length; $n >= self::MIN_SECRET_FRAGMENT; --$n) {
            if (substr($value, -$n) === substr($secret, 0, $n)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A Luhn-valid 13-19 digit run anywhere in the value.
     *
     * The edge screens for a PAN too, but only when the whole value is one:
     * "Card 4111111111111111 was declined" passes it. Card data must never
     * leave a merchant estate, so the client is the right place to enforce it.
     *
     * @param string $value
     *
     * @return bool
     */
    public static function containsCardNumber($value)
    {
        if (!preg_match_all('/\d(?:[ -]?\d){12,18}/', $value, $matches)) {
            return false;
        }

        foreach ($matches[0] as $candidate) {
            if (self::luhnValid((string) preg_replace('/\D/', '', $candidate))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param string $digits
     *
     * @return bool
     */
    private static function luhnValid($digits)
    {
        $length = strlen($digits);

        if ($length < 13 || $length > 19) {
            return false;
        }

        $sum = 0;
        $double = false;

        for ($i = $length - 1; $i >= 0; --$i) {
            $digit = (int) $digits[$i];

            if ($double) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
            $double = !$double;
        }

        return $sum % 10 === 0;
    }

    // ──────────────────────────────────────────────
    // Bounding helpers
    // ──────────────────────────────────────────────

    /**
     * Free-ish text: printable characters only, hard byte cap.
     *
     * UTF-8 is preserved rather than stripped — a Greek or Japanese theme name
     * is one of the more useful diagnostics there is. Only control characters go.
     *
     * @param string $value
     *
     * @return string
     */
    public static function text($value)
    {
        $value = (string) $value;
        $clean = (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $value);

        if ($clean === '' && $value !== '') {
            // Invalid UTF-8 made the unicode-mode replace fail; fall back to ASCII.
            $clean = (string) preg_replace('/[^\x20-\x7E]/', '', $value);
        }

        // mb_strcut cuts on a BYTE budget while respecting codepoint boundaries;
        // mb_substr counts codepoints and would overshoot the edge's byte bound.
        return function_exists('mb_strcut')
            ? mb_strcut($clean, 0, self::MAX_TEXT_BYTES)
            : substr($clean, 0, self::MAX_TEXT_BYTES);
    }

    /**
     * Identifier-shaped values only; anything else is dropped rather than mangled.
     *
     * @param string $value
     *
     * @return string
     */
    public static function identifier($value)
    {
        // \z and /D, not $: PCRE's $ accepts a trailing newline, and these
        // values arrive from upstream JSON.
        return preg_match('/^[A-Za-z0-9_.:-]{1,64}\z/D', (string) $value) ? (string) $value : '';
    }

    /**
     * The class name without its namespace.
     *
     * Public because a call site that must not send an exception's message
     * still wants to name its type — a rejected credential is quoted back in
     * the message but never in the class.
     *
     * @param Throwable $exception
     *
     * @return string
     */
    public static function shortClassName($exception)
    {
        $parts = explode('\\', get_class($exception));
        $name = self::text((string) end($parts));

        return $name !== '' ? $name : 'Throwable';
    }

    /**
     * Attribute a failure to the code that raised it.
     *
     * The commonest support case is another module breaking ours, and the
     * answer is in the stack: the first frame outside our own directory names
     * it. The wire values stay plugin/theme/core/paypercut across every
     * platform so support can compare stores.
     *
     * @param array $files  Absolute paths, innermost first
     *
     * @return array
     */
    public static function origin(array $files)
    {
        $ours = defined('_PS_MODULE_DIR_') ? (string) constant('_PS_MODULE_DIR_') . 'paypercut/' : '';

        foreach ($files as $file) {
            $file = (string) $file;

            if ($ours !== '' && strpos($file, $ours) === 0) {
                continue;
            }

            $modules = defined('_PS_MODULE_DIR_') ? (string) constant('_PS_MODULE_DIR_') : '';
            if ($modules !== '' && strpos($file, $modules) === 0) {
                $relative = ltrim(substr($file, strlen($modules)), '/');
                $parts = explode('/', $relative);

                return array(
                    'origin' => 'plugin',
                    'origin_plugin' => self::text($parts[0]),
                );
            }

            foreach (array('_PS_ALL_THEMES_DIR_', '_PS_THEME_DIR_') as $themeRoot) {
                $prefix = defined($themeRoot) ? (string) constant($themeRoot) : '';
                if ($prefix !== '' && strpos($file, $prefix) === 0) {
                    return array('origin' => 'theme');
                }
            }

            return array('origin' => 'core');
        }

        return array('origin' => 'paypercut');
    }

    /**
     * Absolute file paths from an exception, its own location first.
     *
     * @param Throwable $exception
     *
     * @return array
     */
    private static function frameFiles($exception)
    {
        $files = array($exception->getFile());

        foreach ($exception->getTrace() as $frame) {
            if (isset($frame['file'])) {
                $files[] = (string) $frame['file'];
            }
        }

        return $files;
    }

    /**
     * File and line only, at most MAX_STACK_FRAMES of them.
     *
     * Never getTraceAsString(): that renders call arguments, which here are
     * checkout payloads and credentials.
     *
     * @param Throwable $exception
     *
     * @return array
     */
    private static function stack($exception)
    {
        $frames = array();

        foreach ($exception->getTrace() as $frame) {
            if (count($frames) >= self::MAX_STACK_FRAMES) {
                break;
            }

            if (!isset($frame['file'], $frame['line'])) {
                continue;
            }

            $frames[] = self::relativePath((string) $frame['file']) . ':' . (int) $frame['line'];
        }

        return $frames;
    }

    /**
     * Paths relative to a known root: an absolute path on shared hosting names
     * the merchant's account or domain. Most specific root first.
     *
     * @param string $file
     *
     * @return string
     */
    public static function relativePath($file)
    {
        $file = (string) $file;

        foreach (array('_PS_MODULE_DIR_', '_PS_ALL_THEMES_DIR_', '_PS_THEME_DIR_', '_PS_ROOT_DIR_') as $root) {
            if (!defined($root)) {
                continue;
            }

            $prefix = (string) constant($root);

            if ($prefix !== '' && strpos($file, $prefix) === 0) {
                return ltrim(substr($file, strlen($prefix)), '/');
            }
        }

        return '[external]';
    }

    /**
     * Reduce PHP's fatal message to the part that is not already reported.
     *
     * An uncaught Error arrives with its whole stack trace inlined and every
     * path absolute. Left alone it spends the clamp on frames the stack field
     * already carries, and puts the server's filesystem layout on the wire.
     *
     * @param string $message
     *
     * @return string
     */
    private static function fatalMessage($message)
    {
        $message = (string) $message;
        $trace = strpos($message, 'Stack trace:');

        if ($trace !== false) {
            $message = rtrim(substr($message, 0, $trace));
        }

        foreach (array('_PS_MODULE_DIR_', '_PS_ALL_THEMES_DIR_', '_PS_ROOT_DIR_') as $root) {
            if (!defined($root)) {
                continue;
            }

            $prefix = (string) constant($root);

            if ($prefix !== '') {
                $message = str_replace(rtrim($prefix, '/') . '/', '', $message);
            }
        }

        return $message;
    }

    /**
     * @param array $schema
     * @param array $values
     *
     * @return array
     */
    private static function castFields(array $schema, array $values)
    {
        $fields = array();

        foreach ($schema as $key => $cast) {
            if (!array_key_exists($key, $values)) {
                continue;
            }

            $value = $values[$key];

            if ($cast === 'bool') {
                $fields[$key] = (bool) $value;
                continue;
            }

            if (!is_scalar($value)) {
                continue;
            }

            $clean = $cast === 'identifier'
                ? self::identifier((string) $value)
                : self::text((string) $value);

            if ($clean !== '') {
                $fields[$key] = $clean;
            }
        }

        return $fields;
    }

    /**
     * Merge fields this class derived, without breaking the attribute budget.
     *
     * Derived fields outrank the caller's: over MAX_ATTRS the edge keeps the
     * first attributes in sorted key order and drops the rest, which is exactly
     * how origin / api_code / trace_id — the diagnosis — went missing.
     *
     * @param array $derived
     *
     * @return PaypercutTelemetryEvent
     */
    private function withDerived(array $derived)
    {
        $merged = $derived + $this->fields;

        $this->fields = count($merged) > self::MAX_ATTRS
            ? array_slice($merged, 0, self::MAX_ATTRS, true)
            : $merged;

        return $this;
    }

    /**
     * Bound attributes a call site passed in, rather than trusting them.
     *
     * Booleans and ints are already bounded and pass through intact; strings
     * are clamped and control-stripped; a container is not a scalar diagnostic
     * and is dropped.
     *
     * @param array $attrs
     *
     * @return array
     */
    private static function cleanAttrs(array $attrs)
    {
        $fields = array();

        foreach ($attrs as $key => $value) {
            if (count($fields) >= self::MAX_ATTRS) {
                break;
            }

            $name = self::text((string) $key);

            if ($name === '' || !is_scalar($value)) {
                continue;
            }

            $fields[$name] = is_string($value) ? self::text($value) : $value;
        }

        return $fields;
    }
}
