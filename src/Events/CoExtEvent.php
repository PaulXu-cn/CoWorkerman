<?php

namespace CoWorkerman\Events;

use Event;
use EventBase;
use SplObjectStorage;
use BadMethodCallException;
use React\EventLoop\Timer\Timer;
use React\EventLoop\LoopInterface;
use React\EventLoop\SignalsHandler;
use React\EventLoop\TimerInterface;
use React\EventLoop\Tick\FutureTickQueue;
use Workerman\Worker;
use Workerman\Events\EventInterface;

/**
 * Class CoExtEvent
 *
 * @package CoWorkerman\Events
 */
class CoExtEvent implements EventInterface, LoopInterface
{
    private $eventBase;
    private $futureTickQueue;
    private $timerCallback;
    private $timerEvents;
    private $streamCallback;
    private $readEvents = array();
    private $writeEvents = array();
    private $readListeners = array();
    private $writeListeners = array();
    private $readRefs = array();
    private $writeRefs = array();
    private $running;
    private $signals;
    private $signalEvents = array();

    public function __construct()
    {
        if (!\class_exists('EventBase', false)) {
            throw new BadMethodCallException('Cannot create ExtEventLoop, ext-event extension missing');
        }

        // support arbitrary file descriptors and not just sockets
        // Windows only has limited file descriptor support, so do not require this (will fail otherwise)
        // @link http://www.wangafu.net/~nickm/libevent-book/Ref2_eventbase.html#_setting_up_a_complicated_event_base
        $config = new \EventConfig();
        if (\DIRECTORY_SEPARATOR !== '\\') {
            $config->requireFeatures(\EventConfig::FEATURE_FDS);
        }

        $this->_eventBase = $this->eventBase = new EventBase($config);
        $this->futureTickQueue = new FutureTickQueue();
        $this->timerEvents = new SplObjectStorage();
        $this->signals = new SignalsHandler();

        $this->createTimerCallback();
        $this->createStreamCallback();
    }

//    public function __construct()
//    {
//        if (\class_exists('\\\\EventBase', false)) {
//            $class_name = '\\\\EventBase';
//        } else {
//            $class_name = '\EventBase';
//        }
//        $this->_eventBase = new $class_name();
//    }

    public function __destruct()
    {
        // explicitly clear all references to Event objects to prevent SEGFAULTs on Windows
        foreach ($this->timerEvents as $timer) {
            $this->timerEvents->detach($timer);
        }

        $this->readEvents = array();
        $this->writeEvents = array();
    }

    public function addReadStream($stream, $listener)
    {
        $key = (int) $stream;
        if (isset($this->readListeners[$key])) {
            return;
        }

        $event = new Event($this->eventBase, $stream, Event::PERSIST | Event::READ, $this->streamCallback);
        $event->add();
        $this->readEvents[$key] = $event;
        $this->readListeners[$key] = $listener;

        // ext-event does not increase refcount on stream resources for PHP 7+
        // manually keep track of stream resource to prevent premature garbage collection
        if (\PHP_VERSION_ID >= 70000) {
            $this->readRefs[$key] = $stream;
        }
    }

    public function addWriteStream($stream, $listener)
    {
        $key = (int) $stream;
        if (isset($this->writeListeners[$key])) {
            return;
        }

        $event = new Event($this->eventBase, $stream, Event::PERSIST | Event::WRITE, $this->streamCallback);
        $event->add();
        $this->writeEvents[$key] = $event;
        $this->writeListeners[$key] = $listener;

        // ext-event does not increase refcount on stream resources for PHP 7+
        // manually keep track of stream resource to prevent premature garbage collection
        if (\PHP_VERSION_ID >= 70000) {
            $this->writeRefs[$key] = $stream;
        }
    }

    public function removeReadStream($stream)
    {
        $key = (int) $stream;

        if (isset($this->readEvents[$key])) {
            $this->readEvents[$key]->free();
            unset(
                $this->readEvents[$key],
                $this->readListeners[$key],
                $this->readRefs[$key]
            );
        }
    }

    public function removeWriteStream($stream)
    {
        $key = (int) $stream;

        if (isset($this->writeEvents[$key])) {
            $this->writeEvents[$key]->free();
            unset(
                $this->writeEvents[$key],
                $this->writeListeners[$key],
                $this->writeRefs[$key]
            );
        }
    }

    public function addTimer($interval, $callback)
    {
        $timer = new Timer($interval, $callback, false);

        $this->scheduleTimer($timer);

        return $timer;
    }

    public function addPeriodicTimer($interval, $callback)
    {
        $timer = new Timer($interval, $callback, true);

        $this->scheduleTimer($timer);

        return $timer;
    }

    public function cancelTimer(TimerInterface $timer)
    {
        if ($this->timerEvents->contains($timer)) {
            $this->timerEvents[$timer]->free();
            $this->timerEvents->detach($timer);
        }
    }

    public function futureTick($listener)
    {
        $this->futureTickQueue->add($listener);
    }

    public function addSignal($signal, $listener)
    {
        $this->signals->add($signal, $listener);

        if (!isset($this->signalEvents[$signal])) {
            $this->signalEvents[$signal] = Event::signal($this->eventBase, $signal, array($this->signals, 'call'));
            $this->signalEvents[$signal]->add();
        }
    }

    public function removeSignal($signal, $listener)
    {
        $this->signals->remove($signal, $listener);

        if (isset($this->signalEvents[$signal]) && $this->signals->count($signal) === 0) {
            $this->signalEvents[$signal]->free();
            unset($this->signalEvents[$signal]);
        }
    }

    public function run()
    {
        $this->running = true;

        while ($this->running) {
            $this->futureTickQueue->tick();

            $flags = EventBase::LOOP_ONCE;
            if (!$this->running || !$this->futureTickQueue->isEmpty()) {
                $flags |= EventBase::LOOP_NONBLOCK;
            } elseif (!$this->readEvents && !$this->writeEvents && !$this->timerEvents->count() && $this->signals->isEmpty()) {
                break;
            }

            $this->eventBase->loop($flags);
        }
    }

    public function stop()
    {
        $this->running = false;
    }

    /**
     * Schedule a timer for execution.
     *
     * @param TimerInterface $timer
     */
    private function scheduleTimer(TimerInterface $timer)
    {
        $flags = Event::TIMEOUT;

        if ($timer->isPeriodic()) {
            $flags |= Event::PERSIST;
        }

        $event = new Event($this->eventBase, -1, $flags, $this->timerCallback, $timer);
        $this->timerEvents[$timer] = $event;

        $event->add($timer->getInterval());
    }

    /**
     * Create a callback used as the target of timer events.
     *
     * A reference is kept to the callback for the lifetime of the loop
     * to prevent "Cannot destroy active lambda function" fatal error from
     * the event extension.
     */
    private function createTimerCallback()
    {
        $timers = $this->timerEvents;
        $this->timerCallback = function ($_, $__, $timer) use ($timers) {
            \call_user_func($timer->getCallback(), $timer);

            if (!$timer->isPeriodic() && $timers->contains($timer)) {
                $this->cancelTimer($timer);
            }
        };
    }

    /**
     * Create a callback used as the target of stream events.
     *
     * A reference is kept to the callback for the lifetime of the loop
     * to prevent "Cannot destroy active lambda function" fatal error from
     * the event extension.
     */
    private function createStreamCallback()
    {
        $read =& $this->readListeners;
        $write =& $this->writeListeners;
        $this->streamCallback = function ($stream, $flags) use (&$read, &$write) {
            $key = (int) $stream;

            if (Event::READ === (Event::READ & $flags) && isset($read[$key])) {
                \call_user_func($read[$key], $stream);
            }

            if (Event::WRITE === (Event::WRITE & $flags) && isset($write[$key])) {
                \call_user_func($write[$key], $stream);
            }
        };
    }

    // --------------------- react event loop end

    /**
     * Event base.
     * @var object
     */
    protected $_eventBase = null;

    /**
     * All listeners for read/write event.
     * @var array
     */
    protected $_allEvents = array();

    /**
     * Event listeners of signal.
     * @var array
     */
    protected $_eventSignal = array();

    /**
     * All timer event listeners.
     * [func, args, event, flag, time_interval]
     * @var array
     */
    protected $_eventTimer = array();

    /**
     * Timer id.
     * @var int
     */
    protected static $_timerId = 1;

    /**
     * @see EventInterface::add()
     */
    public function add($fd, $flag, $func, $args=array())
    {
        if (\class_exists('\\\\Event', false)) {
            $class_name = '\\\\Event';
        } else {
            $class_name = '\Event';
        }
        switch ($flag) {
            case self::EV_SIGNAL:

                $fd_key = (int)$fd;
                $event = $class_name::signal($this->_eventBase, $fd, $func);
                if (!$event||!$event->add()) {
                    return false;
                }
                $this->_eventSignal[$fd_key] = $event;
                return true;

            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:

                $param = array($func, (array)$args, $flag, $fd, self::$_timerId);
                $event = new $class_name($this->_eventBase, -1, $class_name::TIMEOUT|$class_name::PERSIST, array($this, "timerCallback"), $param);
                if (!$event||!$event->addTimer($fd)) {
                    return false;
                }
                $this->_eventTimer[self::$_timerId] = $event;
                return self::$_timerId++;

            default :
                $fd_key = (int)$fd;
                $real_flag = $flag === self::EV_READ ? $class_name::READ | $class_name::PERSIST : $class_name::WRITE | $class_name::PERSIST;
                $event = new $class_name($this->_eventBase, $fd, $real_flag, $func, $fd);
                if (!$event||!$event->add()) {
                    return false;
                }
                $this->_allEvents[$fd_key][$flag] = $event;
                return true;
        }
    }

    /**
     * @see \Workerman\Events\EventInterface::del()
     */
    public function del($fd, $flag)
    {
        switch ($flag) {

            case self::EV_READ:
            case self::EV_WRITE:

                $fd_key = (int)$fd;
                if (isset($this->_allEvents[$fd_key][$flag])) {
                    $this->_allEvents[$fd_key][$flag]->del();
                    unset($this->_allEvents[$fd_key][$flag]);
                }
                if (empty($this->_allEvents[$fd_key])) {
                    unset($this->_allEvents[$fd_key]);
                }
                break;

            case  self::EV_SIGNAL:
                $fd_key = (int)$fd;
                if (isset($this->_eventSignal[$fd_key])) {
                    $this->_eventSignal[$fd_key]->del();
                    unset($this->_eventSignal[$fd_key]);
                }
                break;

            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                if (isset($this->_eventTimer[$fd])) {
                    $this->_eventTimer[$fd]->del();
                    unset($this->_eventTimer[$fd]);
                }
                break;
        }
        return true;
    }

    /**
     * Timer callback.
     * @param null $fd
     * @param int $what
     * @param int $timer_id
     */
    public function timerCallback($fd, $what, $param)
    {
        $timer_id = $param[4];

        if ($param[2] === self::EV_TIMER_ONCE) {
            $this->_eventTimer[$timer_id]->del();
            unset($this->_eventTimer[$timer_id]);
        }

        try {
            \call_user_func_array($param[0], $param[1]);
        } catch (\Exception $e) {
            \Workerman\Worker::log($e);
            exit(250);
        } catch (\Error $e) {
            Worker::log($e);
            exit(250);
        }
    }

    /**
     * @see \Workerman\Events\EventInterface::clearAllTimer()
     * @return void
     */
    public function clearAllTimer()
    {
        foreach ($this->_eventTimer as $event) {
            $event->del();
        }
        $this->_eventTimer = array();
    }


    /**
     * @see EventInterface::loop()
     */
    public function loop()
    {
        $this->_eventBase->loop();
    }

    /**
     * Destroy loop.
     *
     * @return void
     */
    public function destroy()
    {
        foreach ($this->_eventSignal as $event) {
            $event->del();
        }
    }

    /**
     * Get timer count.
     *
     * @return integer
     */
    public function getTimerCount()
    {
        return \count($this->_eventTimer);
    }
}
