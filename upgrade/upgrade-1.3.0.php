<?php

/**
 * Paypercut - Upgrade to 1.3.0
 *
 * Adds the debug-session (telemetry) storage table, the connection environment
 * setting, and the back-office hook that carries the running-session notice.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * @param Paypercut $module
 *
 * @return bool
 */
function upgrade_module_1_3_0($module)
{
    $created = Db::getInstance()->execute(
        'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'paypercut_telemetry_store` (
            `id_paypercut_telemetry_store` INT(11) NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(191) NOT NULL,
            `payload` LONGTEXT DEFAULT NULL,
            `expires_at` INT(11) UNSIGNED NOT NULL DEFAULT 0,
            `id_shop` INT(10) UNSIGNED NOT NULL DEFAULT 1,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_paypercut_telemetry_store`),
            UNIQUE KEY `name_shop` (`name`, `id_shop`),
            KEY `expires_at` (`expires_at`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;'
    );

    if (!$created) {
        return false;
    }

    // Existing stores are on production by definition: that is the only host
    // this module has ever talked to.
    if (!Configuration::hasKey(Paypercut::CONFIG_ENVIRONMENT)) {
        Configuration::updateValue(Paypercut::CONFIG_ENVIRONMENT, PaypercutEnvironment::PRODUCTION);
    }

    foreach (Paypercut::OPTIONAL_HOOKS as $hook) {
        $module->registerHook($hook);
    }

    return true;
}
