<?php

namespace CoWorkerman\Coroutine;

/**
 * Trait CoroutineMan
 *
 * @package CoWorkerman\Coroutine
 */
trait CoroutineMan
{
    /**
     * 生成器数组
     *
     * @var \Generator[] $_coroutines
     */
    protected static $_coroutines = array();

    /**
     * socket连接对应的 生成器ID
     *
     * @var integer[]   $_connectionSocketToCoId
     */
    protected static $_connectionSocketToCoId = array();

    /**
     * @var integer[]   $_coIds
     */
    protected static $_coIds = array();

    /**
     * @var integer[]   $_recycleCoIds
     */
    protected static $_recycleCoIds = array();

    /**
     * @var int $_currentCoId
     */
    public static $_currentCoId = 0;

    /**
     * @return int|mixed
     */
    public static function genCoId()
    {
        $newId = -1;
//        if (empty(self::$_recycleCoIds)) {
            self::$_coIds[] = null;
            $keys = array_keys(self::$_coIds);
            $newId = array_pop($keys);
            self::$_coIds[$newId] = $newId;
//        } else {
//            $newId = array_shift(self::$_recycleCoIds);
//        }
        self::$_currentCoId = $newId;
        return $newId;
    }

    /**
     * @return integer
     */
    public static function getCurrentCoId()
    {
        return self::$_currentCoId;
    }

    /**
     * @param resource  $socket
     */
    public static function refreshCoIdBySocket($socket)
    {
        self::$_currentCoId = self::getCoIdBySocket($socket);
    }

    /**
     * @param integer       $coId
     * @param resource|null $socket
     * @param mixed         $data
     */
    public static function coSend($coId, $socket, $data)
    {
        /**
         * @var \Generator $gen
         */
        $gen = self::$_coroutines[$coId];
        if ($gen instanceof \Generator) {
            self::$_currentCoId = $coId;
            $gen->send(array('socket' => $socket, 'data' => $data));
        }
    }

    /**
     * @param integer       $coId
     * @param resource|null $socket
     * @param \Exception    $exception
     */
    public static function coThrow($coId, $socket, $exception)
    {
        /**
         * @var \Generator $gen
         */
        $gen = self::$_coroutines[$coId];
        if ($gen instanceof \Generator) {
            $gen->throw($exception);
        }
    }

    /**
     * @param resource $socket
     * @return mixed|null
     */
    protected static function getCoIdBySocket($socket)
    {
        $coId = -1;
        if (isset(self::$_connectionSocketToCoId[(string)$socket])) {
            $coId = self::$_connectionSocketToCoId[(string)$socket];
        }
        return $coId;
    }

    /**
     * @param resource $socket
     *
     * @return \Generator|null
     */
    protected static function getGenBySocket($socket)
    {
        $coId = self::getCoIdBySocket($socket);
        if (isset(self::$_coroutines[$coId])) {
            /**
             * @var \Generator $gen
             */
            $gen = self::$_coroutines[$coId];
            return $gen;
        } else {
            return null;
        }
    }

    /**
     * @param \Generator $generator
     * @param integer    $coId
     */
    public static function addCoroutine($generator, $coId = null)
    {
        if (null === $coId) {
            $coId = self::genCoId();
        }
        self::$_coroutines[$coId] = $generator;
    }

    /**
     * 移除协程
     *
     * @param integer   $coId
     *
     * @return boolean
     */
    public static function removeCoroutine($coId)
    {
        if (isset(self::$_coroutines[$coId])) {
            // 移除生成器
            unset(self::$_coroutines[$coId]);
            // 移除生成器ID
            unset(self::$_coIds[$coId]);
            // 回收生成器ID
            self::$_recycleCoIds[$coId] = $coId;
            return true;
        } else {
            return false;
        }
    }

    /**
     * 保存当前socket 对应的 生成器
     *
     * @param resource $socket
     */
    public static function setCoIdBySocket($socket)
    {
        $resId = (string)$socket;
        if (isset(self::$_socketToCoId[$resId]))
            return;
        $coId = CoWorker::getCurrentCoId();
        self::$_socketToCoId[$resId] = $coId;
    }

    /**
     * 通过 socket 移除相关生成器
     *
     * @param resource  $socket
     * @return bool
     */
    protected static function removeGeneratorBySocket($socket)
    {
        $coId = self::getCoIdBySocket($socket);
        return self::removeCoroutine($coId);
    }

}