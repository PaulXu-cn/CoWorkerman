<?php

namespace CoWorkerman\Coroutine;

/**
 * Class Promise
 *
 * @package Workerman\Coroutine
 */
class Promise
{

    /**
     * @param \Generator[] $params
     * @return array|\Generator
     * @throws \Exception
     */
    public static function all($params, $func = '')
    {
        $result = array();
        $genInfos = array();
        /**
         * @var TcpSocketPromise    $gen
         */
        $gen = null;
        foreach ($params as $key => $gen) {
            if ($gen instanceof \Generator) {
                $re = $genRe = $gen->current();
                $genInfos[$key] = array('gen' => $gen, 'data' => $re);
            } else {
                $call = $gen->getFunction();
                if ($call instanceof \Generator) {
                    // real call
                    $re = $call->current();
                    $genInfos[$key] = array('gen' => $gen, 'data' => $re);
                } else {
                    throw new \Exception('wait 方法只接受一个生成器');
                }
            }
        }

        $ignoreKey = $childReturn = array();
        do {
            $collection = array();
            $deepGen = array();
            $finishedC = 0;

            // 都current了，判断下getReturn
            foreach ($genInfos as $key => $info) {
                try {
                    /**
                     * @var \Generator|mixed    $return
                     */
                    $return = null;
                    if (isset($info['return'])) {
                        $return = $info['return'];
                    } else {
                        $return = $info['gen']->getReturn();
                        $genInfos[$key]['return'] = $return;
                        $ignoreKey[] = $key;
                    }
                    if ($return instanceof \Generator) {
                        $deepGen[$key] = $return;
                    } else {
                        $result[$key] = $return;
                        $finishedC ++;
                    }
                } catch (\Exception $e) {
                    // 那就是没完事, 接受输入
                    $genOrData = $info['gen']->current();
                    if ($genOrData instanceof \Generator) {
                        $deepGen[$key] = $genOrData;
                    } else {
                        $collection[$key] = yield $info['data'];
                    }
                }
            }

            // 接收数据搜集完了，就开始放入对应生成器
            foreach ($genInfos as $key => $info) {
                if (in_array($key, $ignoreKey))
                    continue;

                if ($info['data'] instanceof \Generator) {
                    $deepGen[$key] = $info['gen'];
                } else {
                    foreach ($collection as $data) {
                        $socket = $data['socket'];
                        $genSocket = $info['data'];
                        if ($socket === $genSocket) {
                            $info['gen']->send($data);
                        }
                    }
                }
            }

            if (!empty($deepGen)) {
                $childReturn = yield from self::all($deepGen, 'Promise::all');

                foreach ($genInfos as $key => $info) {
                    if (isset($childReturn[$key])) {
                        $result[$key] = $childReturn[$key];
                        $genInfos[$key]['return'] = $childReturn[$key];
                    }
                }
            }

            if ($finishedC >= count($genInfos))
                break;
        } while (true);

        return empty($result) ? $childReturn : $result;
    }

    /**
     * @param $gen
     *
     * @return \Generator|mixed
     * @throws \Exception
     */
    public static function wait($gen, $func = '')
    {
        $result = array();
        $deepGen = array();
        /**
         * @var \Generator  $call
         */
        $call = null;
        /**
         * @var TcpSocketPromise    $gen
         */
        if ($gen instanceof \Generator) {
            do {
                $socket = $gen->current();
                $data = yield $socket;
                $gen->send($data);

                try {
                    $return = $gen->getReturn();

                    if ($return instanceof \Generator) {
                        $deepGen = $return;
                    } else {
                        $result = $return;
                    }
                    // 已到return，可以跳出
                    break;
                } catch (\Exception $e) {
                    // 还没运行完事, 还不能跳出
                }
            } while (true);

            if (!empty($deepGen)) {
                $result = yield from self::wait($deepGen, 'Promise:await');
            }
            return $result;
        } elseif ($gen instanceof Promise) {
            $call = $gen->getFunction();
            if ($call instanceof \Generator) {
                // real call
                $socket = $call->current();
                $data = yield $socket;
                $call->send($data);
                return $data['data'];
            } else {
                throw new \Exception('wait 方法只接受一个生成器');
            }
        }
        return $result;
    }

    public static function race($params, $func = '')
    {

    }

    public static function any($params, $func = '')
    {

    }
}
