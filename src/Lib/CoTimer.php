<?php

namespace CoWorkerman\Lib;

use CoWorkerman\Connection\CoTcpConnection;
use Workerman\Timer;

use CoWorkerman\CoWorker;

/**
 * Class CoTimer
 *
 * @author Paul Xu
 * @package Workerman\Lib
 */
class CoTimer extends Timer
{

    /**
     * @param integer   $ms
     *
     * @return \Generator
     */
    public static function sleepAsync($ms)
    {
        $coId = CoWorker::getCurrentCoId();
        CoWorker::safeEcho('add timer id with CoId: ' . $coId . PHP_EOL);
        $timerCall = function () use ($coId) {
            CoWorker::safeEcho('timer callback send to CoId: ' . $coId . PHP_EOL);
            CoWorker::$_currentCoId = $coId;
            CoWorker::coSend($coId, null, true);
        };
        $timerId = self::add($ms / 1000, $timerCall);
        CoWorker::safeEcho('add timer id: ' . $timerId . PHP_EOL);
        $re = yield $timerId;
        self::del($timerId);
        CoWorker::safeEcho('delete timer id: ' . $timerId . PHP_EOL);
    }

}
