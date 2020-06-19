<?php

namespace CoWorkerman\Lib;

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
        $timerCall = function () use ($coId) {
            CoWorker::$_currentCoId = $coId;
            CoWorker::coSend($coId, null, true);
        };
        $timerId = self::add($ms / 1000, $timerCall);
        $re = yield $timerId;
        self::del($timerId);
    }

}
