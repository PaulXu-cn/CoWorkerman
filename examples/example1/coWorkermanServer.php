<?php

define('DS', DIRECTORY_SEPARATOR);

include __DIR__ . DS . '../../../../autoload.php';
include __DIR__ . DS . '../../vendor/autoload.php';

use CoWorkerman\CoWorker;
use CoWorkerman\Connection\CoTcpConnection;

$worker = new CoWorker('text://0.0.0.0:8080');

$worker->onConnect = function (CoTcpConnection  $connection) {
    $clientAdd = "{$connection->getRemoteIp()}:{$connection->getRemotePort()}";
    echo "New Connection, {$clientAdd}" . PHP_EOL;

    $connection->send('hello' . PHP_EOL);
    $re = yield from $connection->readAsync(CoTcpConnection::READ_BUFFER_SIZE);

    CoWorker::safeEcho( "I got: [{$re}]" . PHP_EOL);
    $connection->close();
};

// 运行worker
CoWorker::runAll();