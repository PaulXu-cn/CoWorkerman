<?php

namespace CoWorkerman\Coroutine;

/**
 * Class TcpSocketPromise
 *
 * @package Workerman\Coroutine
 */
class TcpSocketPromise extends Promise
{
    protected $_coroutineId;

    protected $_clientId;

    protected $_parentId;

    /**
     * @var \Generator  $_generator
     */
    protected $_generator;

    protected $_function;

    protected $_args;

    protected $_socketName;

    protected $_socket;

    /**
     * TcpSocketPromise constructor.
     *
     * @param callable|\Generator  $function
     * @param integer   $parentId
     * @param    $client
     * @param integer   $coroutineId
     */
    public function __construct($socket, $context, $function, ...$args)
    {
        $this->_function = $function;
        $this->_socket = $socket;
        $this->_socketName = (string) $socket;
        $this->_args = $args;
    }

    public function run()
    {
        $this->_generator = call_user_func($this->_function);
        if ($this->_generator instanceof \Generator) {
            $this->_generator->current();
        }
    }

    public function send($data)
    {
        $this->_generator->send($data);
    }

    /**
     * @return bool
     */
    public function isValid()
    {
        if (empty($this->_generator)) {
            return false;
        }
        try {
            $this->_generator->getReturn();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * @return \Generator
     */
    public function getGenerator(): \Generator
    {
        return $this->_generator;
    }

    public function getFunction()
    {
        return $this->_function;
    }

    /**
     * @return array
     */
    public function getArgs(): array
    {
        return $this->_args;
    }

    /**
     * @return string
     */
    public function getSocketName(): string
    {
        return $this->_socketName;
    }

    /**
     * @return mixed
     */
    public function getSocket()
    {
        return $this->_socket;
    }

}
