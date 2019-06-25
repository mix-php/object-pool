<?php

namespace Mix\Pool;

use Mix\Concurrent\Coroutine;

/**
 * Trait ConnectionTrait
 * @package Mix\Pool
 * @author liu,jian <coder.keda@gmail.com>
 */
trait ConnectionTrait
{

    /**
     * @var \Mix\Pool\ConnectionPoolInterface
     */
    public $connectionPool;

    /**
     * 丢弃连接
     * @return bool
     */
    public function discard()
    {
        if (isset($this->connectionPool)) {
            $this->connectionPool->discard($this);
        }
        return false;
    }

    /**
     * 释放连接
     * @return bool
     */
    public function release()
    {
        if (isset($this->connectionPool)) {
            return $this->connectionPool->release($this);
        }
        return false;
    }

}
