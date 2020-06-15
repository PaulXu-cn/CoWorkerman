<?php
/**
 * 所依赖的其他服务，这里通过命令行参数来制定是什么名字的服务，
 */

$productId = '';
//$port = 8081;
$port = $argv[1];       // 端口

//$method = 'inventory';
$method = $argv[2];     //  method，服务名字

$sleepTime = intval($argv[3]);  // 模拟耗时时长

// 启动一个socket server
$socket = @stream_socket_server("tcp://0.0.0.0:$port", $errno, $errMsg);
if ($socket === false) {
    throw new \RuntimeException("fail to listen on port: {$port}!");
}
fwrite(STDOUT, "socket server listen on port: {$port}" . PHP_EOL);

while (true) {
    $client = @stream_socket_accept($socket);
    if ($client == false) {
        continue;
    } else {
        fwrite(STDOUT, "\nnew  client:  " . $client . " time: " . date('Y-m-d H:i:s') . " \n");
    }

    while (true) {
        $msg = @fread($client, 4096);
        if ($msg) {
            fwrite(STDOUT, "\nreceive client: $msg " . date('Y-m-d H:i:s') . " \n");
            // 解析请求
            $request = json_decode($msg, true);
            // 获取产品ID
            $productId = $request['data']['productId'];
            // 检查发放是否是预期
            if ($method == $request['method']) {
                $randInt = rand(0, 100);
                $status = $randInt > 20;        //  这里不是每一次都是返回的成功，有20%失败
                $reMsg = array('method' => $method, 'data' => array('productId' => $productId, 're' => $status));
            } else {
                $reMsg = array('method' => $method, 'data' => array('productId' => $productId, 're' => false));
            }

            // 这里休眠 xs，模拟处理耗时
            sleep($sleepTime);

            // 响应请求
            $json = json_encode($reMsg);
            @fwrite($client, $json);
            fwrite(STDOUT, "response :" . $json . " time: " . date('Y-m-d H:i:s') . "\n");
            // 关闭socket
            fclose($client);
            break;
        } elseif (feof($client)) {
            fwrite(STDOUT, "client:" . (int)$client . " disconnected!\n");
            fclose($client);
            break;
        }
    }
}