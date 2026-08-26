<?php

/**
 * Paypercut - Test bootstrap
 *
 * The suite runs without PrestaShop: the telemetry units that carry the privacy
 * contract are pure, and the handful of platform calls they make are stubbed
 * here. Anything that needs a real database belongs on the dev store, not here.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

define('_PS_VERSION_', '8.1.7');
define('_PS_ROOT_DIR_', '/var/www/html');
define('_PS_MODULE_DIR_', '/var/www/html/modules/');
define('_PS_ALL_THEMES_DIR_', '/var/www/html/themes/');
define('_PS_THEME_DIR_', '/var/www/html/themes/classic/');
define('_DB_PREFIX_', 'ps_');

class Tools
{
    public static function strtolower($str)
    {
        return function_exists('mb_strtolower') ? mb_strtolower($str, 'utf-8') : strtolower($str);
    }

    public static function strtoupper($str)
    {
        return function_exists('mb_strtoupper') ? mb_strtoupper($str, 'utf-8') : strtoupper($str);
    }

    public static function passwdGen($length = 8)
    {
        return substr(str_repeat('abcdefghijklmnopqrstuvwxyz', 4), 0, $length);
    }

    public static function safeOutput($str)
    {
        return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
    }

    public static function getValue($key, $default = false)
    {
        return $default;
    }
}

class Translate
{
    public static function getModuleTranslation($module, $string, $source)
    {
        return $string;
    }
}

class Configuration
{
    public static $values = array();

    public static function get($key)
    {
        return isset(self::$values[$key]) ? self::$values[$key] : false;
    }

    public static function updateValue($key, $value)
    {
        self::$values[$key] = $value;

        return true;
    }

    public static function deleteByName($key)
    {
        unset(self::$values[$key]);

        return true;
    }

    public static function hasKey($key)
    {
        return isset(self::$values[$key]);
    }
}

class DbQuery
{
    public function select($x)
    {
        return $this;
    }

    public function from($x)
    {
        return $this;
    }

    public function where($x)
    {
        return $this;
    }
}

/** A store that never has anything in it, so token() resolves to ''. */
class Db
{
    public static function getInstance()
    {
        return new self();
    }

    public function getRow($sql)
    {
        return false;
    }

    public function getValue($sql)
    {
        return false;
    }

    public function execute($sql)
    {
        return true;
    }

    public function delete($table, $where)
    {
        return true;
    }
}

class PrestaShopLogger
{
    public static $lines = array();

    public static function addLog($message, $severity = 1, $errorCode = null, $objectType = null, $objectId = null, $allowDuplicate = false)
    {
        self::$lines[] = $message;
    }
}

class Validate
{
    public static function isLoadedObject($object)
    {
        return is_object($object) && !empty($object->id);
    }
}

class Context
{
    public static function getContext()
    {
        return null;
    }
}

class Paypercut
{
    const VERSION = '1.3.0';
    const CONFIG_API_KEY = 'PAYPERCUT_API_KEY';
    const CONFIG_WEBHOOK_SECRET = 'PAYPERCUT_WEBHOOK_SECRET';
    const CONFIG_ENVIRONMENT = 'PAYPERCUT_ENVIRONMENT';

    public static function moduleVersion()
    {
        return self::VERSION;
    }
}

function pSQL($string, $htmlOk = false)
{
    return addslashes($string);
}

require_once dirname(__FILE__) . '/../classes/telemetry/bootstrap.php';
require_once dirname(__FILE__) . '/Assert.php';
