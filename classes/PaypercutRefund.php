<?php

/**
 * PaypercutRefund ObjectModel
 *
 * Tracks refunds issued through the Paypercut API.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaypercutRefund extends ObjectModel
{
    /** @var int */
    public $id_paypercut_refund;

    /** @var int */
    public $id_order;

    /** @var string */
    public $payment_id;

    /** @var string */
    public $refund_id;

    /** @var float */
    public $amount;

    /** @var string e.g. pending, succeeded, failed */
    public $status;

    /** @var string */
    public $reason;

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
        'table' => 'paypercut_refund',
        'primary' => 'id_paypercut_refund',
        'fields' => array(
            'id_order' => array(
                'type' => self::TYPE_INT,
                'validate' => 'isUnsignedId',
                'required' => true,
            ),
            'payment_id' => array(
                'type' => self::TYPE_STRING,
                'validate' => 'isGenericName',
                'required' => true,
                'size' => 64,
            ),
            'refund_id' => array(
                'type' => self::TYPE_STRING,
                'validate' => 'isGenericName',
                'size' => 64,
            ),
            'amount' => array(
                'type' => self::TYPE_FLOAT,
                'validate' => 'isPrice',
                'required' => true,
            ),
            'status' => array(
                'type' => self::TYPE_STRING,
                'validate' => 'isGenericName',
                'size' => 32,
            ),
            'reason' => array(
                'type' => self::TYPE_STRING,
                'validate' => 'isCleanHtml',
                'size' => 255,
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
     * Get all refunds for an order
     *
     * @param int $idOrder
     *
     * @return array
     */
    public static function getByOrderId($idOrder)
    {
        $sql = new DbQuery();
        $sql->select('*');
        $sql->from('paypercut_refund');
        $sql->where('id_order = ' . (int) $idOrder);
        $sql->orderBy('date_add DESC');

        $rows = Db::getInstance()->executeS($sql);

        return $rows ? $rows : array();
    }

    /**
     * Get refund by Paypercut refund ID
     *
     * @param string $refundId
     *
     * @return PaypercutRefund|false
     */
    public static function getByRefundId($refundId)
    {
        $sql = new DbQuery();
        $sql->select('id_paypercut_refund');
        $sql->from('paypercut_refund');
        $sql->where('refund_id = \'' . pSQL($refundId) . '\'');

        $id = Db::getInstance()->getValue($sql);

        if ($id) {
            return new self((int) $id);
        }

        return false;
    }

    /**
     * Get total refunded amount for an order
     *
     * @param int $idOrder
     *
     * @return float
     */
    public static function getTotalRefunded($idOrder)
    {
        $sql = new DbQuery();
        $sql->select('SUM(amount)');
        $sql->from('paypercut_refund');
        $sql->where('id_order = ' . (int) $idOrder);
        $sql->where('status IN (\'pending\', \'succeeded\')');

        $total = Db::getInstance()->getValue($sql);

        return $total ? (float) $total : 0.0;
    }

    /**
     * Update refund status
     *
     * @param string $refundId
     * @param string $status
     *
     * @return bool
     */
    public static function updateStatusByRefundId($refundId, $status)
    {
        return Db::getInstance()->update(
            'paypercut_refund',
            array('status' => pSQL($status), 'date_upd' => date('Y-m-d H:i:s')),
            'refund_id = \'' . pSQL($refundId) . '\''
        );
    }
}
