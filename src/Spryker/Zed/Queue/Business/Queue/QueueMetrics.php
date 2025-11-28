<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Queue;

class QueueMetrics
{
    /**
     * @var string
     */
    protected string $queueName;

    /**
     * @var string|null
     */
    protected ?string $storeName;

    /**
     * @var string|null
     */
    protected ?string $regionName;

    /**
     * @var int
     */
    protected int $messageCount;

    /**
     * @var int
     */
    protected int $batchSize;

    /**
     * @var int
     */
    protected int $priority;

    /**
     * @var int
     */
    protected int $messageToChunkSizeRatio;

    /**
     * @return string
     */
    public function getQueueName(): string
    {
        return $this->queueName;
    }

    /**
     * @param string|null $queueName
     *
     * @return $this
     */
    public function setQueueName(?string $queueName)
    {
        $this->queueName = $queueName;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getStoreName(): ?string
    {
        return $this->storeName;
    }

    /**
     * @param string|null $storeName
     *
     * @return $this
     */
    public function setStoreName(?string $storeName)
    {
        $this->storeName = $storeName;

        return $this;
    }

    /**
     * @return string|null
     */
    public function getRegionName(): ?string
    {
        return $this->regionName;
    }

    /**
     * @param string|null $regionName
     *
     * @return $this
     */
    public function setRegionName(?string $regionName)
    {
        $this->regionName = $regionName;

        return $this;
    }

    /**
     * @return int
     */
    public function getMessageCount(): int
    {
        return $this->messageCount;
    }

    /**
     * @param int $messageCount
     *
     * @return $this
     */
    public function setMessageCount(int $messageCount)
    {
        $this->messageCount = $messageCount;

        return $this;
    }

    /**
     * @return int
     */
    public function getMessageToChunkSizeRatio(): int
    {
        return $this->messageToChunkSizeRatio;
    }

    /**
     * @param int $messageToChunkSizeRatio
     *
     * @return $this
     */
    public function setMessageToChunkSizeRatio(int $messageToChunkSizeRatio)
    {
        $this->messageToChunkSizeRatio = $messageToChunkSizeRatio;

        return $this;
    }

    /**
     * @return int
     */
    public function getBatchSize(): int
    {
        return $this->batchSize;
    }

    /**
     * @param int $batchSize
     *
     * @return $this
     */
    public function setBatchSize(int $batchSize)
    {
        $this->batchSize = $batchSize;

        return $this;
    }

    /**
     * @return int
     */
    public function getPriority(): int
    {
        return $this->priority;
    }

    /**
     * @param int $priority
     *
     * @return $this
     */
    public function setPriority(int $priority)
    {
        $this->priority = $priority;

        return $this;
    }
}
