<?php

/**
 * Storage primitives the telemetry units depend on.
 *
 * PrestaShop has no transient concept and its cache may be non-persistent, so
 * the expiring blobs (token, queue, in-flight batch) are backed by a module
 * table with an expires_at column. A cache flush mid-session would otherwise
 * silently lose the merchant's diagnostics, which is the one failure mode a
 * debug session cannot tolerate.
 *
 * The hot session record lives in Configuration instead: PrestaShop preloads
 * the whole configuration table once per request, so the storefront gate costs
 * nothing. The key is absent from the settings form, so a save cannot write it.
 *
 * Every row is scoped to one shop, because the session record it belongs to is:
 * on a multistore install two shops sharing a token row would trample each
 * other's session.
 *
 * @author    Paypercut <support@paypercut.io>
 * @copyright Paypercut
 * @license   https://mit-license.org ( MIT )
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class PaypercutTelemetryStore
{
    const TABLE = 'paypercut_telemetry_store';

    /**
     * Read an expiring or durable blob.
     *
     * @param string $name
     *
     * @return array|null  null when absent or expired
     */
    public static function get($name)
    {
        $sql = new DbQuery();
        $sql->select('payload, expires_at');
        $sql->from(self::TABLE);
        $sql->where('name = \'' . pSQL($name) . '\'');
        $sql->where('id_shop = ' . (int) self::shopId());

        try {
            $row = Db::getInstance()->getRow($sql);
        } catch (Exception $exception) {
            // Storage being unavailable means no debug session, never a broken
            // payment settings page. Telemetry is diagnostic; it may not take
            // the module's admin screen down with it, exactly as the edge may
            // never block a payment. A store on 1.3.0's files whose upgrade has
            // not run yet has the calls but not the table.
            return null;
        }

        if (!$row) {
            return null;
        }

        // The stored TTL is a backstop, never the authority: every caller
        // re-validates against the session record anyway.
        if ((int) $row['expires_at'] > 0 && (int) $row['expires_at'] <= time()) {
            self::delete($name);

            return null;
        }

        $decoded = json_decode((string) $row['payload'], true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Write a blob. An empty value deletes the row, so an empty queue leaves
     * nothing behind.
     *
     * @param string $name
     * @param array  $value
     * @param int    $ttlSeconds  0 for a durable blob that never expires
     */
    public static function put($name, array $value, $ttlSeconds = 0)
    {
        if (empty($value)) {
            self::delete($name);

            return;
        }

        $payload = json_encode($value);

        if (!is_string($payload)) {
            return;
        }

        $expiresAt = (int) $ttlSeconds > 0 ? time() + (int) $ttlSeconds : 0;

        try {
            Db::getInstance()->execute(
                'REPLACE INTO `' . _DB_PREFIX_ . self::TABLE . '` (`name`, `id_shop`, `payload`, `expires_at`, `date_upd`)'
                . ' VALUES (\'' . pSQL($name) . '\', ' . (int) self::shopId() . ', \'' . pSQL($payload, true) . '\', '
                . (int) $expiresAt . ', \'' . pSQL(date('Y-m-d H:i:s')) . '\')'
            );
        } catch (Exception $exception) {
            // Losing a diagnostic write is a lost diagnostic. Taking the
            // module's admin screen down with it is a broken payment plugin.
        }
    }

    /**
     * @param string $name
     */
    public static function delete($name)
    {
        try {
            Db::getInstance()->delete(
                self::TABLE,
                'name = \'' . pSQL($name) . '\' AND id_shop = ' . (int) self::shopId()
            );
        } catch (Exception $exception) {
            // See put(): storage must not take the admin screen down.
        }
    }

    /**
     * Does a row exist, whatever its payload?
     *
     * @param string $name
     *
     * @return bool
     */
    public static function exists($name)
    {
        return self::get($name) !== null;
    }

    // ──────────────────────────────────────────────
    // Locks
    // ──────────────────────────────────────────────

    /** @var array Owner tokens for the locks this request holds */
    private static $lockOwners = array();

    /**
     * Take a lock that genuinely fails under contention.
     *
     * Deliberately NOT a read-then-write: that would let two clicks in two
     * tabs both mint, and a second minted token is a fully valid credential
     * that no teardown path knows about and nothing can revoke.
     *
     * @param string $name
     * @param int    $ttlSeconds
     *
     * @return bool
     */
    public static function claimLock($name, $ttlSeconds)
    {
        if (self::insertLock($name, $ttlSeconds)) {
            return true;
        }

        if (!self::lockIsStale($name)) {
            return false;
        }

        // An abandoned lock: clear it and try exactly once more, so a crashed
        // request cannot block the feature forever and a live holder is never
        // displaced by an unbounded retry loop.
        self::forceReleaseLock($name);

        return self::insertLock($name, $ttlSeconds);
    }

    /**
     * INSERT IGNORE against the UNIQUE name index, so exactly one
     * concurrent caller sees a row created. IGNORE rather than a bare INSERT
     * because a store in debug mode turns a duplicate-key error into a thrown
     * exception, which would make the lock unusable exactly where it is tested.
     *
     * @param string $name
     * @param int    $ttlSeconds
     *
     * @return bool
     */
    private static function insertLock($name, $ttlSeconds)
    {
        $owner = Tools::passwdGen(16, 'NO_NUMERIC');

        try {
            Db::getInstance()->execute(
                'INSERT IGNORE INTO `' . _DB_PREFIX_ . self::TABLE . '` (`name`, `id_shop`, `payload`, `expires_at`, `date_upd`)'
                . ' VALUES (\'' . pSQL($name) . '\', ' . (int) self::shopId() . ', \'' . pSQL(json_encode(array('owner' => $owner)), true) . '\', '
                . (int) (time() + (int) $ttlSeconds) . ', \'' . pSQL(date('Y-m-d H:i:s')) . '\')'
            );

            if ((int) Db::getInstance()->Affected_Rows() !== 1) {
                return false;
            }
        } catch (Exception $exception) {
            // No lock, no session. The panel reports that; the page still renders.
            return false;
        }

        self::$lockOwners[$name] = $owner;

        return true;
    }

    /**
     * Release a lock only if this request is still the holder: a request that
     * overran the TTL and had its lock stolen must not delete the new holder's.
     *
     * @param string $name
     */
    public static function releaseLock($name)
    {
        if (!isset(self::$lockOwners[$name])) {
            return;
        }

        $owner = self::$lockOwners[$name];
        unset(self::$lockOwners[$name]);

        $held = self::get($name);

        if (is_array($held) && isset($held['owner']) && $held['owner'] !== $owner) {
            return;
        }

        self::forceReleaseLock($name);
    }

    /**
     * @param string $name
     *
     * @return bool
     */
    private static function lockIsStale($name)
    {
        $sql = new DbQuery();
        $sql->select('expires_at');
        $sql->from(self::TABLE);
        $sql->where('name = \'' . pSQL($name) . '\'');
        $sql->where('id_shop = ' . (int) self::shopId());

        try {
            $expiresAt = Db::getInstance()->getValue($sql);
        } catch (Exception $exception) {
            return true;
        }

        if ($expiresAt === false || $expiresAt === null) {
            return true;
        }

        return (int) $expiresAt <= time();
    }

    /**
     * @param string $name
     */
    private static function forceReleaseLock($name)
    {
        self::delete($name);
    }

    // ──────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────

    /**
     * @return int
     */
    public static function shopId()
    {
        $context = Context::getContext();

        if ($context && isset($context->shop) && Validate::isLoadedObject($context->shop)) {
            return (int) $context->shop->id;
        }

        return 1;
    }

    /**
     * Remove every telemetry row for every shop. Used on uninstall.
     */
    public static function purge()
    {
        Db::getInstance()->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . self::TABLE . '`');
    }
}
