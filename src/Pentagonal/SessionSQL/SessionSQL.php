<?php
namespace Pentagonal\SessionSQL;

use Pentagonal\SessionSQL\Abstracts\SessionQuery;
use Pentagonal\SessionSQL\Interfaces\SessionHandlerInterface;

class SessionSQL implements SessionHandlerInterface
{
    /**
     * @var object Database Class extends in
     *          \Pentagonal\SessionSQL\Abstracts\SQLQuery
     */
    protected $sqlqueryobject;

    /**
     * @var string session id record
     */
    protected $session_id;

    /**
     * @var string locked as session id record cached
     */
    protected $locked;

    /**
     * determine if data valid
     *
     * @var boolean
     */
    protected $data_valid = false;

    /**
     * @var string fingerprints data hash sha1(data)
     * @uses \Pentagonal\SessionSQL\SessionSQL::generateFingerPrint([string] data);
     */
    protected $fingerprint;

    /**
     * Default configuration
     *
     * @var array
     */
    protected $defaultconfig = array(
        'cookie_name'     => null,
        'cookie_lifetime' => 0,
        'cookie_path'     => '/',
        'cookie_domain'   => null,
        'cookie_secure'   => null,
        'cookie_httponly' => null,
    );

    /**
     * Configuration
     *
     * @var array
     */
    protected $configs = array();

    /**
     * SessionSQL constructor.
     *
     * @param object $sqlqueryobect instance of \Pentagonal\SessionSQL\Abstracts\SessionQuery
     * @param $session_name
     */
    public function __construct(SessionQuery $sqlqueryobect, array $config)
    {
        $this->sqlqueryobject = $sqlqueryobect;
        $this->configs = array_merge($this->defaultconfig, $config);
        $this->configs['cookie_lifetime'] = (
            empty($this->configs['cookie_lifetime']) || is_numeric($this->configs['cookie_lifetime'])
                ? 0
                : time() + $this->configs['cookie_lifetime']
        );
        if ($this->configs['cookie_name'] && is_string($this->configs['cookie_name'])) {
            session_name($this->configs['cookie_name']);
        } else {
            $this->configs['cookie_name'] = ini_get('session.name');
            if ($this->configs['cookie_name']) {
                session_name($this->configs['cookie_name']);
            }
        }
    }

    /**
     * Initial loads
     *
     * @throws \Exception
     */
    public function load()
    {
        static $hasLoaded;
        // prevent multiple call on one instance
        if ($hasLoaded) {
            return;
        }
        // static content
        $hasLoaded = true;
        // Sanitize the cookie, because apparently PHP doesn't do that for userspace handlers
        if ($this->configs['cookie_name'] && isset($_COOKIE[$this->configs['cookie_name']])
            && (
                ! is_string($_COOKIE[$this->configs['cookie_name']])
                // getting cookies values if invalid
                || ! preg_match('/^[0-9a-f]{40}$/', $_COOKIE[$this->configs['cookie_name']])
            )
        ) {
            unset($_COOKIE[$this->configs['cookie_name']]);
        }

        /**
         * if session status is not callable
         */
        if ($this->sessionStatus() === null) {
            throw new \Exception(
                'Function session_status() disabled by the server! Please check your server configuration.',
                E_ERROR
            );
        }

        /**
         * Check whether session is disabled by server
         */
        if (!is_callable('session_start') || $this->sessionStatus() === 0) {
            throw new \Exception(
                'Session disabled by the server! Please check your PHP configuration!',
                E_ERROR
            );
        }

        /**
         * handle session with this class
         */
        session_set_save_handler($this, true);

        // check if session not valid
        if (!$this->configs['cookie_name'] || !is_string($this->configs['cookie_domain'])) {
            $this->configs['cookie_name'] = ini_get('session.name');
            $this->session_name = $this->configs['cookie_name'];
        }

        if (empty($this->configs['session_expiration'])) {
            $this->configs['session_expiration'] = (int) ini_get('session.gc_maxlifetime');
        } else {
            $this->configs['session_expiration'] = (int) $this->configs['session_expiration'];
            ini_set('session.gc_maxlifetime', $this->configs['session_expiration']);
        }

        // Security is king
        ini_set('session.use_trans_sid', 0);
        ini_set('session.use_strict_mode', 1);
        ini_set('session.use_cookies', 1);
        ini_set('session.use_only_cookies', 1);
        ini_set('session.hash_function', 1);
        ini_set('session.hash_bits_per_character', 4);
        ini_set('session.gc_probability', 1);

        // if session is not active start it
        if ($this->sessionStatus() !== 2) {
            // start the session
            session_start();
        }

        // re ^ set session_id;
        $this->session_id = session_id();
        // re set the session
        if (empty($_COOKIE[$this->configs['cookie_name']])
            || $_COOKIE[$this->configs['cookie_name']] !== $this->session_id
        ) {
            setcookie(
                $this->configs['cookie_name'],
                $this->session_id,
                $this->configs['cookie_lifetime'],
                $this->configs['cookie_path'],
                $this->configs['cookie_domain'],
                $this->configs['cookie_secure'],
                $this->configs['cookie_httponly']
            );
        }

    }

    /**
     * Check session status , if callable function of
     *      session_status() it will be retuning integer
     *      null = could not detect session status function
     *      0 = session is disabled
     *      1 = Session Enabled But Not yet Started
     * @return integer|null
     */
    public function sessionStatus()
    {
        static $session_is_callable = null;
        if ($session_is_callable === null) {
            $session_is_callable = function_exists('session_status');
        }
        return ($session_is_callable === true ? session_status() : null);
    }

    /**
     * Generate Finger print
     *
     * @param string $data
     * @return string
     */
    public function generateFingerPrint($data)
    {
        return sha1($data);
    }

    /**
     * Set Fingerprint
     *
     * @param string $data session data
     * @return string
     */
    protected function setFingerPrint($data)
    {
        $this->fingerprint = $this->generateFingerPrint($data);
        return $this->fingerprint;
    }

    /**
     * Getting fingerprint record
     *
     * @return string
     */
    public function getFingerPrint()
    {
        return $this->fingerprint;
    }

    /**
     * Destroy the cookie
     *
     * @return mixed followed setcookie() returned value
     */
    protected function cookieDestroy()
    {
        return setcookie(
            $this->configs['cookie_name'],
            null,
            1, // time must be less than now
            $this->configs['cookie_path'],
            $this->configs['cookie_domain'],
            $this->configs['cookie_secure'],
            $this->configs['cookie_httponly']
        );
    }

    /**
     * Releasing the lock
     *
     * @return bool
     */
    protected function releaseLock()
    {
        $query  = $this->sqlqueryobject->releaseLockQuery($this->session_id, 'lock');
        if ($result = $this->sqlqueryobject->queryResultArray($query)) {
            if (is_array($result) && isset($result['lock']) && $result['lock']) {
                $this->locked = false;
                return true;
            }
        }

        return false;
    }

    /**
     * Get lock
     *
     * Acquires a lock, depending on the underlying platform.
     *
     * @param   string  $session_id Session ID
     * @return  bool
     */
    protected function getLock($session_id)
    {
        $query  = $this->sqlqueryobject->selectQueryGetLock($this->session_id, 'lock');
        if ($result = $this->sqlqueryobject->queryResultArray($query)) {
            if (is_array($result) && isset($result['lock']) && $result['lock']) {
                $this->locked = $session_id;
                return true;
            }
        }

        return false;
    }

    /** =======================================================
     *  --------        -------------------          ----------
     *                | START HANDLER HERE |
     *  --------        -------------------          ----------
     *  =======================================================
     */

    /**
     * Initialize session
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.open.php
     * @param string $save_path The path where to store/retrieve the session.
     * @param string $session_id The session id.
     * @return bool The return value (usually TRUE on success, FALSE on failure).
     *              Note this value is returned internally to PHP for processing.
     */
    public function open($session_path, $session_id)
    {
        $this->session_id = $session_id;
        return (boolean) $this->sqlqueryobject->open($session_path, $session_id);
    }

    /**
     * Read session data
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.read.php
     * @param string $session_id The session id to read data for.
     * @return string Returns an encoded string of the read data.
     *                If nothing was read, it must return an empty string.
     *                Note this value is returned internally to PHP for processing.
     * @throws \Exception
     */
    public function read($session_id)
    {
        if ($this->getLock($session_id)) {
            // Needed by write() to detect session_regenerate_id() calls
            $this->session_id = $session_id;
            // this must be an array
            $result = $this->sqlqueryobject->getFullData($session_id); # array result
            if (empty($result) || ! is_array($result)) {
                if (!empty($result) && !is_array($result)) {
                    throw new \Exception(
                        'Invalid result from queryResultArray(), make sure the handler returning array if not empty',
                        E_USER_ERROR
                    );
                }
                // PHP7 will reuse the same SessionHandler object after
                // ID regeneration, so we need to explicitly set this to
                // FALSE instead of relying on the default ...
                $this->data_valid = false;
                $this->setFingerPrint('');
                return '';
            }

            $result = $this->sqlqueryobject->getData($session_id);
            $this->setFingerPrint($result);
            $this->data_valid = true;
            return $result;
        }

        $this->setFingerPrint('');
        return '';
    }

    /**
     * Write session data
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.write.php
     * @param string $session_id The session id.
     * @param string $session_data
     *
     * The encoded session data. This data is the
     * result of the PHP internally encoding
     * the $_SESSION superglobal to a serialized
     * string and passing it as this parameter.
     * Please note sessions use an alternative serialization method.
     *
     * @return bool The return value (usually TRUE on success, FALSE on failure).
     *              Note this value is returned internally to PHP for processing.
     */
    public function write($session_id, $session_data)
    {
        // Was the ID regenerated?
        if ($session_id !== $this->session_id) {
            if (! $this->releaseLock() || ! $this->getLock($session_id)) {
                return false;
            }

            $this->row_exists = false;
            $this->session_id = $session_id;
        } elseif ($this->locked === false) {
            return false;
        }

        if ($this->data_valid === false
            // force set agains
            || $this->data_valid && ! $this->sqlqueryobject->getFullData($this->session_id)
        ) {
            if ($this->sqlqueryobject->replaceData($this->session_id, $session_data)) {
                $this->setFingerPrint($session_data);
                return $this->data_valid = true;
            }

            return false;
        }

        if ($this->getFingerPrint() != $this->generateFingerPrint($session_data)) {
            if ($this->sqlqueryobject->updateData($this->session_id, $session_data)) {
                $this->setFingerPrint($session_data);
                return $this->data_valid = true;
            }
        }

        return ($this->sqlqueryobject->getCurrentSessionData() == $session_data);
    }

    /**
     * Close the session
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.close.php
     * @return bool The return value (usually TRUE on success, FALSE on failure).
     * Note this value is returned internally to PHP for processing.
     */
    public function close()
    {
        return (bool) ($this->locked)
            ? ($this->data_valid
               && ! $this->sqlqueryobject->getData($this->session_id)
               || $this->releaseLock()
            ) : true;
    }

    /**
     * Destroy a session
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.destroy.php
     * @param string $session_id The session ID being destroyed.
     * @return bool The return value (usually TRUE on success, FALSE on failure).
     *              Note this value is returned internally to PHP for processing.
     */
    public function destroy($session_id)
    {
        if ($this->locked) {
            return $this->sqlqueryobject->remove($session_id)
                ? ($this->close() && $this->cookieDestroy())
                : false;
        }

        return ($this->close() && $this->cookieDestroy());
    }

    /**
     * Cleanup old sessions
     *
     * @link http://php.net/manual/en/sessionhandlerinterface.gc.php
     * @param int $maxlifetime
     *
     * Sessions that have not updated for
     * the last maxlifetime seconds will be removed.
     *
     * @return bool The return value (usually TRUE on success, FALSE on failure).
     *              Note this value is returned internally to PHP for processing.
     */
    public function gc($maxlifetime)
    {
        return $this->sqlqueryobject->removeExpired($maxlifetime);
    }
}
