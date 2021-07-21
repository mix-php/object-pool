<?php

namespace Mix\ObjectPool;

use Mix\ObjectPool\Exception\WaitTimeoutException;
use Swoole\Coroutine;
use Swoole\Coroutine\Channel;
use Swoole\Exception;
use Swoole\Timer;

/**
 * Class AbstractObjectPool
 * @package Mix\ObjectPool
 */
abstract class AbstractObjectPool
{

    /**
     * 拨号器
     * @var DialerInterface
     */
    protected $dialer;

    /**
     * 最大活跃数
     * "0" 为不限制，默认等于cpu数量
     * @var int
     */
    protected $maxOpen = -1;

    /**
     * 最多可空闲数
     * 默认等于cpu数量
     * @var int
     */
    protected $maxIdle = -1;

    /**
     * 连接可复用的最长时间
     * "0" 为不限制
     * @var int
     */
    protected $maxLifetime = 0;

    /**
     * 等待新连接超时时间
     * "0" 为不限制
     * @var float
     */
    protected $waitTimeout = 0.0;

    /**
     * 连接队列
     * @var Channel
     */
    protected $queue;

    /**
     * 活跃连接集合
     * @var array
     */
    protected $actives = [];

    /**
     * AbstractObjectPool constructor.
     * @param DialerInterface $dialer
     * @param int $maxOpen
     * @param int $maxIdle
     * @param int $maxLifetime
     * @param float $waitTimeout
     */
    public function __construct(DialerInterface $dialer, int $maxOpen = -1, int $maxIdle = -1, int $maxLifetime = 0, float $waitTimeout = 0.0)
    {
        $this->dialer = $dialer;
        $this->maxOpen = $maxOpen;
        $this->maxIdle = $maxIdle;
        $this->maxLifetime = $maxLifetime;
        $this->waitTimeout = $waitTimeout;
        // 默认连接池数量等于 cpu 数量
        if ($maxOpen == -1) {
            $this->maxOpen = swoole_cpu_num();
        }
        if ($maxIdle == -1) {
            $this->maxIdle = swoole_cpu_num();
        }
        // 创建连接队列
        $this->queue = new Channel($this->maxIdle);
        // 闲置连接数量处理
        // 废弃了以前的：归还时通道满就直接丢弃连接，因为高并发经常扎堆归还扎堆获取导致连接频繁丢弃和创建
        Timer::tick(2000, function () {
            $stats = $this->queue->stats();
            if ($stats['consumer_num'] == 0 && $stats['queue_num'] == $this->maxIdle && $stats['producer_num'] > 0) {
                $this->queue->pop(); // 丢弃
            }
        });
    }

    /**
     * 创建连接
     * @return object
     */
    protected function createConnection()
    {
        // 连接创建时会挂起当前协程，导致 actives 未增加，因此需先 actives++ 连接创建成功后 actives--
        $closure = function () {
            /** @var ObjectTrait $connection */
            $connection = $this->dialer->dial();
            $connection->pool = $this;
            $connection->createTime = time();
            return $connection;
        };
        $id = spl_object_hash($closure);
        $this->actives[$id] = '';
        try {
            $connection = call_user_func($closure);
        } finally {
            unset($this->actives[$id]);
        }
        return $connection;
    }

    /**
     * 借用连接
     * @return object
     * @throws WaitTimeoutException
     */
    public function borrow()
    {
        /** @var ObjectTrait $connection */
        $connection = null;
        if ($this->getIdleNumber() > 0 || ($this->maxOpen && $this->getTotalNumber() >= $this->maxOpen)) {
            // 队列有连接，从队列取
            // 达到最大连接数，从队列取
            $connection = $this->pop();
        } else {
            // 创建连接
            $connection = $this->createConnection();
        }
        // 登记, 队列中出来的也需要登记，因为有可能是 discard 中创建的新连接
        $id = spl_object_hash($connection);
        $this->actives[$id] = ''; // 不可保存外部连接的引用，否则导致外部连接不析构

        // 检查最大生命周期
        if ($this->maxLifetime && $connection->createTime + $this->maxLifetime <= time()) {
            $this->discard($connection);
            return $this->borrow();
        }

        // 返回
        return $connection;
    }

    /**
     * 归还连接
     * @param $connection
     * @return bool
     */
    public function return(object $connection)
    {
        $id = spl_object_hash($connection);
        // 判断是否已释放
        if (!isset($this->actives[$id])) {
            return false;
        }
        // 移除登记
        unset($this->actives[$id]); // 注意：必须是先减 actives，否则会 maxActive - maxIdle <= 1 时会阻塞
        // 入列
        return $this->push($connection);
    }

    /**
     * 丢弃连接
     * @param $connection
     * @return bool
     */
    public function discard(object $connection)
    {
        $id = spl_object_hash($connection);
        // 判断是否已丢弃
        if (!isset($this->actives[$id])) {
            return false;
        }
        // 移除登记
        unset($this->actives[$id]); // 注意：必须是先减 actives，否则会 maxActive - maxIdle <= 1 时会阻塞
        // 入列一个新连接替代丢弃的连接
        return $this->push($this->createConnection());
    }

    /**
     * 获取连接池的统计信息
     * @return array
     */
    public function stats()
    {
        return [
            'total' => $this->getTotalNumber(),
            'idle' => $this->getIdleNumber(),
            'active' => $this->getActiveNumber(),
        ];
    }

    /**
     * 放入连接
     * @param $connection
     * @return bool
     */
    protected function push($connection)
    {
        // 解决对象在协程外部析构导致的: Swoole\Error: API must be called in the coroutine
        if (Coroutine::getCid() == -1) {
            return false;
        }
        return $this->queue->push($connection);
    }

    /**
     * 弹出连接
     * @return mixed
     * @throws WaitTimeoutException
     * @throws Exception
     */
    protected function pop()
    {
        $timeout = -1;
        if ($this->waitTimeout) {
            $timeout = $this->waitTimeout;
        }
        $object = $this->queue->pop($timeout);
        if (!$object) {
            if ($timeout != -1) {
                throw new WaitTimeoutException(sprintf('Wait timeout: %fs', $timeout));
            }
            throw new Exception('Channel a deadlock');
        }
        return $object;
    }

    /**
     * 获取队列中的连接数
     * @return int
     */
    protected function getIdleNumber()
    {
        $count = $this->queue->stats()['queue_num'];
        return $count < 0 ? 0 : $count;
    }

    /**
     * 获取活跃的连接数
     * @return int
     */
    protected function getActiveNumber()
    {
        return count($this->actives);
    }

    /**
     * 获取当前总连接数
     * @return int
     */
    protected function getTotalNumber()
    {
        return $this->getIdleNumber() + $this->getActiveNumber();
    }

}
