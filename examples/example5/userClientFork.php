<?php
/**
 * 始发客户端, 向购物车服务端发起请求
 */

$host = "127.0.0.1";
$port = 8080;

function request()
{
    global $host, $port;
    $method = "cart";
    $socket = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errMsg);
    if ($socket === false) {
        throw new \RuntimeException("unable to create socket: " . $errMsg);
    }
    fwrite(STDOUT, "\nconnect to server: [{$host}:{$port}]...\n");

    $productId = rand(100, 1000);
    $data = array(
        "method" => $method,
        "data" => array(
            'productId' => $productId,
        ),
        'noBlocking' => true
    );

    $message = json_encode($data);

    fwrite(STDOUT, "send to server: $message , time: " . date('Y-m-d H:i:s') . "\n");
    // 发出请求
    $len = @fwrite($socket, $message . PHP_EOL);
    if ($len === 0) {
        fwrite(STDOUT, "socket closed\n");
    }

    // 读取响应
    $msg = @fread($socket, 4096);
    if ($msg) {
        fwrite(STDOUT, "receive server: $msg  client time : " . date('Y-m-d H:i:s') . ".\n");
    } elseif (feof($socket)) {
        fwrite(STDOUT, "socket closed time: " . date('Y-m-d H:i:s') . "\n");
    }

    // 一个请求完毕，关闭socket
    fwrite(STDOUT, "close connection...\n");
    fclose($socket);
}

function forkMe($ttl)
{
    if (0 > $ttl) {
        return;
    }
    $pid = pcntl_fork();
    if (0 > $pid) {
        exit();
    } elseif ($pid > 0) {
        sleep(1);
        forkMe(-- $ttl);
    } elseif (0 == $pid) {
        request();
    }
    if ($pid > 0) {
        pcntl_wait($status);
    }
}

forkMe(5);
