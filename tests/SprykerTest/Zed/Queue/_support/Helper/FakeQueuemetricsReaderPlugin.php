<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Helper;

use Generated\Shared\Transfer\QueueMetricsRequestTransfer;
use Generated\Shared\Transfer\QueueMetricsResponseTransfer;
use Spryker\Zed\QueueExtension\Dependency\Plugin\QueueMetricsReaderPluginInterface;

/**
 * Stands in for the broker-backed queue metrics reader, and counts every lookup.
 */
class FakeQueuemetricsReaderPlugin implements QueueMetricsReaderPluginInterface
{
    /**
     * @var array<string, int>
     */
    protected array $readCallsByKey = [];

    /**
     * @var int
     */
    protected int $readCallCount = 0;

    /**
     * "queueName:storeName" when a store-specific count is wanted.
     *
     * @param array<string, int> $messageCountByQueueName Message count keyed by queue name, or by
     */
    public function __construct(
        protected array $messageCountByQueueName = [],
        protected bool $isApplicable = true
    ) {
    }

    public function read(QueueMetricsRequestTransfer $queuemetricsRequestTransfer): QueueMetricsResponseTransfer
    {
        $queueName = (string)$queuemetricsRequestTransfer->getQueueName();
        $storeName = $queuemetricsRequestTransfer->getStoreName();

        $key = $storeName === null ? $queueName : sprintf('%s:%s', $queueName, $storeName);

        $this->readCallCount++;
        $this->readCallsByKey[$key] = ($this->readCallsByKey[$key] ?? 0) + 1;

        $messageCount = $this->messageCountByQueueName[$key]
            ?? $this->messageCountByQueueName[$queueName]
            ?? 0;

        return (new QueueMetricsResponseTransfer())->setMessageCount($messageCount);
    }

    public function isApplicable(string $adapterClassName): bool
    {
        return $this->isApplicable;
    }

    /**
     * Total number of broker lookups performed since construction.
     *
     * @return int
     */
    public function getReadCallCount(): int
    {
        return $this->readCallCount;
    }

    /**
     * Lookups per "queue" or "queue:store" key.
     *
     * @return array<string, int>
     */
    public function getReadCallsByKey(): array
    {
        return $this->readCallsByKey;
    }

    public function resetCallCounts(): void
    {
        $this->readCallCount = 0;
        $this->readCallsByKey = [];
    }
}
