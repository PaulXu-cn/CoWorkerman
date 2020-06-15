<?php

namespace CoWorkerman;

use Workerman\Connection\ConnectionInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Connection\UdpConnection;
use Workerman\Worker;

use CoWorkerman\Connection\CoTcpConnection;

require_once __DIR__ . '/Worker.php';

class CoWorker extends Worker
{
    /**
     * the child worker's coroutines
     *
     * @var \Generator[]    $_coroutines
     */
    protected static $_coroutines = array();

    protected static $_coIds = array();

    protected static $_currentCoId = 0;

    protected static $_recycleCoIds = array();

    /**
     * @return int|mixed
     */
    public static function genCoId()
    {
        $newId = -1;
        if (empty(self::$_recycleCoIds)) {
            self::$_coIds[] = null;
            $keys = array_keys(self::$_coIds);
            $newId = array_pop($keys);
            self::$_coIds[$newId] = $newId;
        } else {
            $newId = array_shift(self::$_recycleCoIds);
        }
        self::$_currentCoId = $newId;
        return $newId;
    }

    public static function getCurrentCoId()
    {
        return self::$_currentCoId;
    }

    /**
     * @param integer       $coId
     * @param resource|null $socket
     * @param mixed         $data
     */
    public static function coSend($coId, $socket, $data)
    {
        $gen = self::$_coroutines[$coId];
        $gen->send(array('socket' => $socket, 'data' => $data));
    }

    /**
     * @param \Generator    $generator
     * @param integer       $coId
     */
    public static function addCoroutine($generator, $coId = null)
    {
        if (null === $coId) {
            $coId = self::genCoId();
        }
        self::$_coroutines[$coId] = $generator;
    }

    /**
     * 移除协程
     * @param $coId
     */
    public static function removeCoroutine($coId)
    {
        if (isset(self::$_coroutines[$coId])) {
            // 移除生成器
            unset(self::$_coroutines[$coId]);
            // 回收生成器ID
            self::$_recycleCoIds[$coId] = $coId;
        }
    }

    /**
     * Accept a connection.
     *
     * @param resource $socket
     * @return void
     */
    public function acceptConnection($socket)
    {
        // Accept a connection on server socket.
        \set_error_handler(function(){});
        $new_socket = \stream_socket_accept($socket, 0, $remote_address);
        \restore_error_handler();

        // Thundering herd.
        if (!$new_socket) {
            return;
        }

        // TcpConnection.
        /**
         * @var CoTcpConnection $connection
         */
        $connection                         = new CoTcpConnection($new_socket, $remote_address);
        $this->connections[$connection->id] = $connection;
        $connection->worker                 = $this;
        $connection->protocol               = $this->protocol;
        $connection->transport              = $this->transport;
        $connection->onConnect              = $this->onConnect;
        $connection->onMessage              = $this->onMessage;
        $connection->onClose                = $this->onClose;
        $connection->onError                = $this->onError;
        $connection->onBufferDrain          = $this->onBufferDrain;
        $connection->onBufferFull           = $this->onBufferFull;

        // Try to emit onConnect callback.
        \call_user_func(array($connection, 'newConnect'), $connection);
    }

    /**
     * For udp package.
     *
     * @param resource $socket
     * @return bool
     */
    public function acceptUdpConnection($socket)
    {
        \set_error_handler(function(){});
        $recv_buffer = \stream_socket_recvfrom($socket, static::MAX_UDP_PACKAGE_SIZE, 0, $remote_address);
        \restore_error_handler();
        if (false === $recv_buffer || empty($remote_address)) {
            return false;
        }
        // UdpConnection.
        $connection           = new UdpConnection($socket, $remote_address);
        $connection->protocol = $this->protocol;
        if ($this->onMessage) {
            try {
                if ($this->protocol !== null) {
                    /** @var \Workerman\Protocols\ProtocolInterface $parser */
                    $parser      = $this->protocol;
                    if(\method_exists($parser,'input')){
                        while($recv_buffer !== ''){
                            $len = $parser::input($recv_buffer, $connection);
                            if($len === 0)
                                return true;
                            $package = \substr($recv_buffer,0,$len);
                            $recv_buffer = \substr($recv_buffer,$len);
                            $data = $parser::decode($package,$connection);
                            if ($data === false)
                                continue;
                            \call_user_func($this->onMessage, $connection, $data);
                        }
                    }else{
                        $data = $parser::decode($recv_buffer, $connection);
                        // Discard bad packets.
                        if ($data === false)
                            return true;
                        \call_user_func($this->onMessage, $connection, $data);
                    }
                }else{
                    \call_user_func($this->onMessage, $connection, $recv_buffer);
                }
                ++ConnectionInterface::$statistics['total_request'];
            } catch (\Exception $e) {
                static::log($e);
                exit(250);
            } catch (\Error $e) {
                static::log($e);
                exit(250);
            }
        }
        return true;
    }

}