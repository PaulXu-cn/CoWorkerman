<?php

define('DS', DIRECTORY_SEPARATOR);

require_once __DIR__ . DS . '../../vendor/autoload.php';

use CoWorkerman\CoWorker;
use CoWorkerman\Lib\CoTimer;
use CoWorkerman\Coroutine\Promise;
use CoWorkerman\Connection\CoTcpClient;
use CoWorkerman\Connection\CoTcpConnection;

$worker = new CoWorker('tcp://0.0.0.0:8080');
CoWorker::$globalEvent = null;
$worker->count = 1;

/**
 * 发起rpc检查统一接口
 *
 * @param      $host
 * @param      $port
 * @param      $method
 * @param      $data
 * @param bool $noBlocking
 * @return bool|false|string
 */
function checkClientAsync($connection, $host, $port, $method, $data, $noBlocking = true)
{
    CoWorker::safeEcho('try to connect to ' . "tcp://$host:$port" . PHP_EOL);
    $con = new CoTcpClient("tcp://$host:$port");
    $ifCon = $con->connectAsync($connection);
    $ifCon = (yield from Promise::wait($ifCon, __FUNCTION__));
    if (!$ifCon) {
        return null;
    }
    CoWorker::safeEcho( "\nconnected to server, re: [{$ifCon}]\n" . PHP_EOL);

    $message = json_encode([
        "method" => $method,
        "data" => $data
    ]);

    $re =  $con->sendAsync($message);
    return $re;
}

function checkInventoryAsync($connection, $productId, $noBlocking = true)
{
    // client.php
    $host = "127.0.0.1";
    $port = 8081;

    $data = array('productId' => $productId);

    return (yield from checkClientAsync($connection, $host, $port, 'inventory', $data,  $noBlocking));
}

function checkProductAsync($connection, $productId, $noBlocking = true)
{
    // client.php
    $host = "127.0.0.1";
    $port = 8082;

    $data = array('productId' => $productId);

    return (yield from checkClientAsync($connection, $host, $port, 'product', $data,  $noBlocking));
}

function checkPromoAsync($connection, $productId, $noBlocking = true)
{
    // client.php
    $host = "127.0.0.1";
    $port = 8083;

    $data = array('productId' => $productId);

    return (yield from checkClientAsync($connection, $host, $port, 'promo', $data,  $noBlocking));
}

$worker->onConnect = function (CoTcpConnection  $connection) {
    echo "New Connection, {$connection->getLocalIp()} \n";

    $re = checkInventoryAsync($connection, rand(10, 20), true);
    $re2 = checkProductAsync($connection, rand(10, 20), true);
    $re3 = checkPromoAsync($connection, rand(10, 20), true);

    yield from CoTimer::sleepAsync(100);

    // 顺序异步执行 ------------------------------------------------------------------+
    $re3 = yield from Promise::wait($re3);
//    $re = yield from Promise::wait($re, 'onConnect');
//    $re2 = yield from Promise::wait($re2, 'onConnect');
    // or 同时异步执行 ---------------------------------------------------------------+
    list($re, $re2) = yield from Promise::all(array($re, $re2), 'onConnect');
    // 选择结束 ----------------------------------------------------------------------+

    if (isset($re['re']) && isset($re2['re'])) {
        $check = $re['re'] && $re2['re'];
    }
    $connection->sendAsync($check);
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