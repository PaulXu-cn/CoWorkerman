<?php

namespace CoWorkerman\Connection;

use Workerman\Worker;
use Workerman\Events\EventInterface;
use Workerman\Connection\AsyncTcpConnection;

use CoWorkerman\CoWorker;
use CoWorkerman\Coroutine\Promise;
use CoWorkerman\Coroutine\CoroutineMan;

/**
 * Class CoTcpClient
 *
 * @package Workerman\Connection
 */
class CoTcpClient extends AsyncTcpConnection
{
    use CoroutineMan;

    /**
     * @param CoTcpClient $client  socket 链接
     * @param boolean     $success 链接成功与否
     */
    protected function _onConnect($client, $success)
    {
        $socket = $client->getSocket();
        $coId = self::getCoIdBySocket($socket);
        CoTcpConnection::coSend($coId, $socket, $success);
        if ($this->onConnect) {
            try {
                $gen = \call_user_func($this->onConnect, $client);
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
     * @param CoTcpClient $connection
     * @param $data
     */
    protected function _onMessage($connection, $data)
    {
        $socket = $connection->getSocket();
        $coId = self::getCoIdBySocket($socket);
        CoTcpConnection::coSend($coId, $socket, $data);
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
     * @param CoTcpClient $connection
     */
    protected function _onClose($connection)
    {
        self::removeGeneratorBySocket($connection->getSocket());
        CoWorker::removeCoroutine(self::getCoIdBySocket($connection->getSocket()));
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
                self::setCoIdBySocket($this->_socket);
                $re = yield $this->_socket;
                return $re['data'];
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
        $re = (yield $this->_socket);
        return $re['data'];
    }

    /**
     * Check connection is successfully established or faild.
     *
     * @return void
     */
    public function checkConnection()
    {
        // Remove EV_EXPECT for windows.
        if(\DIRECTORY_SEPARATOR === '\\') {
            Worker::$globalEvent->del($this->_socket, EventInterface::EV_EXCEPT);
        }

        // Remove write listener.
        Worker::$globalEvent->del($this->_socket, EventInterface::EV_WRITE);

        if ($this->_status !== self::STATUS_CONNECTING) {
            return;
        }

        // Check socket state.
        if ($address = \stream_socket_get_name($this->_socket, true)) {
            // Nonblocking.
            \stream_set_blocking($this->_socket, false);
            // Compatible with hhvm
            if (\function_exists('stream_set_read_buffer')) {
                \stream_set_read_buffer($this->_socket, 0);
            }
            // Try to open keepalive for tcp and disable Nagle algorithm.
            if (\function_exists('socket_import_stream') && $this->transport === 'tcp') {
                $raw_socket = \socket_import_stream($this->_socket);
                \socket_set_option($raw_socket, \SOL_SOCKET, \SO_KEEPALIVE, 1);
                \socket_set_option($raw_socket, \SOL_TCP, \TCP_NODELAY, 1);
            }

            // SSL handshake.
            if ($this->transport === 'ssl') {
                $this->_sslHandshakeCompleted = $this->doSslHandshake($this->_socket);
                if ($this->_sslHandshakeCompleted === false) {
                    return;
                }
            } else {
                // There are some data waiting to send.
                if ($this->_sendBuffer) {
                    Worker::$globalEvent->add($this->_socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
                }
            }

            // Register a listener waiting read event.
            Worker::$globalEvent->add($this->_socket, EventInterface::EV_READ, array($this, 'baseReadAsync'));

            $this->_status                = self::STATUS_ESTABLISHED;
            $this->_remoteAddress         = $address;

            // Try to emit onConnect callback.
            /*
            if ($this->onConnect) {
                try {
                    \call_user_func($this->onConnect, $this);
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (\Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            } */
            // Try to emit protocol::onConnect
            if (\method_exists($this->protocol, 'onConnect')) {
                try {
                    \call_user_func(array($this->protocol, 'onConnect'), $this);
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (\Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            }
        } else {
            // Connection failed.
            $this->emitError(\WORKERMAN_CONNECT_FAIL, 'connect ' . $this->_remoteAddress . ' fail after ' . round(\microtime(true) - $this->_connectStartTime, 4) . ' seconds');
            if ($this->_status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            if ($this->_status === self::STATUS_CLOSED) {
                $this->onConnect = null;
            }
        }
    }

    /**
     * @param CoTcpConnection $parent
     */
    public function checkConnectionAsync($parent)
    {
        // Remove EV_EXPECT for windows.
        if(\DIRECTORY_SEPARATOR === '\\') {
            Worker::$globalEvent->del($this->_socket, EventInterface::EV_EXCEPT);
        }

        // Remove write listener.
        Worker::$globalEvent->del($this->_socket, EventInterface::EV_WRITE);

        if ($this->_status !== self::STATUS_CONNECTING) {
            return;
        }

        // Check socket state.
        if ($address = \stream_socket_get_name($this->_socket, true)) {
            // Nonblocking.
            \stream_set_blocking($this->_socket, false);
            // Compatible with hhvm
            if (\function_exists('stream_set_read_buffer')) {
                \stream_set_read_buffer($this->_socket, 0);
            }
            // Try to open keepalive for tcp and disable Nagle algorithm.
            if (\function_exists('socket_import_stream') && $this->transport === 'tcp') {
                $raw_socket = \socket_import_stream($this->_socket);
                \socket_set_option($raw_socket, \SOL_SOCKET, \SO_KEEPALIVE, 1);
                \socket_set_option($raw_socket, \SOL_TCP, \TCP_NODELAY, 1);
            }

            // SSL handshake.
            if ($this->transport === 'ssl') {
                $this->_sslHandshakeCompleted = $this->doSslHandshake($this->_socket);
                if ($this->_sslHandshakeCompleted === false) {
                    return;
                }
            } else {
                // There are some data waiting to send.
                if ($this->_sendBuffer) {
                    Worker::$globalEvent->add($this->_socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
                }
            }

            // Register a listener waiting read event.
            Worker::$globalEvent->add($this->_socket, EventInterface::EV_READ, array($this, 'baseReadAsync'), array($parent));

            $this->_status                = self::STATUS_ESTABLISHED;
            $this->_remoteAddress         = $address;

            // Try to emit onConnect callback.
            /*
            if ($this->onConnect) {
                try {
                    \call_user_func($this->onConnect, $this);
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (\Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            } */
            $this->_onConnect($this, true);
            // Try to emit protocol::onConnect
            if (\method_exists($this->protocol, 'onConnect')) {
                try {
                    \call_user_func(array($this->protocol, 'onConnect'), $this);
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (\Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            }
        } else {
            // Connection failed.
            $this->emitError(\WORKERMAN_CONNECT_FAIL, 'connect ' . $this->_remoteAddress . ' fail after ' . round(\microtime(true) - $this->_connectStartTime, 4) . ' seconds');
            $this->_onConnect($this, false);
            if ($this->_status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            if ($this->_status === self::STATUS_CLOSED) {
                $this->onConnect = null;
            }
        }
    }
    /**
     * Do connect.
     *
     * @return void
     */
    public function connect()
    {
        if ($this->_status !== self::STATUS_INITIAL && $this->_status !== self::STATUS_CLOSING &&
            $this->_status !== self::STATUS_CLOSED) {
            return;
        }
        $this->_status           = self::STATUS_CONNECTING;
        $this->_connectStartTime = \microtime(true);
        if ($this->transport !== 'unix') {
            if (!$this->_remotePort) {
                $this->_remotePort = $this->transport === 'ssl' ? 443 : 80;
                $this->_remoteAddress = $this->_remoteHost.':'.$this->_remotePort;
            }
            // Open socket connection asynchronously.
            if ($this->_contextOption) {
                $context = \stream_context_create($this->_contextOption);
                $this->_socket = \stream_socket_client("tcp://{$this->_remoteHost}:{$this->_remotePort}",
                    $errno, $errstr, 0, \STREAM_CLIENT_ASYNC_CONNECT, $context);
            } else {
                $this->_socket = \stream_socket_client("tcp://{$this->_remoteHost}:{$this->_remotePort}",
                    $errno, $errstr, 0, \STREAM_CLIENT_ASYNC_CONNECT);
            }
        } else {
            $this->_socket = \stream_socket_client("{$this->transport}://{$this->_remoteAddress}", $errno, $errstr, 0,
                \STREAM_CLIENT_ASYNC_CONNECT);
        }
        // If failed attempt to emit onError callback.
        if (!$this->_socket || !\is_resource($this->_socket)) {
            $this->emitError(\WORKERMAN_CONNECT_FAIL, $errstr);
            if ($this->_status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            if ($this->_status === self::STATUS_CLOSED) {
                $this->onConnect = null;
            }
            return;
        }
        self::setCoIdBySocket($this->_socket);
        // Add socket to global event loop waiting connection is successfully established or faild.
        Worker::$globalEvent->add($this->_socket, EventInterface::EV_WRITE, array($this, 'checkConnection'));
        // For windows.
        if(\DIRECTORY_SEPARATOR === '\\') {
            Worker::$globalEvent->add($this->_socket, EventInterface::EV_EXCEPT, array($this, 'checkConnection'));
        }
    }

    public function _connectAsync($parent)
    {
        if ($this->_status !== self::STATUS_INITIAL && $this->_status !== self::STATUS_CLOSING &&
            $this->_status !== self::STATUS_CLOSED) {
            return;
        }
        $this->_status           = self::STATUS_CONNECTING;
        $this->_connectStartTime = \microtime(true);
        if ($this->transport !== 'unix') {
            if (!$this->_remotePort) {
                $this->_remotePort = $this->transport === 'ssl' ? 443 : 80;
                $this->_remoteAddress = $this->_remoteHost.':'.$this->_remotePort;
            }
            // Open socket connection asynchronously.
            if ($this->_contextOption) {
                $context = \stream_context_create($this->_contextOption);
                $this->_socket = \stream_socket_client("tcp://{$this->_remoteHost}:{$this->_remotePort}",
                    $errno, $errstr, 0, \STREAM_CLIENT_ASYNC_CONNECT, $context);
            } else {
                $this->_socket = \stream_socket_client("tcp://{$this->_remoteHost}:{$this->_remotePort}",
                    $errno, $errstr, 0, \STREAM_CLIENT_ASYNC_CONNECT);
            }
        } else {
            $this->_socket = \stream_socket_client("{$this->transport}://{$this->_remoteAddress}", $errno, $errstr, 0,
                \STREAM_CLIENT_ASYNC_CONNECT);
        }
        // If failed attempt to emit onError callback.
        if (!$this->_socket || !\is_resource($this->_socket)) {
            $this->emitError(\WORKERMAN_CONNECT_FAIL, $errstr);
            if ($this->_status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            if ($this->_status === self::STATUS_CLOSED) {
                $this->onConnect = null;
            }
            return;
        }
        self::setCoIdBySocket($this->_socket);
        // Add socket to global event loop waiting connection is successfully established or faild.
        Worker::$globalEvent->add($this->_socket, EventInterface::EV_WRITE, array($this, 'checkConnectionAsync'), array($parent));
        // For windows.
        if(\DIRECTORY_SEPARATOR === '\\') {
            Worker::$globalEvent->add($this->_socket, EventInterface::EV_EXCEPT, array($this, 'checkConnectionAsync'), array($parent));
        }
        $re = yield $this->_socket;
        return $re['data'];
    }

    /**
     * @param CoTcpConnection   $parent
     *
     * @return \Generator|void|Promise
     */
    public function connectAsync($parent)
    {
        return yield from $this->_connectAsync($parent);
    }

    /**
     * Base read handler.
     *
     * @param resource $socket
     * @param bool     $check_eof
     * @return void
     */
    public function baseReadAsync($socket, $check_eof = true)
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
                */
                $msg = $parser::decode($one_request_buffer, $this);
                $this->_onMessage($this, $msg);
                $coId = self::getCoIdBySocket($socket);
                CoTcpConnection::coSend($coId, $socket, $msg);
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
        parent::__destruct();
    }

    /**
     * @throws \Exception
     */
    public function __clone()
    {
        // TODO: Implement __clone() method.
        throw new \Exception('你就不能new一个么，非要clone！');
    }

}
