<?php
namespace Pentagonal\SessionSQL\Abstracts;

use Pentagonal\SessionSQL\Interfaces\SessionQueryInterface;

/**
 * abstract Class SessionQuery
 *
 * @package Pentagonal\SessionSQL
 */
abstract class SessionQuery implements SessionQueryInterface
{
    /**
     * @var array|object data of session data from sql
     */
    protected $session_data;
    /**
     * @var string the session id set
     */
    protected $session_id;
    /**
     * @var string the table name
     */
    protected $table;

    /**
     * @var string
     */
    protected $session_lock;

    /**
     * @var string column for session id
     */
    protected $session_id_column;

    /**
     * @param string $session_path the sesion path
     * @param string $session_id session id
     * @return bool
     */
    abstract public function open($session_path, $session_name);

    /**
     * Fix session id
     *
     * @param null $session_id
     * @return null|string
     * @throws \Exception
     */
    final public function sessionIdFixer($session_id = null)
    {
        if ($session_id === null) {
            $session_id = $this->session_id;
        } elseif (!is_string($session_id) && ($this->session_id || session_id())) {
            $session_id = ($this->session_id ? $this->session_id : session_id());
        } elseif (!$this->session_id && session_id()) {
            $session_id = session_id();
        } else {
            throw new \Exception(
                'Invalid session id parameter or session has not been starterd yet!',
                E_USER_ERROR
            );
        }

        return $session_id;
    }

    /**
     * @param string|null $session_id if null will be detect automatic of session id
     * @return string sql query
     * @throws \Exception
     */
    public function selectQueryFromSessionId($session_id = null)
    {
        $session_id = $this->sessionIdFixer($session_id);
        return "SELECT * FROM {$this->table} WHERE {$this->session_id_column}='{$session_id}' LIMIT 1";
    }

    /**
     * @param $session_id
     * @param string $asLock
     * @return string
     * @throws \Exception
     */
    public function selectQueryGetLock($session_name, $asLock = 'lock')
    {
        $this->session_lock = $session_name;
        $asLock = trim($asLock);
        return "SELECT GET_LOCK('{$this->session_id}', 300) AS {$asLock}";
    }

    /**
     * @param $session_id
     * @param string $asLock
     * @return string
     * @throws \Exception
     */
    public function releaseLockQuery($session_id, $asLock = 'lock')
    {
        $session_id = $this->sessionIdFixer($session_id);
        $asLock = trim($asLock);
        return "SELECT RELEASE_LOCK('{$session_id}') AS {$asLock}";
    }

    /**
     * @param string $query sql query
     * @return mixed
     */
    abstract public function queryResultArray($query);

    /**
     * @param string $query sql query
     * @return mixed
     */
    abstract public function queryResultObject($query);

    /**
     * Write data into database
     *
     * @param string $sessionid
     * @param string  $data data to be inserted
     * @return integer|boolean result
     */
    abstract public function writeData($sessionid, $data);

    /**
     * Update data into database
     *
     * @param string $sessionid
     * @param string  $data data to be inserted
     * @return integer|boolean result
     */
    abstract public function updateData($sessionid, $data);

    /**
     * Replace data into database
     *
     * @param string $sessionid
     * @param string  $data data to be inserted
     * @return integer|boolean result
     */
    abstract public function replaceData($sessionid, $data);

    /**
     * Update data into database only change timestamp only
     *
     * @param string $sessionid
     * @return integer|boolean result
     */
    abstract public function updateDataTimeStamp($sessionid);

    /**
     * Remove data by session id
     *
     * @param $sessionid
     * @return boolean
     */
    abstract public function removeData($sessionid);

    /**
     * Remove expire data determine by expired max lifetime
     *
     * @param $maxlifetime
     * @return boolean
     */
    abstract public function removeExpired($maxlifetime);

    /**
     * @return array|object of $this->session_data
     */
    abstract public function getCurrentFullData();

    /**
     * getting session data stored
     *
     * @return string
     */
    abstract public function getCurrentSessionData();

    /**
     * Read session data
     *
     * @param string $sessionid
     * @return string session data
     */
    abstract public function getFullData($sessionid);

    /**
     * read sesion data and returning session data string
     *
     * @param string $session_id
     * @return mixed
     */
    abstract public function getData($session_id);
}
