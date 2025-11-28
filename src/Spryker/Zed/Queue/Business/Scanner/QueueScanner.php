<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Scanner;

use ArrayObject;
use Generated\Shared\Transfer\QueueMetricsRequestTransfer;
use Generated\Shared\Transfer\QueueMetricsResponseTransfer;
use Generated\Shared\Transfer\StoreCriteriaTransfer;
use Generated\Shared\Transfer\StoreTransfer;
use Spryker\Zed\Queue\Business\Logger\WorkerLoggerInterface;
use Spryker\Zed\Queue\Business\Queue\QueueMetrics;
use Spryker\Zed\Queue\Business\Reader\QueueConfigReaderInterface;
use Spryker\Zed\Queue\QueueConfig;
use Spryker\Zed\Store\Business\StoreFacadeInterface;

class QueueScanner implements QueueScannerInterface
{
    /**
     * @var int
     */
    protected const DEFAULT_QUEUE_MESSAGE_COUNT = 1;

    /**
     * @var float
     */
    protected float $lastScanAt = 0;

    /**
     * @var bool
     */
    protected bool $lastScanHadQueues = false;

    /**
     * @var array<string>|null
     */
    protected static ?array $storeNames = null;

    /**
     * @var array
     */
    protected array $chunkSizeByQueue;

    /**
     * @var array<string>
     */
    protected array $queueAdapterMap = [];

    /**
     * @var int
     */
    protected int $queueCount = 0;

    /**
     * @var int
     */
    protected int $notEmptyQueueCount = 0;

    /**
     * @var int
     */
    protected int $messageCount = 0;

    /**
     * @param \Spryker\Zed\Store\Business\StoreFacadeInterface $storeFacade
     * @param array<string> $queueNames
     * @param array<\Spryker\Zed\Queue\Dependency\Plugin\QueueMessageProcessorPluginInterface> $queueMessageProcessorPlugins
     * @param array<\Spryker\Zed\QueueExtension\Dependency\Plugin\QueueMetricsReaderPluginInterface> $queueMetricsReaderPlugins
     * @param \Spryker\Zed\Queue\Business\Logger\WorkerLoggerInterface $consoleLogger
     * @param \Spryker\Zed\Queue\QueueConfig $queueConfig
     * @param \Spryker\Zed\Queue\Business\Reader\QueueConfigReaderInterface $queueConfigReader
     */
    public function __construct(
        protected StoreFacadeInterface $storeFacade,
        protected array $queueNames,
        array $queueMessageProcessorPlugins,
        protected array $queueMetricsReaderPlugins,
        protected WorkerLoggerInterface $consoleLogger,
        protected QueueConfig $queueConfig,
        protected QueueConfigReaderInterface $queueConfigReader
    ) {
        foreach ($queueMessageProcessorPlugins as $queue => $plugin) {
            $this->chunkSizeByQueue[$queue] = $plugin->getChunkSize();
        }
    }

    /**
     * @param array<string> $storeNames
     * @param int $emptyScanCooldownSeconds
     *
     * @return \ArrayObject<int, \Spryker\Zed\Queue\Business\Queue\QueueMetrics>
     */
    public function scanQueues(array $storeNames = [], int $emptyScanCooldownSeconds = 5): ArrayObject
    {
        $timeSinceLastScan = microtime(true) - $this->lastScanAt;

        if (!$this->lastScanHadQueues && !($timeSinceLastScan > $emptyScanCooldownSeconds)) {
            return new ArrayObject();
        }

        $queueMetrics = $this->directScanQueues($storeNames);

        $this->lastScanAt = microtime(true);
        $this->lastScanHadQueues = $queueMetrics->count() !== 0;

        return $queueMetrics;
    }

    /**
     * @param array<string> $storeNames
     *
     * @return \ArrayObject<int, \Spryker\Zed\Queue\Business\Queue\QueueMetrics>
     */
    protected function directScanQueues(array $storeNames): ArrayObject
    {
        static $scanCount = 0;

        $scanCount++;

        $this->consoleLogger->debug(sprintf('> SCANNING QUEUES - %d...', $scanCount));

        $this->queueCount = 0;

        $this->notEmptyQueueCount = 0;
        $this->messageCount = 0;

        $queueMetrics = new ArrayObject();

        foreach ($this->queueNames as $queueName) {
            foreach ($this->directScanQueue($storeNames, $queueName) as $queueMetricsItem) {
                $queueMetrics->append($queueMetricsItem);
            }
        }

        $messagesPerQueue = 0;
        if ($this->queueCount > 0) {
            $messagesPerQueue = $this->messageCount / $this->queueCount;
        }

        $this->consoleLogger->info(sprintf(
            '> SCANNING %d DONE: %d / %d queues, %d messages total, %d msg/queue avg',
            $scanCount,
            $this->notEmptyQueueCount,
            $this->queueCount,
            $this->messageCount,
            $messagesPerQueue,
        ));

        return $queueMetrics;
    }

    /**
     * @param array<string> $storeNames
     * @param string $queueName
     *
     * @return \ArrayObject<int, \Spryker\Zed\Queue\Business\Queue\QueueMetrics>
     */
    protected function directScanQueue(
        array $storeNames,
        string $queueName
    ): ArrayObject {
        $queueMetrics = new ArrayObject();

        if (!$storeNames && $this->queueConfig->isDynamicStoreEnabled()) {
            $queueMetricsPerLocation = $this->getQueueMetricsPerLocation($queueName);
            if ($queueMetricsPerLocation) {
                $queueMetrics->append($queueMetricsPerLocation);
            }

            return $queueMetrics;
        }

        if (!$storeNames) {
            $storeNames = $this->getCachedStoreNames();
        }

        foreach ($storeNames as $storeName) {
            $queueMetricsPerLocation = $this->getQueueMetricsPerLocation($queueName, $storeName);
            if (!$queueMetricsPerLocation) {
                continue;
            }

            $queueMetrics->append($queueMetricsPerLocation);
        }

        return $queueMetrics;
    }

    /**
     * @param string $queueName
     * @param string|null $storeName
     *
     * @return \Spryker\Zed\Queue\Business\Queue\QueueMetrics|null
     */
    protected function getQueueMetricsPerLocation(
        string $queueName,
        ?string $storeName = null
    ): ?QueueMetrics {
        $this->queueCount++;

        $queueMetricsRequestTransfer = (new QueueMetricsRequestTransfer())
            ->setQueueName($queueName)
            ->setStoreName($storeName);

        $queueMetricsResponseTransfer = $this->readQueueMetrics($queueMetricsRequestTransfer);

        return $this->getMetrics(
            $queueMetricsResponseTransfer,
            $queueName,
            $storeName,
            $this->queueConfig->getCurrentRegion(),
        );
    }

    /**
     * @param \Generated\Shared\Transfer\QueueMetricsResponseTransfer $queueMetricsResponseTransfer
     * @param string $queueName
     * @param string|null $storeName
     * @param string|null $regionName
     *
     * @return \Spryker\Zed\Queue\Business\Queue\QueueMetrics|null
     */
    protected function getMetrics(
        QueueMetricsResponseTransfer $queueMetricsResponseTransfer,
        string $queueName,
        ?string $storeName = null,
        ?string $regionName = null
    ): ?QueueMetrics {
        $queueMetricsResponseTransfer->requireMessageCount();
        $queueMessageCount = $queueMetricsResponseTransfer->getMessageCount();

        if ($queueMessageCount === 0) {
            return null;
        }

        $msgToChunkSizeRatio = (int)round(
            $queueMessageCount / ($this->chunkSizeByQueue[$queueName] ?? $queueMessageCount),
        );

        $this->messageCount += $queueMessageCount;
        $this->notEmptyQueueCount += ($queueMessageCount > 0 ? 1 : 0);

        return (new QueueMetrics())
                ->setQueueName($queueName)
                ->setStoreName($storeName)
                ->setRegionName($regionName)
                ->setMessageCount($queueMessageCount)
                ->setMessageToChunkSizeRatio($msgToChunkSizeRatio)
                ->setBatchSize($this->chunkSizeByQueue[$queueName] ?? 0);
    }

    /**
     * @param \Generated\Shared\Transfer\QueueMetricsRequestTransfer $queueMetricsRequestTransfer
     *
     * @return \Generated\Shared\Transfer\QueueMetricsResponseTransfer
     */
    protected function readQueueMetrics(
        QueueMetricsRequestTransfer $queueMetricsRequestTransfer
    ): QueueMetricsResponseTransfer {
        $queueMetricsResponseTransfer = (new QueueMetricsResponseTransfer())->setMessageCount(static::DEFAULT_QUEUE_MESSAGE_COUNT);

        foreach ($this->queueMetricsReaderPlugins as $queueMetricsReaderPlugin) {
            $queueAdapter = $this->getQueueAdapter($queueMetricsRequestTransfer->getQueueName());
            if (!$queueAdapter) {
                return $queueMetricsResponseTransfer;
            }
            if ($queueMetricsReaderPlugin->isApplicable($queueAdapter)) {
                return $queueMetricsReaderPlugin->read($queueMetricsRequestTransfer);
            }
        }

        return $queueMetricsResponseTransfer;
    }

    /**
     * @return array<string>
     */
    protected function getCachedStoreNames(): array
    {
        if (static::$storeNames === null) {
            static::$storeNames = array_map(function (StoreTransfer $storeTransfer) {
                return $storeTransfer->getName();
            }, $this->storeFacade->getStoreCollection(new StoreCriteriaTransfer())->getStores()->getArrayCopy());
        }

        return static::$storeNames;
    }

    /**
     * @param string $queueName
     *
     * @return string|null
     */
    protected function getQueueAdapter(string $queueName): ?string
    {
        if (isset($this->queueAdapterMap[$queueName])) {
            return $this->queueAdapterMap[$queueName];
        }

        $this->queueAdapterMap[$queueName] = $this->queueConfigReader->getQueueAdapter($queueName);

        return $this->queueAdapterMap[$queueName];
    }
}
