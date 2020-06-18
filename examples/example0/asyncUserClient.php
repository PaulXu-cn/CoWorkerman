<?php

define('DS', DIRECTORY_SEPARATOR);

require_once __DIR__ . DS . '../../vendor/autoload.php';

use \Workerman\Worker;
use \Workerman\Connection\AsyncTcpConnection;

$task = new Worker();
// 进程启动时异步建立一个到www.baidu.com连接对象，并发送数据获取数据
$task->onWorkerStart = function($task) {
    for ($i = 0; $i < 9; $i++) {
        $connection_to_baidu = new AsyncTcpConnection('tcp://127.0.0.1:8080');
// 当连接建立成功时，发送http请求数据
        $connection_to_baidu->onConnect = function ($connection) {
            echo "connect success\n";

            $method = 'cart';
            $productId = rand(100, 1000);
            $data = array(
                "method" => $method,
                "data" => array(
                    'productId' => $productId,
                ),
                'noBlocking' => true
            );
            $message = json_encode($data);

            $connection->send($message . PHP_EOL);
        };

        $connection_to_baidu->onMessage = function ($connection, $http_buffer) {
            echo $http_buffer . PHP_EOL;
//            $connection->close();
        };

        $connection_to_baidu->onClose = function ($connection) {
            echo "connection closed\n";
        };

        $connection_to_baidu->onError = function ($connection, $code, $msg) {
            echo "Error code:$code msg:$msg\n";
        };

        $connection_to_baidu->connect();
        sleep(1);
    }
};

// 运行worker
Worker::runAll();