<?php

define('DS', DIRECTORY_SEPARATOR);

include __DIR__ . DS . '../../../../autoload.php';
include __DIR__ . DS . '../../vendor/autoload.php';

use CoWorkerman\CoWorker;
use CoWorkerman\Lib\CoTimer;
use CoWorkerman\Coroutine\Promise;
use CoWorkerman\Connection\CoTcpClient;
use CoWorkerman\Connection\CoTcpConnection;
use \CoWorkerman\Exception\ConnectionErrorException;
use \CoWorkerman\Exception\ConnectionCloseException;

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
    CoWorker::safeEcho( "connected to other server, status : [{$ifCon}]" . PHP_EOL . PHP_EOL);

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
    try {
        // 接收用户请求
        $clientAdd = "{$connection->getRemoteIp()}:{$connection->getRemotePort()}";
        echo "New Connection,  {$clientAdd}\n";
        $request = yield from $connection->readAsync(CoTcpConnection::READ_BUFFER_SIZE);
        CoWorker::safeEcho('user request msg : ' . $request . PHP_EOL);
        $reqData = json_decode($request, true);
        $productId = mt_rand(10, 50);
        if (isset($reqData['data']['productId'])) {
            $productId = $reqData['data']['productId'];
        }

        // 开始请求其他服务
        $re = checkInventoryAsync($connection, $productId, true);
        $re2 = checkProductAsync($connection, $productId, true);
        $re3 = checkPromoAsync($connection, $productId, true);

        // 睡一会儿，模拟程序耗时
        yield from CoTimer::sleepAsync(1000);

        // 顺序异步执行 ------------------------------------------------------------------+
//    $re3 = yield from Promise::wait($re3);
//    $re = yield from Promise::wait($re, 'onConnect');
//    $re2 = yield from Promise::wait($re2, 'onConnect');
        // or 同时异步执行 ---------------------------------------------------------------+
        list($re, $re2) = yield from Promise::all(array($re, $re2), 'onConnect');
        // 选择结束 ----------------------------------------------------------------------+

        // 数据处理以及校验
        CoWorker::safeEcho('received from inventory, product, msg is: ' . $re . '; ' . $re2 . PHP_EOL);
        $check = 'failed';
        $data1 = json_decode($re, true);
        $data2 = json_decode($re2, true);
        if (isset($data1['data']['re']) && isset($data2['data']['re'])) {
            $check = $data1['data']['re'] && $data2['data']['re'];
        }

        // 响应客户端的请求
        $response = json_encode(array('productId' => $productId, 'check' => $check));
        $connection->send($response);
        CoWorker::safeEcho('response user client: ' . $response . PHP_EOL . PHP_EOL);
    } catch (ConnectionCloseException $e) {
        CoWorker::safeEcho('connection closed: ' . $check . PHP_EOL);
    } catch (ConnectionErrorException $e) {
        CoWorker::safeEcho('connection error, msg: ' . $e->getMessage() . PHP_EOL);
    } catch (\Exception $e) {
        CoWorker::safeEcho('Exception caught, msg: ' . $e->getMessage() . PHP_EOL);
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