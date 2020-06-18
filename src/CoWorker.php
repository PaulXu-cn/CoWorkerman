<?php

namespace CoWorkerman;

use Workerman\Connection\ConnectionInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Connection\UdpConnection;
use Workerman\Worker;

use CoWorkerman\Connection\CoTcpConnection;
use CoWorkerman\Coroutine\CoroutineMan;

/**
 * Class CoWorker
 *
 * @package CoWorkerman
 */
class CoWorker extends Worker
{
    use CoroutineMan;

    /**
     * Get global event-loop instance.
     *
     * @return \Workerman\Events\EventInterface|\React\EventLoop\LoopInterface
     */
    public static function getEventLoop()
    {
        return static::$globalEvent;
    }


    /**
     * Get event loop name.
     *
     * @return string
     */
    protected static function getEventLoopName()
    {
        if (static::$eventLoopClass) {
            return static::$eventLoopClass;
        }

        if (!\class_exists('\Swoole\Event', false)) {
            unset(static::$_availableEventLoops['swoole']);
        }

        $loop_name = '';
        foreach (static::$_availableEventLoops as $name=>$class) {
            if (\extension_loaded($name)) {
                $loop_name = $name;
                break;
            }
        }

        if ($loop_name) {
            if (\interface_exists('\React\EventLoop\LoopInterface')) {
                switch ($loop_name) {
                    case 'libevent':
                        static::$eventLoopClass = '\Workerman\Events\React\ExtLibEventLoop';
                        break;
                    case 'event':
//                        static::$eventLoopClass = '\Workerman\Events\React\ExtEventLoop';
                        static::$eventLoopClass = '\CoWorkerman\Events\CoExtEvent';
                        break;
                    default :
//                        static::$eventLoopClass = '\Workerman\Events\React\StreamSelectLoop';
                        static::$eventLoopClass = '\CoWorkerman\Events\CoStreamSelect';
                        break;
                }
            } else {
                static::$eventLoopClass = static::$_availableEventLoops[$loop_name];
            }
        } else {
            static::$eventLoopClass = \interface_exists('\React\EventLoop\LoopInterface') ? '\Workerman\Events\React\StreamSelectLoop' : '\Workerman\Events\Select';
        }
        return static::$eventLoopClass;
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