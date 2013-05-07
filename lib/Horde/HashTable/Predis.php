<?php
/**
 * Copyright 2013 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file COPYING for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category  Horde
 * @copyright 2013 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   HashTable
 */

/**
 * Implementation of HashTable for a Redis server (using the Predis library).
 *
 * See: https://github.com/nrk/predis
 *
 * @author    Michael Slusarz <slusarz@horde.org>
 * @category  Horde
 * @copyright 2013 Horde LLC
 * @license   http://www.horde.org/licenses/lgpl21 LGPL 2.1
 * @package   Memcache
 */
class Horde_HashTable_Predis extends Horde_HashTable implements Horde_HashTable_Lock
{
    /* Suffix added to key to create the lock entry. */
    const LOCK_SUFFIX = '_l';

    /* Lock timeout (in seconds). */
    const LOCK_TIMEOUT = 30;

    /**
     * Locked keys.
     *
     * @var array
     */
    protected $_locks = array();

    /**
     * Predis client object.
     *
     * @var Predis\Client
     */
    protected $_predis;

    /**
     * @param array $params  Additional configuration parameters:
     * <pre>
     *   - predis: (Predis\Client) [REQUIRED] Predis client object.
     * </pre>
     */
    public function __construct(array $params = array())
    {
        if (!isset($params['predis'])) {
            throw InvalidArgumentException('Missing predis parameter.');
        }

        parent::__construct($params);

        register_shutdown_function(array($this, 'shutdown'));
    }

    /**
     */
    protected function _init()
    {
        $this->_predis = $this->_params['predis'];
    }

    /**
     * Shutdown function.
     */
    public function shutdown()
    {
        foreach (array_keys($this->_locks) as $key) {
            $this->unlock($key);
        }
    }

    /**
     */
    protected function _delete($keys)
    {
        return (count($keys) == $this->_predis->del($keys));
    }

    /**
     */
    protected function _get($keys)
    {
        $keys = array_values($keys);
        $out = array();

        foreach ($this->_predis->mget($keys) as $key => $val) {
            $out[$keys[$key]] = is_null($val)
                ? false
                : $val;
        }

        return $out;
    }

    /**
     */
    protected function _set($key, $val, $opts)
    {
        if (!empty($opts['replace']) && !$this->_predis->exists($key)) {
            return false;
        }

        /* Can't use SETEX, since 2.0 server is not guaranteed. */
        if (!$this->_predis->set($key, $val)) {
            return false;
        }

        if (!empty($opts['expire'])) {
            $this->_predis->expire($key, $opts['expire']);
        }

        return true;
    }

    /**
     */
    public function clear()
    {
        $res = $this->_predis->keys(addcslashes(strval($this->_params['prefix']), '?*') . '*');

        /* Before 2.0, KEYS returns a space-delimited string. */
        if (is_string($res)) {
            $res = explode(' ', $res);
        }

        $this->_predis->del($res);
    }

    /**
     */
    public function hkey($key)
    {
        /* Key is MD5 encoded. But don't MD5 encode the prefix part, or else
         * clear() won't work properly. */
        return $this->_prefix . hash('md5', $key);
    }

    /**
     */
    public function lock($key)
    {
        $hkey = $this->hkey($key) . self::LOCK_SUFFIX;

        while (!$this->_predis->setnx($hkey, 1)) {
            /* Wait 0.1 secs before attempting again. */
            usleep(100000);
        }

        $this->_predis->expire($hkey, self::LOCK_TIMEOUT);
        $this->_locks[$key] = true;
    }

    /**
     */
    public function unlock($key)
    {
        $this->_predis->del($this->hkey($key) . self::LOCK_SUFFIX);
        $this->_locks[$key] = false;
    }

}
