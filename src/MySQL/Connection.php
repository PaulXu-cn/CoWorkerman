<?php

namespace CoWorkerman\MySQL;

use React\EventLoop\LoopInterface;
use React\MySQL\Factory;
use React\EventLoop\Factory as EventFactory;
use React\MySQL\QueryResult;

use CoWorkerman\CoWorker;


class Connection
{
    protected $dsn = '';

    protected $connection;

    /**
     * @param array $config
     * @return string
     */
    protected static function buildDSN($config)
    {
        return "{$config['user']}:{$config['password']}@{$config['host']}:{$config['port']}/{$config['dbname']}";
    }

    /**
     * Connection constructor.
     *
     * @param array         $config
     * @param LoopInterface $eventLoop
     */
    public function __construct($config, $eventLoop = null)
    {
        if (empty($eventLoop)) {
            $eventLoop = CoWorker::getEventLoop();
        }
        $factory = new Factory($eventLoop);

        $dsn = self::buildDSN($config);
        $this->connection = $factory->createLazyConnection($dsn);
    }

    /**
     * @return \Generator|mixed
     */
    public function connection()
    {
        $coId = CoWorker::getCurrentCoId();
        $re = yield $this->connection->ping()->then(function () use ($coId) {
            echo 'OK' . PHP_EOL;
            CoWorker::coSend($coId, null, true);
        }, function (\Exception $e) use ($coId) {
            echo 'Error: ' . $e->getMessage() . PHP_EOL;
            CoWorker::coSend($coId, null, false);
        });
        return $re['data'];
    }

    /**
     * @param $sql
     * @return \Generator|QueryResult|null
     */
    public function query($sql)
    {
        $coId = CoWorker::getCurrentCoId();
        $re = yield $this->connection->query($sql)->then(function (QueryResult $command) use ($coId) {
            /*
             * $resultRows = array('resultFields' => array('filed1', 'field2' ...),
             *      'resultRows' => array(0 => array(), 1 => array(), 2 => array()),
             *      'insertId' => integer, 'affectedRows' => integer);
             */
            CoWorker::coSend($coId, null, $command);
        }, function (\Exception $error) use ($coId) {
            // the query was not executed successfully
            echo 'Error: ' . $error->getMessage() . PHP_EOL;
            CoWorker::coSend($coId, null, null);
        });
        return $re['data'];
    }

}
