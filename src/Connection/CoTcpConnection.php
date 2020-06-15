<?php

namespace CoWorkerman\Connection;

use Workerman\Events\EventInterface;
use Workerman\Worker;
use Workerman\Connection\TcpConnection;

use CoWorkerman\CoWorker;


class CoTcpConnection extends TcpConnection
{
    protected static $_coroutines = array();

    protected static $_connectionSocketToCoId = array();

    protected $_coroutineId;

    protected $_socketIDtoCoId = array();

    /**
     * @var null|callable $onConnect
     */
    public $onConnect = null;

//    protected static $_connections = array();

    /**
     * CoTcpConnection constructor.
     *
     * @param resource $socket
     * @param string   $remote_address
     * @param integer  $coId
     */
    public function __construct($socket, $remote_address = '', $coId = 0)
    {
        $this->_coroutineId = $coId;
        parent::__construct($socket, $remote_address);
    }

    /**
     * @param $coId
     * @param $socket
     * @param $data
     */
    public static function coSend($coId, $socket, $data)
    {
        $gen = self::$_coroutines[$coId];
        $gen->send(array('socket' => $socket, 'data' => $data));
    }

    /**
     * @param   CoTcpConnection $connection
     */
    public function newConnect($connection)
    {
        // Try to emit onConnect callback.
        if ($this->onConnect) {
            try {
                $gen = \call_user_func($this->onConnect, $this);
                if ($gen instanceof \Generator) {
                    $coId = Coworker::genCoId();
                    self::$_connectionSocketToCoId[(string) $connection->getSocket()] = $coId;
                    self::$_coroutines[$coId] = $gen;
                    CoWorker::addCoroutine($gen, $coId);
                    // run coroutine
                    $yieldRe = $gen->current();
                }
            } catch (\Exception $e) {
                static::log($e);
                exit(250);
            } catch (\Error $e) {
                static::log($e);
                exit(250);
            }
        }
    }

    /**
     * 发来的消息，
     *
     * @param $connection
     * @param $data
     */
    protected function _onMessage($connection, $data)
    {
        if (!$this->onMessage) {
            return;
        }
        try {
            // Decode request buffer before Emitting onMessage callback.
            $gen = \call_user_func($this->onMessage, $connection, $data);
            if ($gen instanceof \Generator) {
                $coId = Coworker::genCoId();
                self::$_coroutines[$coId] = $gen;
                // run coroutine
                $gen->current();
            }
        } catch (\Exception $e) {
            Worker::log($e);
            exit(250);
        } catch (\Error $e) {
            Worker::log($e);
            exit(250);
        }
    }

    /**
     * @param   resource    $socket
     */
    protected static function removeGeneratorBySocket($socket)
    {
        $coId = null;
        if (isset(self::$_connectionSocketToCoId[(string) $socket])) {
            $coId = self::$_connectionSocketToCoId[(string) $socket];
        }
        if (isset(self::$_coroutines[$coId])) {
            unset(self::$_coroutines[$coId]);
            CoWorker::removeCoroutine($coId);
        }
    }

    /**
     * 关闭链接
     *
     * @param   CoTcpConnection     $connection
     */
    protected function _onClose($connection)
    {
        self::removeGeneratorBySocket($connection->getSocket());
        if ($this->onClose) {
            try {
                $gen = \call_user_func($this->onClose, $connection);
                if ($gen instanceof \Generator) {
                    $coId = Coworker::genCoId();
                    self::$_coroutines[$coId] = $gen;
                    // run coroutine
                    $gen->current();
                }
            } catch (\Exception $e) {
                Worker::log($e);
                exit(250);
            } catch (\Error $e) {
                Worker::log($e);
                exit(250);
            }
        }
    }

    /**
     * 报错了
     *
     * @param $connection
     * @param $code
     * @param $msg
     */
    protected function _onError($connection, $code, $msg)
    {
        if ($this->onError) {
            try {
                $gen = \call_user_func($this->onError, $connection, $code, $msg);
                if ($gen instanceof \Generator) {
                    $coId = Coworker::genCoId();
                    self::$_coroutines[$coId] = $gen;
                    // run coroutine
                    $gen->current();
                }
            } catch (\Exception $e) {
                Worker::log($e);
                exit(250);
            } catch (\Error $e) {
                Worker::log($e);
                exit(250);
            }
        }
    }

    /**
     * Sends data on the connection.
     *
     * @param mixed $send_buffer
     * @param bool  $raw
     * @return bool|null
     */
    public function sendAsync($send_buffer, $raw = false)
    {
        if (false) {
            yield;
        }
        if ($this->_status === self::STATUS_CLOSING || $this->_status === self::STATUS_CLOSED) {
            return false;
        }

        // Try to call protocol::encode($send_buffer) before sending.
        if (false === $raw && $this->protocol !== null) {
            $parser = $this->protocol;
            $send_buffer = $parser::encode($send_buffer, $this);
            if ($send_buffer === '') {
                return;
            }
        }

        if ($this->_status !== self::STATUS_ESTABLISHED ||
            ($this->transport === 'ssl' && $this->_sslHandshakeCompleted !== true)
        ) {
            if ($this->_sendBuffer && $this->bufferIsFull()) {
                ++self::$statistics['send_fail'];
                return false;
            }
            $this->_sendBuffer .= $send_buffer;
            $this->checkBufferWillFull();
            return;
        }

        // Attempt to send data directly.
        if ($this->_sendBuffer === '') {
            if ($this->transport === 'ssl') {
                Worker::$globalEvent->add($this->_socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
                $this->_sendBuffer = $send_buffer;
                $this->checkBufferWillFull();
                return;
            }
            $len = 0;
            try {
                $len = @\fwrite($this->_socket, $send_buffer);
            } catch (\Exception $e) {
                Worker::log($e);
            } catch (\Error $e) {
                Worker::log($e);
            }
            // send successful.
            if ($len === \strlen($send_buffer)) {
                $this->bytesWritten += $len;
                return true;
            }
            // Send only part of the data.
            if ($len > 0) {
                $this->_sendBuffer = \substr($send_buffer, $len);
                $this->bytesWritten += $len;
            } else {
                // Connection closed?
                if (!\is_resource($this->_socket) || \feof($this->_socket)) {
                    ++self::$statistics['send_fail'];
                    /*
                    if ($this->onError) {
                        try {
                            \call_user_func($this->onError, $this, \WORKERMAN_SEND_FAIL, 'client closed');
                        } catch (\Exception $e) {
                            Worker::log($e);
                            exit(250);
                        } catch (\Error $e) {
                            Worker::log($e);
                            exit(250);
                        }
                    } */
                    $this->_onError($this, \WORKERMAN_SEND_FAIL, 'client closed');
                    $this->destroy();
                    return false;
                }
                $this->_sendBuffer = $send_buffer;
            }
            Worker::$globalEvent->add($this->_socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
            // Check if the send buffer will be full.
            $this->checkBufferWillFull();
            return;
        }

        if ($this->bufferIsFull()) {
            ++self::$statistics['send_fail'];
            return false;
        }

        $this->_sendBuffer .= $send_buffer;
        // Check if the send buffer is full.
        $this->checkBufferWillFull();
    }

    /**
     * Base read handler.
     *
     * @param resource $socket
     * @param bool     $check_eof
     * @return void
     */
    public function baseRead($socket, $check_eof = true)
    {
        // SSL handshake.
        if ($this->transport === 'ssl' && $this->_sslHandshakeCompleted !== true) {
            if ($this->doSslHandshake($socket)) {
                $this->_sslHandshakeCompleted = true;
                if ($this->_sendBuffer) {
                    Worker::$globalEvent->add($socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
                }
            } else {
                return;
            }
        }

        $buffer = '';
        try {
            $buffer = @\fread($socket, self::READ_BUFFER_SIZE);
        } catch (\Exception $e) {
        } catch (\Error $e) {
        }

        // Check connection closed.
        if ($buffer === '' || $buffer === false) {
            if ($check_eof && (\feof($socket) || !\is_resource($socket) || $buffer === false)) {
                $this->destroy();
                return;
            }
        } else {
            $this->bytesRead += \strlen($buffer);
            $this->_recvBuffer .= $buffer;
        }

        // If the application layer protocol has been set up.
        if ($this->protocol !== null) {
            $parser = $this->protocol;
            while ($this->_recvBuffer !== '' && !$this->_isPaused) {
                // The current packet length is known.
                if ($this->_currentPackageLength) {
                    // Data is not enough for a package.
                    if ($this->_currentPackageLength > \strlen($this->_recvBuffer)) {
                        break;
                    }
                } else {
                    // Get current package length.
                    try {
                        $this->_currentPackageLength = $parser::input($this->_recvBuffer, $this);
                    } catch (\Exception $e) {
                    } catch (\Error $e) {
                    }
                    // The packet length is unknown.
                    if ($this->_currentPackageLength === 0) {
                        break;
                    } elseif ($this->_currentPackageLength > 0 && $this->_currentPackageLength <= $this->maxPackageSize) {
                        // Data is not enough for a package.
                        if ($this->_currentPackageLength > \strlen($this->_recvBuffer)) {
                            break;
                        }
                    } // Wrong package.
                    else {
                        Worker::safeEcho('Error package. package_length=' . \var_export($this->_currentPackageLength, true));
                        $this->destroy();
                        return;
                    }
                }

                // The data is enough for a packet.
                ++self::$statistics['total_request'];
                // The current packet length is equal to the length of the buffer.
                if (\strlen($this->_recvBuffer) === $this->_currentPackageLength) {
                    $one_request_buffer = $this->_recvBuffer;
                    $this->_recvBuffer = '';
                } else {
                    // Get a full package from the buffer.
                    $one_request_buffer = \substr($this->_recvBuffer, 0, $this->_currentPackageLength);
                    // Remove the current package from the receive buffer.
                    $this->_recvBuffer = \substr($this->_recvBuffer, $this->_currentPackageLength);
                }
                // Reset the current packet length to 0.
                $this->_currentPackageLength = 0;
                /*
                if (!$this->onMessage) {
                    continue;
                }
                try {
                    // Decode request buffer before Emitting onMessage callback.
                    $gen = \call_user_func($this->onMessage, $this, $parser::decode($one_request_buffer, $this));
                    if ($gen instanceof \Generator) {
                        $coId = Coworker::genCoId();
                        $this->_coroutines[$coId] = $gen;
                        // run coroutine
                        $gen->current();
                    }
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (\Error $e) {
                    Worker::log($e);
                    exit(250);
                }
                */
                $this->_onMessage($this, $parser::decode($one_request_buffer, $this));
            }
            return;
        }

        if ($this->_recvBuffer === '' || $this->_isPaused) {
            return;
        }

        // Applications protocol is not set.
        ++self::$statistics['total_request'];
        /*
        if (!$this->onMessage) {
            $this->_recvBuffer = '';
            return;
        }
        try {
            \call_user_func($this->onMessage, $this, $this->_recvBuffer);
        } catch (\Exception $e) {
            Worker::log($e);
            exit(250);
        } catch (\Error $e) {
            Worker::log($e);
            exit(250);
        }
        */
        $this->_onMessage($this, $this->_recvBuffer);
        // Clean receive buffer.
        $this->_recvBuffer = '';
    }


    /**
     * Destroy connection.
     *
     * @return void
     */
    public function destroy()
    {
        // Avoid repeated calls.
        if ($this->_status === self::STATUS_CLOSED) {
            return;
        }
        // Remove event listener.
        Worker::$globalEvent->del($this->_socket, EventInterface::EV_READ);
        Worker::$globalEvent->del($this->_socket, EventInterface::EV_WRITE);

        // Close socket.
        try {
            @\fclose($this->_socket);
        } catch (\Exception $e) {
        } catch (\Error $e) {
        }

        $this->_status = self::STATUS_CLOSED;
        // Try to emit onClose callback.
        /*
        if ($this->onClose) {
            try {
                \call_user_func($this->onClose, $this);
            } catch (\Exception $e) {
                Worker::log($e);
                exit(250);
            } catch (\Error $e) {
                Worker::log($e);
                exit(250);
            }
        }*/
        $this->_onClose($this);
        // Try to emit protocol::onClose
        if ($this->protocol && \method_exists($this->protocol, 'onClose')) {
            try {
                \call_user_func(array($this->protocol, 'onClose'), $this);
            } catch (\Exception $e) {
                Worker::log($e);
                exit(250);
            } catch (\Error $e) {
                Worker::log($e);
                exit(250);
            }
        }
        $this->_sendBuffer = $this->_recvBuffer = '';
        $this->_currentPackageLength = 0;
        $this->_isPaused = $this->_sslHandshakeCompleted = false;
        if ($this->_status === self::STATUS_CLOSED) {
            // Cleaning up the callback to avoid memory leaks.
            $this->onMessage = $this->onClose = $this->onError = $this->onBufferFull = $this->onBufferDrain = null;
            // Remove from worker->connections.
            if ($this->worker) {
                unset($this->worker->connections[$this->_id]);
            }
            unset(static::$connections[$this->_id]);
        }
    }

    /**
     * Destruct.
     *
     * @return void
     */
    public function __destruct()
    {
        // 关闭相关协程
        parent::__destruct();
    }

}
