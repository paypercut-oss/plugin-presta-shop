<?php

/**
 * PaypercutWebhookLog ObjectModel
 *
 * Idempotency log for webhook events to prevent duplicate processing.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaypercutWebhookLog extends ObjectModel
{
    /** @var int */
    public $id_paypercut_webhook_log;

    /** @var string Paypercut event ID */
    public $event_id;

    /** @var string e.g. checkout.completed, payment.paid */
    public $event_type;

    /** @var string processed, failed */
    public $status;

    /** @var string Error message if failed */
    public $error_message;

    /** @var int */
    public $id_shop;

    /** @var string */
    public $date_add;

    /**
     * @see ObjectModel::$definition
     */
    public static $definition = array(
        'table' => 'paypercut_webhook_log',
        'primary' => 'id_paypercut_webhook_log',
        'fields' => array(
            'event_id' => array(
                'type' => self::TYPE_STRING,
                'validate' => 'isGenericName',
                'required' => true,
                'size' => 64,
            ),
            'event_type' => array(
                'type' => self::TYPE_STRING,
                'validate' => 'isGenericName',
                'required' => true,
                'size' => 64,
            ),
            'status' => array(
                'type' => self::TYPE_STRING,
                'validate' => 'isGenericName',
                'size' => 32,
            ),
            'error_message' => array(
                'type' => self::TYPE_STRING,
                'validate' => 'isCleanHtml',
                'size' => 500,
            ),
            'id_shop' => array(
                'type' => self::TYPE_INT,
                'validate' => 'isUnsignedId',
            ),
            'date_add' => array(
                'type' => self::TYPE_DATE,
                'validate' => 'isDate',
            ),
        ),
    );

    /**
     * Check if an event has already been processed
     *
     * @param string $eventId
     *
     * @return bool
     */
    public static function isProcessed($eventId)
    {
        $sql = new DbQuery();
        $sql->select('id_paypercut_webhook_log');
        $sql->from('paypercut_webhook_log');
        $sql->where('event_id = \'' . pSQL($eventId) . '\'');
        $sql->where('status = \'processed\'');

        return (bool) Db::getInstance()->getValue($sql);
    }

    /**
     * Log a webhook event
     *
     * @param string      $eventId
     * @param string      $eventType
     * @param string      $status     processed|failed
     * @param string|null $errorMessage
     * @param int|null    $idShop
     *
     * @return PaypercutWebhookLog
     */
    public static function logEvent($eventId, $eventType, $status = 'processed', $errorMessage = null, $idShop = null)
    {
        if ($idShop === null) {
            $idShop = (int) Context::getContext()->shop->id;
        }

        $log = new self();
        $log->event_id = $eventId;
        $log->event_type = $eventType;
        $log->status = $status;
        $log->error_message = $errorMessage ? Tools::substr($errorMessage, 0, 500) : null;
        $log->id_shop = (int) $idShop;
        $log->add();

        return $log;
    }

    /**
     * Clean up old log entries
     *
     * @param int $daysToKeep  Number of days to retain
     *
     * @return bool
     */
    public static function cleanup($daysToKeep = 90)
    {
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . (int) $daysToKeep . ' days'));

        return Db::getInstance()->delete(
            'paypercut_webhook_log',
            'date_add < \'' . pSQL($cutoff) . '\''
        );
    }

    /**
     * Get recent webhook events (for admin display)
     *
     * @param int $limit
     * @param int $offset
     *
     * @return array
     */
    public static function getRecent($limit = 50, $offset = 0)
    {
        $sql = new DbQuery();
        $sql->select('*');
        $sql->from('paypercut_webhook_log');
        $sql->orderBy('date_add DESC');
        $sql->limit((int) $limit, (int) $offset);

        $rows = Db::getInstance()->executeS($sql);

        return $rows ? $rows : array();
    }
}
