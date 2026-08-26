<?php

/**
 * The raw HTTP client the telemetry units use.
 *
 * Deliberately NOT PaypercutApi: that client throws on exactly the statuses we
 * need to branch on, shares its 30-second timeout budget with the payment
 * paths, and now reports its own failures as telemetry events — which would
 * recurse. A credential travels on the mint request, so this stays on plain
 * cURL with no hookable wrapper in between.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaypercutTelemetryHttp
{
    /**
     * POST a JSON body. Never throws, whatever the status.
     *
     * @param string      $url
     * @param array       $headers         Raw header lines
     * @param string|null $jsonBody        null means no body at all (the mint takes none)
     * @param int         $timeout
     * @param int         $connectTimeout
     *
     * @return array  { status, headers, body, duration_ms }; status 0 means the
     *                request never completed
     */
    public static function postJson($url, array $headers, $jsonBody, $timeout, $connectTimeout)
    {
        $startedAt = microtime(true);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, (int) $timeout);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, (int) $connectTimeout);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($jsonBody !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $jsonBody);
        } else {
            // The mint endpoint takes no body at all; sending [] or {} is a
            // different request.
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $failed = $response === false || curl_errno($ch) !== 0;
        curl_close($ch);

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if ($failed || $status === 0) {
            return array(
                'status' => 0,
                'headers' => array(),
                'body' => '',
                'duration_ms' => $durationMs,
            );
        }

        return array(
            'status' => $status,
            'headers' => self::parseHeaders(substr((string) $response, 0, $headerSize)),
            'body' => (string) substr((string) $response, $headerSize),
            'duration_ms' => $durationMs,
        );
    }

    /**
     * @param string $raw
     *
     * @return array  Lowercased header name => last value
     */
    private static function parseHeaders($raw)
    {
        $headers = array();

        foreach (preg_split('/\r?\n/', (string) $raw) as $line) {
            $separator = strpos($line, ':');

            if ($separator === false) {
                continue;
            }

            $name = Tools::strtolower(trim(substr($line, 0, $separator)));
            $headers[$name] = trim(substr($line, $separator + 1));
        }

        return $headers;
    }

    /**
     * @param array  $headers
     * @param string $name
     *
     * @return string
     */
    public static function header(array $headers, $name)
    {
        $name = Tools::strtolower($name);

        return isset($headers[$name]) ? (string) $headers[$name] : '';
    }
}
