<?php
namespace Pentagonal\SessionSQL\Interfaces;

/**
 * Interface SQLQueryInterface
 *
 * @package Pentagonal\SessionSQL
 */
interface SessionQueryInterface
{
    /**
     * @param string $session_path the sesion path
     * @param string $session_id session id
     * @return bool
     */
    public function open($session_path, $session_id);

    /**
     * @param string $query sql query
     * @return mixed
     */
    public function queryResultArray($query);

    /**
     * @param string $query sql query
     * @return mixed
     */
    public function queryResultObject($query);

    /**
     * Write data into database
     *
     * @param string $sessionid
     * @param string  $data data to be inserted
     * @return integer|boolean result
     */
    public function writeData($sessionid, $data);

    /**
     * Update data into database
     *
     * @param string $sessionid
     * @param string  $data data to be inserted
     * @return integer|boolean result
     */
    public function updateData($sessionid, $data);

    /**
     * Replace data into database
     *
     * @param string $sessionid
     * @param string  $data data to be inserted
     * @return integer|boolean result
     */
    public function replaceData($sessionid, $data);

    /**
     * Update data into database only change timestamp only
     *
     * @param string $sessionid
     * @return integer|boolean result
     */
    public function updateDataTimeStamp($sessionid);

    /**
     * Remove data by session id
     *
     * @param $sessionid
     * @return boolean
     */
    public function removeData($sessionid);

    /**
     * Remove expire data determine by expired max lifetime
     *
     * @param $maxlifetime
     * @return boolean
     */
    public function removeExpired($maxlifetime);
}
