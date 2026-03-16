<?php

/**
 * Paypercut - Database uninstallation
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

$sql = array();

$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'paypercut_customer`;';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'paypercut_transaction`;';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'paypercut_refund`;';
$sql[] = 'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'paypercut_webhook_log`;';

foreach ($sql as $query) {
    if (!Db::getInstance()->execute($query)) {
        return false;
    }
}

return true;
