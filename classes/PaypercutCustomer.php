<?php

/**
 * PaypercutCustomer ObjectModel
 *
 * Maps PrestaShop customer IDs to Paypercut customer IDs.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaypercutCustomer extends ObjectModel
{
    /** @var int */
    public $id_paypercut_customer;

    /** @var int PrestaShop customer ID */
    public $id_customer;

    /** @var string Paypercut customer ID */
    public $paypercut_customer_id;

    /** @var int PrestaShop store / shop ID */
    public $id_shop;

    /** @var string */
    public $date_add;

    /** @var string */
    public $date_upd;

    /**
     * @see ObjectModel::$definition
     */
    public static $definition = array(
        'table' => 'paypercut_customer',
        'primary' => 'id_paypercut_customer',
        'fields' => array(
            'id_customer' => array(
                'type' => self::TYPE_INT,
                'validate' => 'isUnsignedId',
                'required' => true,
            ),
            'paypercut_customer_id' => array(
                'type' => self::TYPE_STRING,
                'validate' => 'isGenericName',
                'required' => true,
                'size' => 64,
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
     * Get Paypercut customer ID by PS customer ID and shop
     *
     * @param int      $idCustomer
     * @param int|null $idShop
     *
     * @return string|false
     */
    public static function getPaypercutId($idCustomer, $idShop = null)
    {
        if (!$idCustomer) {
            return false;
        }

        if ($idShop === null) {
            $idShop = (int) Context::getContext()->shop->id;
        }

        $sql = new DbQuery();
        $sql->select('paypercut_customer_id');
        $sql->from('paypercut_customer');
        $sql->where('id_customer = ' . (int) $idCustomer);
        $sql->where('id_shop = ' . (int) $idShop);

        $result = Db::getInstance()->getValue($sql);

        return $result ? $result : false;
    }

    /**
     * Get or create Paypercut customer record
     *
     * @param int    $idCustomer
     * @param string $paypercutCustomerId
     * @param int    $idShop
     *
     * @return PaypercutCustomer
     */
    public static function getOrCreate($idCustomer, $paypercutCustomerId, $idShop = null)
    {
        if ($idShop === null) {
            $idShop = (int) Context::getContext()->shop->id;
        }

        $existing = self::getByCustomerAndShop($idCustomer, $idShop);

        if ($existing) {
            if ($existing->paypercut_customer_id !== $paypercutCustomerId) {
                $existing->paypercut_customer_id = $paypercutCustomerId;
                $existing->update();
            }

            return $existing;
        }

        $obj = new self();
        $obj->id_customer = (int) $idCustomer;
        $obj->paypercut_customer_id = $paypercutCustomerId;
        $obj->id_shop = (int) $idShop;
        $obj->add();

        return $obj;
    }

    /**
     * Get record by PS customer + shop
     *
     * @param int $idCustomer
     * @param int $idShop
     *
     * @return PaypercutCustomer|false
     */
    public static function getByCustomerAndShop($idCustomer, $idShop)
    {
        $sql = new DbQuery();
        $sql->select('id_paypercut_customer');
        $sql->from('paypercut_customer');
        $sql->where('id_customer = ' . (int) $idCustomer);
        $sql->where('id_shop = ' . (int) $idShop);

        $id = Db::getInstance()->getValue($sql);

        if ($id) {
            return new self((int) $id);
        }

        return false;
    }

    /**
     * Delete all records for a specific shop
     *
     * @param int $idShop
     *
     * @return bool
     */
    public static function deleteByShop($idShop)
    {
        return Db::getInstance()->delete('paypercut_customer', 'id_shop = ' . (int) $idShop);
    }
}
