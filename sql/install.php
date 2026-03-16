<?php

/**
 * Paypercut - Database installation
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

$sql = array();

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'paypercut_customer` (
    `id_paypercut_customer` INT(11) NOT NULL AUTO_INCREMENT,
    `id_customer` INT(10) UNSIGNED NOT NULL,
    `paypercut_customer_id` VARCHAR(255) NOT NULL,
    `id_shop` INT(10) UNSIGNED NOT NULL DEFAULT 1,
    `email` VARCHAR(255) DEFAULT NULL,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_paypercut_customer`),
    KEY `id_customer` (`id_customer`),
    KEY `id_shop` (`id_shop`),
    UNIQUE KEY `paypercut_customer_id` (`paypercut_customer_id`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'paypercut_transaction` (
    `id_paypercut_transaction` INT(11) NOT NULL AUTO_INCREMENT,
    `id_order` INT(10) UNSIGNED NOT NULL,
    `id_cart` INT(10) UNSIGNED NOT NULL DEFAULT 0,
    `checkout_id` VARCHAR(255) DEFAULT NULL,
    `payment_id` VARCHAR(255) DEFAULT NULL,
    `payment_intent_id` VARCHAR(255) DEFAULT NULL,
    `payment_method` VARCHAR(64) DEFAULT NULL,
    `payment_method_details` TEXT DEFAULT NULL,
    `amount` INT(11) NOT NULL DEFAULT 0,
    `currency` VARCHAR(3) DEFAULT NULL,
    `payment_status` VARCHAR(32) DEFAULT NULL,
    `paypercut_customer_id` VARCHAR(255) DEFAULT NULL,
    `id_shop` INT(10) UNSIGNED NOT NULL DEFAULT 1,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_paypercut_transaction`),
    KEY `id_order` (`id_order`),
    KEY `id_cart` (`id_cart`),
    KEY `id_shop` (`id_shop`),
    KEY `checkout_id` (`checkout_id`),
    KEY `payment_id` (`payment_id`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'paypercut_refund` (
    `id_paypercut_refund` INT(11) NOT NULL AUTO_INCREMENT,
    `id_order` INT(10) UNSIGNED NOT NULL,
    `payment_id` VARCHAR(255) NOT NULL,
    `refund_id` VARCHAR(255) NOT NULL,
    `amount` INT(11) NOT NULL DEFAULT 0,
    `currency` VARCHAR(3) DEFAULT NULL,
    `reason` TEXT DEFAULT NULL,
    `status` VARCHAR(32) DEFAULT NULL,
    `id_shop` INT(10) UNSIGNED NOT NULL DEFAULT 1,
    `date_add` DATETIME NOT NULL,
    `date_upd` DATETIME NOT NULL,
    PRIMARY KEY (`id_paypercut_refund`),
    KEY `id_order` (`id_order`),
    KEY `id_shop` (`id_shop`),
    KEY `payment_id` (`payment_id`),
    UNIQUE KEY `refund_id` (`refund_id`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

$sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'paypercut_webhook_log` (
    `id_paypercut_webhook_log` INT(11) NOT NULL AUTO_INCREMENT,
    `event_id` VARCHAR(255) NOT NULL,
    `event_type` VARCHAR(64) NOT NULL,
    `payment_id` VARCHAR(255) DEFAULT NULL,
    `id_order` INT(10) UNSIGNED DEFAULT NULL,
    `status` VARCHAR(32) DEFAULT NULL,
    `error_message` VARCHAR(500) DEFAULT NULL,
    `processed` TINYINT(1) NOT NULL DEFAULT 0,
    `raw_payload` TEXT DEFAULT NULL,
    `id_shop` INT(10) UNSIGNED NOT NULL DEFAULT 1,
    `date_add` DATETIME NOT NULL,
    PRIMARY KEY (`id_paypercut_webhook_log`),
    UNIQUE KEY `event_id` (`event_id`),
    KEY `id_shop` (`id_shop`),
    KEY `event_type` (`event_type`)
) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

foreach ($sql as $query) {
    if (!Db::getInstance()->execute($query)) {
        return false;
    }
}

return true;
