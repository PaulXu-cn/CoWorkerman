<?php

define('DS', DIRECTORY_SEPARATOR);

require_once __DIR__ . DS . '../../vendor/autoload.php';

use CoWorkerman\CoWorker;
use CoWorkerman\Lib\CoTimer;
use CoWorkerman\Connection\CoTcpConnection;
use \CoWorkerman\Exception\ConnectionCloseException;

$worker = new CoWorker('tcp://0.0.0.0:8080');
CoWorker::$globalEvent = null;
$worker->count = 1;

/**
 * 连接成功时
 *
 * @param CoTcpConnection $connection
 * @return Generator
 */
$worker->onConnect = function (CoTcpConnection  $connection) {
    try {
        $conName = "{$connection->getRemoteIp()}:{$connection->getRemotePort()}";
        echo PHP_EOL . "New Connection, {$conName} \n";

        $re = yield from $connection->readAsync(1024);
        CoWorker::safeEcho('get request msg :' . (string) $re . PHP_EOL);

        yield from CoTimer::sleepAsync(1000 * 2);

        $connection->send(json_encode(array('productId' => 12, 're' =>true)));

        CoWorker::safeEcho('Response to :' . $conName . PHP_EOL . PHP_EOL);
    } catch (ConnectionCloseException $e) {
        CoWorker::safeEcho('Connection closed, ' . $e->getMessage() . PHP_EOL);
    }
};

/**
 * 被动接收到的消息
 *
 * @param CoTcpConnection   $connection
 * @param string            $data
 */
$worker->onMessage = function(CoTcpConnection $connection, $data)
{
    $re = $connection->send("hello");
};

// 运行worker
CoWorker::runAll();