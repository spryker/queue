<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Strategy;

use Closure;
use Iterator;
use Spryker\Zed\Queue\Business\Queue\QueueMetrics;

/**
 * @implements \Iterator<int, \Spryker\Zed\Queue\Business\Queue\QueueMetrics>
 */
class ConditionBasedIterator implements Iterator
{
    /**
     * @var int
     */
    protected int $currentIdx = 0;

    /**
     * @var \Spryker\Zed\Queue\Business\Queue\QueueMetrics|null
     */
    protected ?QueueMetrics $currentTransfer;

    /**
     * @param \Iterator $iterator
     * @param \Closure|null $repeatConditionCheck
     */
    public function __construct(
        protected Iterator $iterator,
        protected ?Closure $repeatConditionCheck = null
    ) {
    }

    /**
     * @return \Spryker\Zed\Queue\Business\Queue\QueueMetrics|mixed
     */
    public function current(): mixed
    {
        $this->currentTransfer = $this->iterator->current();

        return $this->currentTransfer;
    }

    /**
     * @return void
     */
    public function next(): void
    {
        if (
            $this->repeatConditionCheck &&
            $this->repeatConditionCheck->call($this, $this->currentTransfer, $this->currentIdx)
        ) {
            $this->currentIdx++;

            return;
        }

        $this->iterator->next();
        $this->currentIdx = 0;
    }

    /**
     * @return mixed
     */
    public function key(): mixed
    {
        return $this->iterator->key();
    }

    /**
     * @return bool
     */
    public function valid(): bool
    {
        return $this->iterator->valid();
    }

    /**
     * @return void
     */
    public function rewind(): void
    {
        $this->currentIdx = 0;
        $this->iterator->rewind();
    }
}
