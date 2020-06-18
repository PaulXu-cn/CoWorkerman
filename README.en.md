English | [中文](README.md)
# CoWorkerman
Coroutine Workerman.

> Implemented by `yield`,`yield from`.

## Requires
PHP 5.5 or Higher

A POSIX compatible operating system (Linux, OSX, BSD)

POSIX and PCNTL extensions should be enabled

Event extension recommended for better performance, like: Event, ev, libevent

## Install

```shell script
composer require paulxu-cn/co-workerman@dev-master
```

## Basic Usage

### A simple coroutine TCP server, with a timer

```php
<?php
// file: ./simpleTimerServer.php
define('DS', DIRECTORY_SEPARATOR);

require_once __DIR__ . DS . 'vendor' . DS . 'autoload.php';

use CoWorkerman\CoWorker;
use CoWorkerman\Lib\CoTimer;
use CoWorkerman\Connection\CoTcpConnection;

CoWorker::$globalEvent = null;

$worker = new CoWorker('tcp://0.0.0.0:8686');

$worker->onConnect = function (CoTcpConnection  $connection) {
    echo "New Connection, {$connection->getLocalIp()}" . PHP_EOL;

    yield from CoTimer::sleepAsync(1000);    // yield, and go back after 1 second.

    $re = yield from $connection->sendAsync('hello');
    
    CoWorker::safeEcho( "get re: [{$re}]" . PHP_EOL);
};

// 运行worker
CoWorker::runAll();
```

* start the server
```shell script
## start the CoWorkerman Server
$ php ./simpleTimerServer.php start
## talnet it
$ talnet 127.0.0.1:8686
hello
> hi
```

### Coroutine TCP server, client.

```php
<?php

define('DS', DIRECTORY_SEPARATOR);

require_once __DIR__ . DS . 'vendor' . DS . 'autoload.php';

use CoWorkerman\CoWorker;
use CoWorkerman\Coroutine\Promise;
use CoWorkerman\Connection\CoTcpClient;
use CoWorkerman\Connection\CoTcpConnection;

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
    $ifCon = yield from Promise::wait($ifCon, __FUNCTION__);
    
    if (!$ifCon) {
        return null;
    }
    CoWorker::safeEcho( "\nconnected to server, re: [{$ifCon}]\n" . PHP_EOL);
    $message = json_encode(["method" => $method, "data" => $data]);
    $re =  $con->sendAsync($message);
    return $re;
}

function checkInventoryAsync($connection, $productId, $noBlocking = true)
{
    $host = "127.0.0.1";
    $port = 8081;
    $data = array('productId' => $productId);
    return yield from checkClientAsync($connection, $host, $port, 'inventory', $data,  $noBlocking);
}

function checkProductAsync($connection, $productId, $noBlocking = true)
{
    $host = "127.0.0.1";
    $port = 8082;
    $data = array('productId' => $productId);
    return yield from checkClientAsync($connection, $host, $port, 'product', $data,  $noBlocking);
}

CoWorker::$globalEvent = null;

$worker = new CoWorker('tcp://0.0.0.0:8686');

$worker->onConnect = function (CoTcpConnection  $connection) {
    echo "New Connection, {$connection->getLocalIp()} \n";

    $re = checkInventoryAsync($connection, rand(10, 20), true);
    $re2 = checkProductAsync($connection, rand(10, 20), true);

    // 顺序异步执行
//    $re = yield from Promise::wait($re, 'onConnect');
//    $re2 = yield from Promise::wait($re2, 'onConnect');

    // or 同时异步执行
    list($re, $re2) = yield from Promise::all(array($re, $re2), 'onConnect');

    var_dump($re);
    var_dump($re2);
};

// 运行worker
CoWorker::runAll();
```

