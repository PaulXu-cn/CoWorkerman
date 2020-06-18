<?php

namespace CoWorkerman\Events;

use React\EventLoop\Timer\Timer;
use React\EventLoop\Timer\Timers;
use React\EventLoop\LoopInterface;
use React\EventLoop\SignalsHandler;
use React\EventLoop\TimerInterface;
use React\EventLoop\Tick\FutureTickQueue;
use Workerman\Events\EventInterface;

class CoStreamSelect implements EventInterface, LoopInterface
{
    /** @internal */
    const MICROSECONDS_PER_SECOND = 1000000;

    private $futureTickQueue;
    private $timers;
    private $readStreams = array();
    private $readListeners = array();
    private $writeStreams = array();
    private $writeListeners = array();
    private $running;
    private $pcntl = false;
    private $pcntlPoll = false;
    private $signals;

    public function __construct()
    {
        $this->futureTickQueue = new FutureTickQueue();
        $this->timers = new Timers();
        $this->pcntl = \function_exists('pcntl_signal') && \function_exists('pcntl_signal_dispatch');
        $this->pcntlPoll = $this->pcntl && !\function_exists('pcntl_async_signals');
        $this->signals = new SignalsHandler();

        // prefer async signals if available (PHP 7.1+) or fall back to dispatching on each tick
        if ($this->pcntl && !$this->pcntlPoll) {
            \pcntl_async_signals(true);
        }

        // Init SplPriorityQueue.
        $this->_scheduler = new \SplPriorityQueue();
        $this->_scheduler->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
    }

    /**
     * Construct.
     */
//    public function __construct()
//    {
//        // Init SplPriorityQueue.
//        $this->_scheduler = new \SplPriorityQueue();
//        $this->_scheduler->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
//    }

    public function addReadStream($stream, $listener)
    {
        $key = (int) $stream;

        if (!isset($this->readStreams[$key])) {
            $this->readStreams[$key] = $stream;
            $this->readListeners[$key] = $listener;
        }
    }

    public function addWriteStream($stream, $listener)
    {
        $key = (int) $stream;

        if (!isset($this->writeStreams[$key])) {
            $this->writeStreams[$key] = $stream;
            $this->writeListeners[$key] = $listener;
        }
    }

    public function removeReadStream($stream)
    {
        $key = (int) $stream;

        unset(
            $this->readStreams[$key],
            $this->readListeners[$key]
        );
    }

    public function removeWriteStream($stream)
    {
        $key = (int) $stream;

        unset(
            $this->writeStreams[$key],
            $this->writeListeners[$key]
        );
    }

    public function addTimer($interval, $callback)
    {
        $timer = new Timer($interval, $callback, false);

        $this->timers->add($timer);

        return $timer;
    }

    public function addPeriodicTimer($interval, $callback)
    {
        $timer = new Timer($interval, $callback, true);

        $this->timers->add($timer);

        return $timer;
    }

    public function cancelTimer(TimerInterface $timer)
    {
        $this->timers->cancel($timer);
    }

    public function futureTick($listener)
    {
        $this->futureTickQueue->add($listener);
    }

    public function addSignal($signal, $listener)
    {
        if ($this->pcntl === false) {
            throw new \BadMethodCallException('Event loop feature "signals" isn\'t supported by the "StreamSelectLoop"');
        }

        $first = $this->signals->count($signal) === 0;
        $this->signals->add($signal, $listener);

        if ($first) {
            \pcntl_signal($signal, array($this->signals, 'call'));
        }
    }

    public function removeSignal($signal, $listener)
    {
        if (!$this->signals->count($signal)) {
            return;
        }

        $this->signals->remove($signal, $listener);

        if ($this->signals->count($signal) === 0) {
            \pcntl_signal($signal, \SIG_DFL);
        }
    }

    public function run()
    {
        $this->running = true;

        while ($this->running) {
            $this->futureTickQueue->tick();

            $this->timers->tick();

            // Future-tick queue has pending callbacks ...
            if (!$this->running || !$this->futureTickQueue->isEmpty()) {
                $timeout = 0;

                // There is a pending timer, only block until it is due ...
            } elseif ($scheduledAt = $this->timers->getFirst()) {
                $timeout = $scheduledAt - $this->timers->getTime();
                if ($timeout < 0) {
                    $timeout = 0;
                } else {
                    // Convert float seconds to int microseconds.
                    // Ensure we do not exceed maximum integer size, which may
                    // cause the loop to tick once every ~35min on 32bit systems.
                    $timeout *= self::MICROSECONDS_PER_SECOND;
                    $timeout = $timeout > \PHP_INT_MAX ? \PHP_INT_MAX : (int)$timeout;
                }

                // The only possible event is stream or signal activity, so wait forever ...
            } elseif ($this->readStreams || $this->writeStreams || !$this->signals->isEmpty()) {
                $timeout = null;

                // There's nothing left to do ...
            } else {
                break;
            }

            $this->waitForStreamActivity($timeout);
        }
    }

    public function stop()
    {
        $this->running = false;
    }

    /**
     * Wait/check for stream activity, or until the next timer is due.
     *
     * @param integer|null $timeout Activity timeout in microseconds, or null to wait forever.
     */
    private function waitForStreamActivity($timeout)
    {
        $read  = $this->readStreams;
        $write = $this->writeStreams;

        $available = $this->streamSelect($read, $write, $timeout);
        if ($this->pcntlPoll) {
            \pcntl_signal_dispatch();
        }
        if (false === $available) {
            // if a system call has been interrupted,
            // we cannot rely on it's outcome
            return;
        }

        foreach ($read as $stream) {
            $key = (int) $stream;

            if (isset($this->readListeners[$key])) {
                \call_user_func($this->readListeners[$key], $stream);
            }
        }

        foreach ($write as $stream) {
            $key = (int) $stream;

            if (isset($this->writeListeners[$key])) {
                \call_user_func($this->writeListeners[$key], $stream);
            }
        }
    }

    /**
     * Emulate a stream_select() implementation that does not break when passed
     * empty stream arrays.
     *
     * @param array    $read    An array of read streams to select upon.
     * @param array    $write   An array of write streams to select upon.
     * @param int|null $timeout Activity timeout in microseconds, or null to wait forever.
     *
     * @return int|false The total number of streams that are ready for read/write.
     *     Can return false if stream_select() is interrupted by a signal.
     */
    private function streamSelect(array &$read, array &$write, $timeout)
    {
        if ($read || $write) {
            // We do not usually use or expose the `exceptfds` parameter passed to the underlying `select`.
            // However, Windows does not report failed connection attempts in `writefds` passed to `select` like most other platforms.
            // Instead, it uses `writefds` only for successful connection attempts and `exceptfds` for failed connection attempts.
            // We work around this by adding all sockets that look like a pending connection attempt to `exceptfds` automatically on Windows and merge it back later.
            // This ensures the public API matches other loop implementations across all platforms (see also test suite or rather test matrix).
            // Lacking better APIs, every write-only socket that has not yet read any data is assumed to be in a pending connection attempt state.
            // @link https://docs.microsoft.com/de-de/windows/win32/api/winsock2/nf-winsock2-select
            $except = null;
            if (\DIRECTORY_SEPARATOR === '\\') {
                $except = array();
                foreach ($write as $key => $socket) {
                    if (!isset($read[$key]) && @\ftell($socket) === 0) {
                        $except[$key] = $socket;
                    }
                }
            }

            // suppress warnings that occur, when stream_select is interrupted by a signal
            $ret = @\stream_select($read, $write, $except, $timeout === null ? null : 0, $timeout);

            if ($except) {
                $write = \array_merge($write, $except);
            }
            return $ret;
        }

        if ($timeout > 0) {
            \usleep($timeout);
        } elseif ($timeout === null) {
            // wait forever (we only reach this if we're only awaiting signals)
            // this may be interrupted and return earlier when a signal is received
            \sleep(PHP_INT_MAX);
        }

        return 0;
    }

    /**
     * All listeners for read/write event.
     *
     * @var array
     */
    public $_allEvents = array();

    /**
     * Event listeners of signal.
     *
     * @var array
     */
    public $_signalEvents = array();

    /**
     * Fds waiting for read event.
     *
     * @var array
     */
    protected $_readFds = array();

    /**
     * Fds waiting for write event.
     *
     * @var array
     */
    protected $_writeFds = array();

    /**
     * Fds waiting for except event.
     *
     * @var array
     */
    protected $_exceptFds = array();

    /**
     * Timer scheduler.
     * {['data':timer_id, 'priority':run_timestamp], ..}
     *
     * @var \SplPriorityQueue
     */
    protected $_scheduler = null;

    /**
     * All timer event listeners.
     * [[func, args, flag, timer_interval], ..]
     *
     * @var array
     */
    protected $_eventTimer = array();

    /**
     * Timer id.
     *
     * @var int
     */
    protected $_timerId = 1;

    /**
     * Select timeout.
     *
     * @var int
     */
    protected $_selectTimeout = 100000000;

    /**
     * Paired socket channels
     *
     * @var array
     */
    protected $channel = array();



    /**
     * {@inheritdoc}
     */
    public function add($fd, $flag, $func, $args = array())
    {
        switch ($flag) {
            case self::EV_READ:
            case self::EV_WRITE:
                $count = $flag === self::EV_READ ? \count($this->_readFds) : \count($this->_writeFds);
                if ($count >= 1024) {
                    echo "Warning: system call select exceeded the maximum number of connections 1024, please install event/libevent extension for more connections.\n";
                } else if (\DIRECTORY_SEPARATOR !== '/' && $count >= 256) {
                    echo "Warning: system call select exceeded the maximum number of connections 256.\n";
                }
                $fd_key                           = (int)$fd;
                $this->_allEvents[$fd_key][$flag] = array($func, $fd);
                if ($flag === self::EV_READ) {
                    $this->_readFds[$fd_key] = $fd;
                } else {
                    $this->_writeFds[$fd_key] = $fd;
                }
                break;
            case self::EV_EXCEPT:
                $fd_key = (int)$fd;
                $this->_allEvents[$fd_key][$flag] = array($func, $fd);
                $this->_exceptFds[$fd_key] = $fd;
                break;
            case self::EV_SIGNAL:
                // Windows not support signal.
                if(\DIRECTORY_SEPARATOR !== '/') {
                    return false;
                }
                $fd_key                              = (int)$fd;
                $this->_signalEvents[$fd_key][$flag] = array($func, $fd);
                \pcntl_signal($fd, array($this, 'signalHandler'));
                break;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                $timer_id = $this->_timerId++;
                $run_time = \microtime(true) + $fd;
                $this->_scheduler->insert($timer_id, -$run_time);
                $this->_eventTimer[$timer_id] = array($func, (array)$args, $flag, $fd);
                $select_timeout = ($run_time - \microtime(true)) * 1000000;
                if( $this->_selectTimeout > $select_timeout ){
                    $this->_selectTimeout = $select_timeout;
                }
                return $timer_id;
        }

        return true;
    }

    /**
     * Signal handler.
     *
     * @param int $signal
     */
    public function signalHandler($signal)
    {
        \call_user_func_array($this->_signalEvents[$signal][self::EV_SIGNAL][0], array($signal));
    }

    /**
     * {@inheritdoc}
     */
    public function del($fd, $flag)
    {
        $fd_key = (int)$fd;
        switch ($flag) {
            case self::EV_READ:
                unset($this->_allEvents[$fd_key][$flag], $this->_readFds[$fd_key]);
                if (empty($this->_allEvents[$fd_key])) {
                    unset($this->_allEvents[$fd_key]);
                }
                return true;
            case self::EV_WRITE:
                unset($this->_allEvents[$fd_key][$flag], $this->_writeFds[$fd_key]);
                if (empty($this->_allEvents[$fd_key])) {
                    unset($this->_allEvents[$fd_key]);
                }
                return true;
            case self::EV_EXCEPT:
                unset($this->_allEvents[$fd_key][$flag], $this->_exceptFds[$fd_key]);
                if(empty($this->_allEvents[$fd_key]))
                {
                    unset($this->_allEvents[$fd_key]);
                }
                return true;
            case self::EV_SIGNAL:
                if(\DIRECTORY_SEPARATOR !== '/') {
                    return false;
                }
                unset($this->_signalEvents[$fd_key]);
                \pcntl_signal($fd, SIG_IGN);
                break;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE;
                unset($this->_eventTimer[$fd_key]);
                return true;
        }
        return false;
    }

    /**
     * Tick for timer.
     *
     * @return void
     */
    protected function tick()
    {
        while (!$this->_scheduler->isEmpty()) {
            $scheduler_data       = $this->_scheduler->top();
            $timer_id             = $scheduler_data['data'];
            $next_run_time        = -$scheduler_data['priority'];
            $time_now             = \microtime(true);
            $this->_selectTimeout = ($next_run_time - $time_now) * 1000000;
            if ($this->_selectTimeout <= 0) {
                $this->_scheduler->extract();

                if (!isset($this->_eventTimer[$timer_id])) {
                    continue;
                }

                // [func, args, flag, timer_interval]
                $task_data = $this->_eventTimer[$timer_id];
                if ($task_data[2] === self::EV_TIMER) {
                    $next_run_time = $time_now + $task_data[3];
                    $this->_scheduler->insert($timer_id, -$next_run_time);
                }
                \call_user_func_array($task_data[0], $task_data[1]);
                if (isset($this->_eventTimer[$timer_id]) && $task_data[2] === self::EV_TIMER_ONCE) {
                    $this->del($timer_id, self::EV_TIMER_ONCE);
                }
                continue;
            }
            return;
        }
        $this->_selectTimeout = 100000000;
    }

    /**
     * {@inheritdoc}
     */
    public function clearAllTimer()
    {
        $this->_scheduler = new \SplPriorityQueue();
        $this->_scheduler->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
        $this->_eventTimer = array();
    }

    /**
     * {@inheritdoc}
     */
    public function loop()
    {
        while (1) {
            if(\DIRECTORY_SEPARATOR === '/') {
                // Calls signal handlers for pending signals
                \pcntl_signal_dispatch();
            }

            $read  = $this->_readFds;
            $write = $this->_writeFds;
            $except = $this->_exceptFds;

            if ($read || $write || $except) {
                // Waiting read/write/signal/timeout events.
                set_error_handler(function(){});
                $ret = stream_select($read, $write, $except, 0, $this->_selectTimeout);
                restore_error_handler();
            } else {
                usleep($this->_selectTimeout);
                $ret = false;
            }


            if (!$this->_scheduler->isEmpty()) {
                $this->tick();
            }

            if (!$ret) {
                continue;
            }

            if ($read) {
                foreach ($read as $fd) {
                    $fd_key = (int)$fd;
                    if (isset($this->_allEvents[$fd_key][self::EV_READ])) {
                        \call_user_func_array($this->_allEvents[$fd_key][self::EV_READ][0],
                            array($this->_allEvents[$fd_key][self::EV_READ][1]));
                    }
                }
            }

            if ($write) {
                foreach ($write as $fd) {
                    $fd_key = (int)$fd;
                    if (isset($this->_allEvents[$fd_key][self::EV_WRITE])) {
                        \call_user_func_array($this->_allEvents[$fd_key][self::EV_WRITE][0],
                            array($this->_allEvents[$fd_key][self::EV_WRITE][1]));
                    }
                }
            }

            if($except) {
                foreach($except as $fd) {
                    $fd_key = (int) $fd;
                    if(isset($this->_allEvents[$fd_key][self::EV_EXCEPT])) {
                        \call_user_func_array($this->_allEvents[$fd_key][self::EV_EXCEPT][0],
                            array($this->_allEvents[$fd_key][self::EV_EXCEPT][1]));
                    }
                }
            }
        }
    }

    /**
     * Destroy loop.
     *
     * @return void
     */
    public function destroy()
    {

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
