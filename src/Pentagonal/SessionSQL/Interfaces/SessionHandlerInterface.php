<?php
namespace Pentagonal\SessionSQL\Interfaces;

use SessionHandlerInterface as SessionHandlerInterfaceInternal;

/**
 * Interface SessionHandlerInterface
 *
 * @package Pentagonal\SessionSQL\Interfaces
 */
interface SessionHandlerInterface extends SessionHandlerInterfaceInternal
{
    public function open($save_path, $session_id);
    public function read($session_id);
    public function write($session_id, $session_data);
    public function gc($maxlifetime);
    public function destroy($session_id);
    public function close();
}
