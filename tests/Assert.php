<?php

/**
 * Paypercut - Minimal assertion helper
 *
 * The module has no Composer dependencies and must stay installable by copying
 * the folder, so the suite ships its own three-function runner rather than a
 * vendored test framework.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

class Assert
{
    public static $passed = 0;

    public static $failures = array();

    /** @var string */
    public static $suite = '';

    public static function suite($name)
    {
        self::$suite = $name;
    }

    public static function true($condition, $message)
    {
        if ($condition) {
            ++self::$passed;

            return;
        }

        self::$failures[] = self::$suite . ': ' . $message;
    }

    public static function false($condition, $message)
    {
        self::true(!$condition, $message);
    }

    public static function same($expected, $actual, $message)
    {
        if ($expected === $actual) {
            ++self::$passed;

            return;
        }

        self::$failures[] = self::$suite . ': ' . $message
            . "\n      expected: " . var_export($expected, true)
            . "\n      actual:   " . var_export($actual, true);
    }

    public static function report()
    {
        echo self::$passed . " assertions passed\n";

        if (empty(self::$failures)) {
            echo "OK\n";

            return 0;
        }

        echo count(self::$failures) . " FAILED\n";

        foreach (self::$failures as $failure) {
            echo '  - ' . $failure . "\n";
        }

        return 1;
    }
}
