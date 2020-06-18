<?php

require_once __DIR__ . '/../../vendor/autoload.php';

use CoWorkerman\CoWorker;
use CoWorkerman\MySQL\Connection;
use CoWorkerman\Events\CoExtEvent;

CoWorker::$globalEvent = $loop;
$worker = new CoWorker('tcp://0.0.0.0:6161');

// 收到客户端请求时
$worker->onMessage = function($connection, $data) {
    global $mysql;

    $mysql = new Connection(array(
//        'host'   => '192.63.0.1', // 不要写localhost
        'host'   => '192.63.0.14', // 不要写localhost
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