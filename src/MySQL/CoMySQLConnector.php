<?php

namespace CoWorkerman\MySQL;

use React\MySQL\ConnectionInterface;
use React\Promise\PromiseInterface;
use React\Stream\ReadableStreamInterface;

/**
 * Class CoMySQLConnector
 *
 * @package CoWorkerman\MySQL
 */
class CoMySQLConnector implements ConnectionInterface
{
    public function on($event, callable $listener)
    {
        // TODO: Implement on() method.
    }

    public function once($event, callable $listener)
    {
        // TODO: Implement once() method.
    }

    public function removeListener($event, callable $listener)
    {
        // TODO: Implement removeListener() method.
    }

    public function removeAllListeners($event = null)
    {
        // TODO: Implement removeAllListeners() method.
    }

    public function listeners($event = null)
    {
        // TODO: Implement listeners() method.
    }

    public function emit($event, array $arguments = [])
    {
        // TODO: Implement emit() method.
    }

    public function query($sql, array $params = array())
    {
        // TODO: Implement query() method.
    }

    public function queryStream($sql, $params = array())
    {
        // TODO: Implement queryStream() method.
    }

    public function ping()
    {
        // TODO: Implement ping() method.
    }

    public function quit()
    {
        // TODO: Implement quit() method.
    }

    public function close()
    {
        // TODO: Implement close() method.
    }

}