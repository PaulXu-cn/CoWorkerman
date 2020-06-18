[English](./README.en.md) | 中文
# CoWorkerman
协程版 Workerman. 同步编码，异步执行

>  使用PHP原生的 `yield`,`yield from` 实现，协程这块功能没有依赖其他拓展.

## 环境要求

至少PHP v5.5

支持`POSIX`规范的兼容性系统(Linux, OSX, BSD) 

`POSIX` 和 `PCNTL` 这两个模块需要在PHP编译期间就启用。

为了更好的性能，推荐安装事件驱动拓展，如：Event，ev，libevent.

## 安装

```shell script
composer require paulxu-cn/co-workerman@dev-master
```

## 基本使用

### 一个带有定时器的简单TCP服务

```php
<?php
// file: ./simpleTimerServer.php
define('DS', DIRECTORY_SEPARATOR);

require_once __DIR__ . DS . 'vendor' . DS . 'autoload.php';

use CoWorkerman\CoWorker;
use CoWorkerman\Lib\CoTimer;
use CoWorkerman\Connection\CoTcpConnection;

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
> hi
hello
```

### TCP服务中，使用协程TCP客户端

```php
<?php
// file: ~/examples/example1/coCartServer.php
define('DS', DIRECTORY_SEPARATOR);

require_once __DIR__ . DS . 'vendor' . DS . 'autoload.php';

use CoWorkerman\CoWorker;
use CoWorkerman\Coroutine\Promise;
use CoWorkerman\Connection\CoTcpClient;
use CoWorkerman\Connection\CoTcpConnection;

$worker = new CoWorker('tcp://0.0.0.0:8686');

$worker->onConnect = function (CoTcpConnection  $connection) {
    echo "New Connection, {$connection->getLocalIp()} \n";

    $re = checkInventoryAsync($connection, rand(10, 20), true);
    $re2 = checkProductAsync($connection, rand(10, 20), true);

    // 顺序异步执行 ------------------------------------------------------------------+
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

// 运行worker
CoWorker::runAll();
```
* 启动依赖的三个服务
```shell script
## 启动一个处理耗时2s的库存服务
$ php ./examples/example1/otherServer.php 8081 inventory 2

## 启动一个处理耗时4s的产品服务
$ php ./examples/example1/otherServer.php 8082 product 4

## 监听8083端口，处理一个请求 耗时6s的 promo 服务
$ php ./examples/example1/otherServer.php 8083 promo 6
```
* 运行服务端，与客户端
```sehll
## 启动一个非阻塞购物车服务
$ php ./coCartServer.php 

## 客户端请求
$ php ./examples/example1/userClient.php
``` 

### 服务中使用协程MySQL客户端

```php
<?php
// file: ~/example/example2/coWorkerMySQLtest1.php
require_once __DIR__ . '/../../vendor/autoload.php';

use CoWorkerman\CoWorker;
use CoWorkerman\MySQL\Connection;

$worker = new CoWorker('tcp://0.0.0.0:6161');

// 收到客户端请求时
$worker->onMessage = function($connection, $data) {
    global $mysql;

    $mysql = new Connection(array(
        'host'   => '127.0.0.1', // 不要写localhost
        'dbname' => 'mysql',
        'user'   => 'root',
        'password' => '123456',
        'port'  => '3306'
    ));

    $connected = yield from $mysql->connection();
    $re = yield from $mysql->query('show databases');

    CoWorker::safeEcho(json_encode($re) . PHP_EOL);
};

CoWorker::runAll();
```

* 启动服务
```shell script
## start the MySQL client server
$ php ./examples/example2/coWorkerMySQLtest1.php start
```
* 访问
```shell script
telnet 127.0.0.1 6161
```

## 说明

目前还在测试开发阶段，不要用在生产环境中.
