<?php

/**
 * PaypercutTransaction ObjectModel
 *
 * Stores transaction data linking PS orders to Paypercut payment objects.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaypercutTransaction extends ObjectModel
{
    /** @var int */
    public $id_paypercut_transaction;

    /** @var int */
    public $id_order;

    /** @var int */
    public $id_cart;

    /** @var string */
    public $checkout_id;

    /** @var string */
    public $payment_id;

    /** @var string */
    public $payment_intent_id;

    /** @var string e.g. paid, pending, failed, refunded */
    public $payment_status;

    /** @var string e.g. card, bank_transfer */
    public $payment_method;

    /** @var float */
    public $amount;

    /** @var string 3-letter ISO */
    public $currency;

    /** @var int */
    public $id_shop;

    /** @var string */
    public $date_add;

    /** @var string */
    public $date_upd;

    /**
     * @see ObjectModel::$definition
     */
    public static $definition = array(
        'table' => 'paypercut_transaction',
        'primary' => 'id_paypercut_transaction',
        'fields' => array(
            'id_order' => array(
                'type' => self::TYPE_INT,
                'validate' => 'isUnsignedId',
            ),
            'id_cart' => array(
                'type' => self::TYPE_INT,
                'validate' => 'isUnsignedId',
            ),
            'checkout_id' => array(
                'type' => self::TYPE_STRING,
                'validate' => 'isGenericName',
                'size' => 64,
            ),
            'payment_id' => array(
                'type' => self::TYPE_STRING,
                'validate' => 'isGenericName',
                'size' => 64,
            ),
            'payment_intent_id' => array(
                'type' => self::TYPE_STRING,
                'validate' => 'isGenericName',
                'size' => 64,
            ),
            'payment_status' => array(
                'type' => self::TYPE_STRING,
                'validate' => 'isGenericName',
                'size' => 32,
            ),
            'payment_method' => array(
                'type' => self::TYPE_STRING,
                'validate' => 'isGenericName',
                'size' => 32,
            ),
            'amount' => array(
                'type' => self::TYPE_FLOAT,
                'validate' => 'isPrice',
            ),
            'currency' => array(
                'type' => self::TYPE_STRING,
                'validate' => 'isGenericName',
                'size' => 3,
            ),
            'id_shop' => array(
                'type' => self::TYPE_INT,
                'validate' => 'isUnsignedId',
            ),
            'date_add' => array(
                'type' => self::TYPE_DATE,
                'validate' => 'isDate',
            ),
            'date_upd' => array(
                'type' => self::TYPE_DATE,
                'validate' => 'isDate',
            ),
        ),
    );

    /**
     * Get transaction by PS order ID
     *
     * @param int $idOrder
     *
     * @return PaypercutTransaction|false
     */
    public static function getByOrderId($idOrder)
    {
        $sql = new DbQuery();
        $sql->select('id_paypercut_transaction');
        $sql->from('paypercut_transaction');
        $sql->where('id_order = ' . (int) $idOrder);
        $sql->orderBy('id_paypercut_transaction DESC');

        $id = Db::getInstance()->getValue($sql);

        if ($id) {
            return new self((int) $id);
        }

        return false;
    }

    /**
     * Get transaction by cart ID
     *
     * @param int $idCart
     *
     * @return PaypercutTransaction|false
     */
    public static function getByCartId($idCart)
    {
        $sql = new DbQuery();
        $sql->select('id_paypercut_transaction');
        $sql->from('paypercut_transaction');
        $sql->where('id_cart = ' . (int) $idCart);
        $sql->orderBy('id_paypercut_transaction DESC');

        $id = Db::getInstance()->getValue($sql);

        if ($id) {
            return new self((int) $id);
        }

        return false;
    }

    /**
     * Get transaction by Paypercut checkout ID
     *
     * @param string $checkoutId
     *
     * @return PaypercutTransaction|false
     */
    public static function getByCheckoutId($checkoutId)
    {
        $sql = new DbQuery();
        $sql->select('id_paypercut_transaction');
        $sql->from('paypercut_transaction');
        $sql->where('checkout_id = \'' . pSQL($checkoutId) . '\'');

        $id = Db::getInstance()->getValue($sql);

        if ($id) {
            return new self((int) $id);
        }

        return false;
    }

    /**
     * Get transaction by Paypercut payment ID
     *
     * @param string $paymentId
     *
     * @return PaypercutTransaction|false
     */
    public static function getByPaymentId($paymentId)
    {
        $sql = new DbQuery();
        $sql->select('id_paypercut_transaction');
        $sql->from('paypercut_transaction');
        $sql->where('payment_id = \'' . pSQL($paymentId) . '\'');

        $id = Db::getInstance()->getValue($sql);

        if ($id) {
            return new self((int) $id);
        }

        return false;
    }

    /**
     * Get transaction by payment intent ID
     *
     * @param string $paymentIntentId
     *
     * @return PaypercutTransaction|false
     */
    public static function getByPaymentIntentId($paymentIntentId)
    {
        $sql = new DbQuery();
        $sql->select('id_paypercut_transaction');
        $sql->from('paypercut_transaction');
        $sql->where('payment_intent_id = \'' . pSQL($paymentIntentId) . '\'');

        $id = Db::getInstance()->getValue($sql);

        if ($id) {
            return new self((int) $id);
        }

        return false;
    }

    /**
     * Get all transactions for an order
     *
     * @param int $idOrder
     *
     * @return array
     */
    public static function getAllByOrderId($idOrder)
    {
        $sql = new DbQuery();
        $sql->select('*');
        $sql->from('paypercut_transaction');
        $sql->where('id_order = ' . (int) $idOrder);
        $sql->orderBy('date_add DESC');

        $rows = Db::getInstance()->executeS($sql);

        return $rows ? $rows : array();
    }

    /**
     * Update payment status for a transaction
     *
     * @param string $paymentId
     * @param string $status
     *
     * @return bool
     */
    public static function updateStatusByPaymentId($paymentId, $status)
    {
        return Db::getInstance()->update(
            'paypercut_transaction',
            array('payment_status' => pSQL($status), 'date_upd' => date('Y-m-d H:i:s')),
            'payment_id = \'' . pSQL($paymentId) . '\''
        );
    }
}
