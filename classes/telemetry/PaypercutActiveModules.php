<?php

/**
 * The store's installed modules, for correlating a failure with a conflict.
 *
 * Names and versions only. A module's directory name is public — it is what the
 * Addons marketplace serves it under — but its author and path are not needed
 * to reproduce a conflict.
 *
 * A version is never an empty string: the edge discards an attribute whose
 * value is empty, and it discards the key with it, so a module recorded
 * without a version would arrive as no module at all — which is the one thing
 * this event exists to name.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaypercutActiveModules
{
    /**
     * Stands in for a version PrestaShop never recorded.
     */
    const UNKNOWN_VERSION = 'unknown';

    /**
     * @return array  name => version, sorted by name
     */
    public static function values()
    {
        $sql = new DbQuery();
        $sql->select('name, version');
        $sql->from('module');
        $sql->where('active = 1');

        $rows = Db::getInstance()->executeS($sql);
        $modules = array();

        if (!is_array($rows)) {
            return $modules;
        }

        foreach ($rows as $row) {
            $name = isset($row['name']) ? (string) $row['name'] : '';

            if ($name === '') {
                continue;
            }

            $version = isset($row['version']) ? trim((string) $row['version']) : '';

            $modules[$name] = $version === '' ? self::UNKNOWN_VERSION : $version;
        }

        ksort($modules);

        return $modules;
    }
}
