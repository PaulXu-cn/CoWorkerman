<?php

namespace CoWorkerman\Lib;

use Workerman\Timer;

use CoWorkerman\CoWorker;

/**
 * Class CoTimer
 *
 * @package Workerman\Lib
 */
class CoTimer extends Timer
{

    /**
     * @param integer   $ms
     * @return \Generator
     */
    public static function sleep($ms)
    {
        yield self::add($ms / 1000, function () {
            $coId = CoWorker::getCurrentCoId();
            CoWorker::coSend($coId, null, true);
        });
    }

}
