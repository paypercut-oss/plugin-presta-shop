<?php

/**
 * Paypercut telemetry - class loader
 *
 * PrestaShop does not autoload module classes, so every telemetry unit is
 * required from here and call sites include this one file.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/../PaypercutEnvironment.php';
require_once dirname(__FILE__) . '/../PaypercutApiException.php';
require_once dirname(__FILE__) . '/PaypercutTelemetryEvent.php';
require_once dirname(__FILE__) . '/PaypercutTelemetryStore.php';
require_once dirname(__FILE__) . '/PaypercutTelemetrySession.php';
require_once dirname(__FILE__) . '/PaypercutTelemetryQueue.php';
require_once dirname(__FILE__) . '/PaypercutTelemetryRecorder.php';
require_once dirname(__FILE__) . '/PaypercutSentLog.php';
require_once dirname(__FILE__) . '/PaypercutTelemetryHttp.php';
require_once dirname(__FILE__) . '/PaypercutTokenMinter.php';
require_once dirname(__FILE__) . '/PaypercutMintErrorMapper.php';
require_once dirname(__FILE__) . '/PaypercutEdgeClient.php';
require_once dirname(__FILE__) . '/PaypercutTelemetryFlusher.php';
require_once dirname(__FILE__) . '/PaypercutEnvironmentSnapshot.php';
require_once dirname(__FILE__) . '/PaypercutActiveModules.php';
require_once dirname(__FILE__) . '/PaypercutFatalErrorWatch.php';
require_once dirname(__FILE__) . '/PaypercutTelemetryAdmin.php';
