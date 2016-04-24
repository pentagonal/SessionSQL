<?php
namespace Pentagonal\SessionSQL\Drivers;

use Pentagonal\SessionSQL\Abstracts\SessionQuery;

class FileDriver extends SessionQuery
{
    protected $file_path;

    protected $session_name;

    protected $handle;

    protected $file_new;

    protected $session_data;

    public function __construct($session_directory = null)
    {
        if ($session_directory) {
            $this->table = $session_directory;
        } else {
            if (isset($_SERVER['DOCUMENT_ROOT'])) {
                if (is_writable($_SERVER['DOCUMENT_ROOT'])
                    && (
                        !is_dir($_SERVER['DOCUMENT_ROOT'].'/Caches')
                        || is_writable($_SERVER['DOCUMENT_ROOT'].'/Caches')
                        )
                    ) {
                    $this->table = realpath($_SERVER['DOCUMENT_ROOT'])
                        .DIRECTORY_SEPARATOR.'Caches'
                        .DIRECTORY_SEPARATOR.'Session';
                }
            }
            if (!$this->table) {
                $this->table = realpath(__DIR__)
                    .DIRECTORY_SEPARATOR.'Caches'
                    .DIRECTORY_SEPARATOR.'Session';
            }
        }
    }

    public function open($session_path, $session_name)
    {
        if (!empty($session_path)) {
            $this->table = $session_path;
        } else {
            $session_path = $this->table;
        }

        // set session save path
        ini_set('session.save_path', $this->table);
        $this->session_name = $session_name;

        if (!is_dir($session_path)) {
            if (!mkdir($session_path, 0700, true)) {
                throw new \Exception(
                    sprintf(
                        "Session: Configured save path <strong>%s</strong> is not a directory, doesn't exist or cannot be created.",
                        $session_path
                    ),
                    E_USER_ERROR
                );
            }
        } elseif (!is_writable($session_path)) {
            throw new \Exception(
                sprintf(
                    "Session: Configured save path <strong>%s</strong> is not writable by the PHP process.",
                    $session_path
                ),
                E_USER_ERROR
            );
        }

        $this->table = $session_path;
        if (!file_exists($this->table.'/index.html')) {
            @file_put_contents($this->table.'/index.html', '');
        }

        if (!file_exists($this->table.'/.htaccess')) {
            @file_put_contents($this->table.'/.htaccess', 'Deny From All');
        }

        if (isset($_SERVER['DOCUMENT_ROOT'])
            && $this->table == realpath($_SERVER['DOCUMENT_ROOT'])
                .DIRECTORY_SEPARATOR.'Caches'
                .DIRECTORY_SEPARATOR.'Session'
            || $this->table == realpath(__DIR__)
                .DIRECTORY_SEPARATOR.'Caches'
                .DIRECTORY_SEPARATOR.'Session'
        ) {
            ! file_exists(dirname($this->table).'/index.html')
                && @file_put_contents(dirname($this->table).'/index.html', '');
            ! file_exists(dirname($this->table).'/.htaccess')
                && @file_put_contents(dirname($this->table).'/.htaccess', 'Deny From All');
        }
        $this->table = realpath($this->table);
        $this->file_path = $this->table . DIRECTORY_SEPARATOR . $session_name;
        return true;
    }

    public function getFullData($session_id)
    {
        // This might seem weird, but PHP 5.6 introduces session_reset(),
        // which re-reads session data
        if ($this->handle === null) {
            // Just using fopen() with 'c+b' mode would be perfect, but it is only
            // available since PHP 5.2.6 and we have to set permissions for new files,
            // so we'd have to hack around this ...
            if (($this->file_new = ! file_exists($this->file_path.$session_id.'.php')) === true) {
                if (($this->handle = @fopen($this->file_path.$session_id.'.php', 'w+b')) === false) {
                    return null;
                }
            } elseif (($this->handle = @fopen($this->file_path.$session_id.'.php', 'r+b')) === false)  {
                return null;
            }

            if (flock($this->handle, LOCK_EX) === false) {
                fclose($this->handle);
                $this->handle = null;
                return null;
            }

            // Needed by write() to detect session_regenerate_id() calls
            $this->session_id = $session_id;

            if ($this->file_new) {
                chmod($this->file_path.$session_id.'.php', 0600);
                return array(
                    'result' => ''
                );
            }
        }  elseif ($this->handle === false) {
            return null;
        } else  {
            rewind($this->handle);
        }

        $session_data = '';
        for ($read = 0, $length = filesize($this->file_path.$session_id.'.php'); $read < $length; $read += strlen($buffer)) {
            if (($buffer = fread($this->handle, $length - $read)) === false) {
                break;
            }

            $session_data .= $buffer;
        }
        if (strlen($session_data) >= 14) {
            $session_data = substr($session_data, 14);
        }
        if (!is_array($this->session_data)) {
            $this->session_data = array(
                'result' => $session_data
            );
        }

        return array(
            'result' => $session_data
        );
    }

    public function getData($session_id)
    {
        $data = $this->getFullData($session_id);
        return is_array($data) && isset($data['result']) ? $data['result'] : null;
    }

    /**
     * @param $session_id
     * @param string $asLock
     * @return string
     * @throws \Exception
     */
    public function selectQueryGetLock($session_id, $asLock = 'lock')
    {
        $asLock = trim($asLock);
        return "GET_LOCK:{$session_id}:{$asLock}";
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
        return "RELEASE_LOCK:{$session_id}:{$asLock}";
    }

    /**
     * @param string|null $session_id if null will be detect automatic of session id
     * @return string sql query
     * @throws \Exception
     */
    public function selectQueryFromSessionId($session_id = null)
    {
        $session_id = $this->sessionIdFixer($this->session_id);
        return "SELECT({$this->table}):{$session_id}";
    }

    /**
     * @param string $query query
     * @return mixed
     */
    public function queryResultArray($query)
    {
        if (strpos($query, ':') === false) {
            return false;
        }
        $explode = explode(':', $query);
        array_filter($explode);
        $explode = array_values($explode);
        $actions = $explode[0];
        $session_id = $explode[1];
        $as = isset($explode[2]) ? $explode[2] : 'result';
        if (strpos($actions, 'SELECT(') !== false) {
            $data = $this->getData($session_id);
            if ($data == false) {
                return null;
            }
            return array(
                $as => $data
            );
        }

        // close handle if get lock
        if (strpos($actions, 'GET_LOCK') === 0) {
            $this->handle = null;
        } else {
            $this->handle = false;
        }
        return array(
            $as => $session_id,
        );
    }

    /**
     * @param string $query query
     * @return mixed
     */
    public function queryResultObject($query)
    {
        $data = $this->queryResultArray($query);
        if (!empty($data)) {
            return json_decode(json_encode($data));
        }
        return null;
    }

    /**
     * Write data into file
     *
     * @param string $session_id
     * @param string  $data data to be inserted
     * @return integer|boolean result
     */
    public function writeData($session_id, $session_data)
    {
        // If the two IDs don't match, we have a session_regenerate_id() call
        // and we need to close the old handle and open a new one
        if ($session_id !== $this->session_id && $this->getData($session_id) === null) {
            return false;
        }

        if ( ! is_resource($this->handle)) {
            return false;
        }

        if (! $this->file_new) {
            ftruncate($this->handle, 0);
            rewind($this->handle);
        }

        if (($length = strlen($session_data)) > 0) {
            $session_data = '<?php exit;?>'.$session_data;
            $result = null;
            for ($written = 0; $written < $length; $written += $result) {
                if (($result = fwrite($this->handle, substr($session_data, $written))) === false) {
                    break;
                }
            }

            if ($result === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Update data into file
     *
     * @param string $session_id
     * @param string  $data data to be inserted
     * @return integer|boolean result
     */
    public function replaceData($session_id, $session_data)
    {
        return $this->updateData($session_id, $session_data);
    }

    /**
     * Update data into database
     *
     * @param string $session_id
     * @param string  $data data to be inserted
     * @return integer|boolean result
     */
    public function updateData($session_id, $session_data)
    {
        // If the two IDs don't match, we have a session_regenerate_id() call
        // and we need to close the old handle and open a new one
        if ($this->getData($session_id) === null) {
            return false;
        }

        if ( ! is_resource($this->handle)) {
            return false;
        }

        if (! $this->file_new) {
            ftruncate($this->handle, 0);
            rewind($this->handle);
        }

        if (($length = strlen($session_data)) > 0) {
            $session_data = '<?php exit;?>'.$session_data;
            $result = null;
            for ($written = 0; $written < $length; $written += $result) {
                if (($result = fwrite($this->handle, substr($session_data, $written))) === false) {
                    break;
                }
            }

            if ($result === null) {
                return false;
            }
        }

        return true;
    }

    /**
     * Remove data by session id
     *
     * @param $session_id
     * @return boolean
     */
    public function removeData($session_id)
    {
        if (!is_file($this->file_path.$session_id.'.php') || unlink($this->file_path.$session_id.'.php')) {
            // must be set false to handle -> because file is not exists anymore
            $this->handle = false;
            return true;
        }

        return false;
    }

    /**
     * Remove expire data determine by expired max lifetime
     *
     * @param $maxlifetime
     * @return boolean
     */
    public function removeExpired($maxlifetime)
    {
        if (! is_dir($this->table) || $directory = opendir($this->table)) {
            return false;
        }

        $ts = time() - $maxlifetime;
        $pattern = sprintf(
            '/^%s[0-9a-f]{40}$/',
            preg_quote($this->session_name, '/')
        );
        while (($file = readdir($directory)) !== false) {
            // If the filename doesn't match this pattern, it's either not a session file or is not ours
            if ( ! preg_match($pattern, $file)
                || ! is_file($this->table.DIRECTORY_SEPARATOR.$file)
                || ($mtime = filemtime($this->table.DIRECTORY_SEPARATOR.$file)) === false
                || $mtime > $ts
            ) {
                continue;
            }

            unlink($this->table.DIRECTORY_SEPARATOR.$file);
        }

        closedir($directory);

        return true;
    }

    /**
     * @return array|object of $this->session_data
     */
    public function getCurrentFullData()
    {
        return $this->session_data;
    }

    /**
     * getting session data stored
     *
     * @return string
     */
    public function getCurrentSessionData()
    {
        return $this->session_data['result'];
    }

    public function updateDataTimeStamp($session_id)
    {
        return true;
    }
}
