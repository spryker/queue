<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Strategy;

use ArrayIterator;
use ArrayObject;
use Iterator;
use Spryker\Zed\Queue\Business\Queue\QueueMetrics;
use Spryker\Zed\Queue\Business\Scanner\QueueScannerInterface;

/**
 * This strategy aims at providing queues in the pre-defined order
 * of array keys of the resulting array here
 * \Spryker\Zed\Queue\QueueDependencyProvider::getProcessorMessagePlugins
 */
class OrderedQueueProcessingStrategy implements QueueProcessingStrategyInterface
{
    /**
     * @var \Iterator|\ArrayIterator
     */
    protected Iterator $currentIterator;

    public function __construct(protected QueueScannerInterface $queueScanner)
    {
        $this->currentIterator = new ConditionBasedIterator(new ArrayIterator());
    }

    public function getNextQueue(bool $ignoreEmptyScanCooldown = false): ?QueueMetrics
    {
        if (!$this->currentIterator->valid()) {
            $queuesPerStore = $this->getQueuesWithMessages($ignoreEmptyScanCooldown);

            $this->currentIterator = new ConditionBasedIterator($queuesPerStore->getIterator());
        }

        /** @var \Spryker\Zed\Queue\Business\Queue\QueueMetrics $queueMetrics */
        $queueMetrics = $this->currentIterator->current();
        $this->currentIterator->next();

        return $queueMetrics;
    }

    /**
     * @param bool $ignoreEmptyScanCooldown
     *
     * @return \ArrayObject<int, \Spryker\Zed\Queue\Business\Queue\QueueMetrics>
     */
    protected function getQueuesWithMessages(bool $ignoreEmptyScanCooldown = false): ArrayObject
    {
        return $this->queueScanner->scanQueues(ignoreEmptyScanCooldown: $ignoreEmptyScanCooldown);
    }
}
