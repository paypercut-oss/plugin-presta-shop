<?php

/**
 * Paypercut API Exception
 *
 * Carries the structured fields a Paypercut error response returns, so a
 * failure can be diagnosed without quoting the platform's prose back — the
 * platform repeats submitted input inside its messages.
 *
 * Extends Exception so every existing `catch (Exception $e)` still applies.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaypercutApiException extends Exception
{
    /** @var int */
    private $statusCode;

    /** @var string */
    private $errorType;

    /** @var string */
    private $errorCode;

    /** @var string */
    private $param;

    /** @var string */
    private $traceId;

    /**
     * @param string $message
     * @param int    $statusCode
     * @param array  $body       Decoded error body, empty when unparsable
     * @param string $traceId
     */
    public function __construct($message, $statusCode = 0, array $body = array(), $traceId = '')
    {
        parent::__construct($message);

        $error = isset($body['error']) && is_array($body['error']) ? $body['error'] : $body;

        $this->statusCode = (int) $statusCode;
        $this->errorType = isset($error['type']) && is_scalar($error['type']) ? (string) $error['type'] : '';
        $this->errorCode = isset($error['code']) && is_scalar($error['code']) ? (string) $error['code'] : '';
        $this->param = isset($error['param']) && is_scalar($error['param']) ? (string) $error['param'] : '';

        if ($traceId === '' && isset($body['trace_id']) && is_scalar($body['trace_id'])) {
            $traceId = (string) $body['trace_id'];
        }

        $this->traceId = (string) $traceId;
    }

    /**
     * @return int  0 when the request never completed
     */
    public function getStatusCode()
    {
        return $this->statusCode;
    }

    /**
     * @return string
     */
    public function getErrorType()
    {
        return $this->errorType;
    }

    /**
     * @return string
     */
    public function getErrorCode()
    {
        return $this->errorCode;
    }

    /**
     * @return string
     */
    public function getParam()
    {
        return $this->param;
    }

    /**
     * @return string
     */
    public function getTraceId()
    {
        return $this->traceId;
    }
}
