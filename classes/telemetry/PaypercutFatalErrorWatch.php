<?php

/**
 * Reports the fatal errors a debug session would otherwise never see.
 *
 * A fatal on the checkout page breaks our payment form whichever module raised
 * it, and it never reaches a catch block — so the session sees nothing at all
 * unless the shutdown handler looks.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaypercutFatalErrorWatch
{
    /** The levels that end a request. A warning is noise; these are the bug. */
    const FATAL_LEVELS = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);

    /** @var bool */
    private static $registered = false;

    public static function register()
    {
        if (self::$registered) {
            return;
        }

        self::$registered = true;

        register_shutdown_function(array(__CLASS__, 'report'));
    }

    /**
     * Record the fatal that ended this request, if there was one.
     */
    public static function report()
    {
        $error = error_get_last();

        if ($error === null || !in_array(isset($error['type']) ? $error['type'] : 0, self::FATAL_LEVELS, true)) {
            return;
        }

        if (!PaypercutTelemetrySession::isActiveFast()) {
            return;
        }

        // Registration order between shutdown handlers is not ours to choose,
        // so drain the recorder here rather than assume it has already run.
        PaypercutTelemetryRecorder::persist();

        $event = PaypercutTelemetryEvent::fatal(
            (string) (isset($error['message']) ? $error['message'] : ''),
            (string) (isset($error['file']) ? $error['file'] : ''),
            (int) (isset($error['line']) ? $error['line'] : 0),
            (int) (isset($error['type']) ? $error['type'] : 0)
        );

        // Written directly rather than buffered for a flush that will never
        // come: the request is over.
        PaypercutTelemetryQueue::append(array($event->envelope()));
    }

    /**
     * Test seam.
     */
    public static function reset()
    {
        self::$registered = false;
    }
}
