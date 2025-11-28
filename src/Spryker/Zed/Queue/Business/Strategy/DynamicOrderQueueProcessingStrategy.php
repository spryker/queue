<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Strategy;

use ArrayIterator;
use ArrayObject;
use Generated\Shared\Transfer\QueueDynamicSettingsTransfer;
use Iterator;
use Spryker\Shared\Queue\Enum\QueueReadModeEnum;
use Spryker\Zed\Queue\Business\Logger\WorkerLoggerInterface;
use Spryker\Zed\Queue\Business\Queue\QueueMetrics;
use Spryker\Zed\Queue\Business\Scanner\QueueScannerInterface;
use Spryker\Zed\Queue\QueueConfig;

/**
 * The Idea
 * - combine OrderedQueuesStrategy + BiggestFirstStrategy
 * - spawn child processes if a queue has min batch_size amount of messages or there are no more such queues
 * - dynamically switch the modes based on RabbitMQ config
 *
 * Queue groups by any differentiators
 *  1. publish
 *  2. sync
 *  3. big -> 10/100 batches
 *  4. small -> less than that
 *  5. less than 1 batch of messages - lowest prio by default
 *   6. more than 1 batch of messages - use other prio rules
 *
 * Calculate prios dynamically based on scores, assign scores based on the active mode
 */
class DynamicOrderQueueProcessingStrategy implements QueueProcessingStrategyInterface
{
    /**
     * @var int
     */
    protected const int QUEUE_METRICS_LOG_COUNT = 5;

    /**
     * @var \Iterator|\ArrayIterator
     */
    protected Iterator $currentIterator;

    /**
     * @var int
     */
    protected int $mode = QueueReadModeEnum::MODE_READ_ORDER->value;

    /**
     * @param \Spryker\Zed\Queue\Business\Scanner\QueueScannerInterface $queueScanner
     * @param \Spryker\Zed\Queue\Business\Logger\WorkerLoggerInterface $consoleLogger
     * @param \Spryker\Zed\Queue\QueueConfig $queueConfig
     * @param array<\Spryker\Zed\QueueExtension\Dependency\Plugin\DynamicSettingsUpdaterPluginInterface> $dynamicSettingsUpdaterPlugins
     */
    public function __construct(
        protected QueueScannerInterface $queueScanner,
        protected WorkerLoggerInterface $consoleLogger,
        protected QueueConfig $queueConfig,
        protected array $dynamicSettingsUpdaterPlugins
    ) {
        $this->currentIterator = new ConditionBasedIterator(new ArrayIterator());
    }

    /**
     * @return \Spryker\Zed\Queue\Business\Queue\QueueMetrics|null
     */
    public function getNextQueue(): ?QueueMetrics
    {
        static $queueDynamicSettingsTransfer = null;

        if ($queueDynamicSettingsTransfer === null) {
            $queueDynamicSettingsTransfer = (new QueueDynamicSettingsTransfer())
                ->setMode($this->queueConfig->getQueueProcessingWorkerDynamicMode())
                ->setBigQueueBatches($this->queueConfig->getQueueProcessingBigQueueThresholdBatchesAmount())
                ->setLimitPerQueue($this->queueConfig->getProcessingLimitOfProcessesPerQueue());
        }

        if (!$this->currentIterator->valid()) {
            // Plugins can update settings dynamically in runtime using plugins
            $queueDynamicSettingsTransfer = $this->updateDynamicSettings($queueDynamicSettingsTransfer);
            $queueMetrics = $this->getQueueMetricsWithMessages($queueDynamicSettingsTransfer);
            $limitOfProcessesPerQueue = $queueDynamicSettingsTransfer->getLimitPerQueue();
            $this->currentIterator = new ConditionBasedIterator(
                $queueMetrics->getIterator(),
                fn (?QueueMetrics $queueMetric, int $currentIndex) => $currentIndex < ($queueMetric !== null && $queueMetric->getMessageToChunkSizeRatio() ?: 0) &&
                    $currentIndex < $limitOfProcessesPerQueue,
            );
        }

        /** @var \Spryker\Zed\Queue\Business\Queue\QueueMetrics|null $queueMetric */
        $queueMetric = $this->currentIterator->current();
        $this->currentIterator->next();

        return $queueMetric;
    }

    /**
     * @param \Generated\Shared\Transfer\QueueDynamicSettingsTransfer $queueDynamicSettingsTransfer
     *
     * @return \ArrayObject<int, \Spryker\Zed\Queue\Business\Queue\QueueMetrics>
     */
    protected function getQueueMetricsWithMessages(QueueDynamicSettingsTransfer $queueDynamicSettingsTransfer): ArrayObject
    {
        $queueMetrics = $this->queueScanner->scanQueues();

        $this->assignQueuePriorities($queueMetrics, $queueDynamicSettingsTransfer);
        $this->sortQueuePriorities($queueMetrics);

        if (count($queueMetrics) <= 0) {
            return $queueMetrics;
        }

        $this->logActiveParameters($queueDynamicSettingsTransfer->getMode());

        $this->consoleLogger->info(sprintf('WORKER calculated priorities - top %d', static::QUEUE_METRICS_LOG_COUNT));

        $this->consoleLogger->info(sprintf(
            '  - %s | %s | %s | %s | %s',
            str_pad('queue name', 50, ' '),
            str_pad('Batch', 8, ' ', STR_PAD_BOTH),
            str_pad('Msgs', 8, ' ', STR_PAD_BOTH),
            str_pad('# Batches', 8, ' ', STR_PAD_BOTH),
            str_pad('Prio', 8, ' ', STR_PAD_BOTH),
        ));

        $queueMetricsForLogging = array_slice($queueMetrics->getArrayCopy(), 0, static::QUEUE_METRICS_LOG_COUNT);
        foreach ($queueMetricsForLogging as $queueMetricLogging) {
            $this->consoleLogger->info(sprintf(
                '  > %s | %s | %s | %s | %s',
                str_pad($queueMetricLogging->getStoreName() . ' / ' . $queueMetricLogging->getQueueName(), 50, ' '),
                str_pad((string)$queueMetricLogging->getBatchSize(), 8, ' ', STR_PAD_BOTH),
                str_pad((string)$queueMetricLogging->getMessageCount(), 8, ' ', STR_PAD_BOTH),
                str_pad((string)$queueMetricLogging->getMessageToChunkSizeRatio(), 8, ' ', STR_PAD_BOTH),
                str_pad((string)$queueMetricLogging->getPriority(), 8, ' ', STR_PAD_BOTH),
            ));
        }

        return $queueMetrics;
    }

    /**
     * @param \ArrayObject<int, \Spryker\Zed\Queue\Business\Queue\QueueMetrics> $queueMetrics
     * @param \Generated\Shared\Transfer\QueueDynamicSettingsTransfer $queueDynamicSettingsTransfer
     *
     * @return \ArrayObject<int, \Spryker\Zed\Queue\Business\Queue\QueueMetrics>
     */
    protected function assignQueuePriorities(ArrayObject $queueMetrics, QueueDynamicSettingsTransfer $queueDynamicSettingsTransfer): ArrayObject
    {
        foreach ($queueMetrics as $queueMetric) {
            $priority = $this->calculatePriority($queueMetric, $queueDynamicSettingsTransfer);

            $queueMetric->setPriority($priority);
        }

        return $queueMetrics;
    }

    /**
     * @param \ArrayObject<int, \Spryker\Zed\Queue\Business\Queue\QueueMetrics> $queueMetrics
     *
     * @return \ArrayObject<int, \Spryker\Zed\Queue\Business\Queue\QueueMetrics>
     */
    protected function sortQueuePriorities(ArrayObject $queueMetrics): ArrayObject
    {
        $queueMetrics->uasort(
            function (QueueMetrics $queueMetricsA, QueueMetrics $queueMetricsB) {
                return $queueMetricsB->getPriority() == $queueMetricsA->getPriority() ?
                    $queueMetricsB->getMessageCount() - $queueMetricsA->getMessageCount() :
                    $queueMetricsB->getPriority() - $queueMetricsA->getPriority();
            },
        );

        return $queueMetrics;
    }

    /**
     * @param \Spryker\Zed\Queue\Business\Queue\QueueMetrics $queueMetrics
     * @param \Generated\Shared\Transfer\QueueDynamicSettingsTransfer $queueDynamicSettingsTransfer
     *
     * @return int
     */
    protected function calculatePriority(QueueMetrics $queueMetrics, QueueDynamicSettingsTransfer $queueDynamicSettingsTransfer): int
    {
        $priority = 0;
        $queueName = $queueMetrics->getQueueName();
        $bigQueueThresholdBatchesAmount = $queueDynamicSettingsTransfer->getBigQueueBatches();

        $priority += ($this->isModeActive(QueueReadModeEnum::MODE_PREFER_PUB->value, $queueDynamicSettingsTransfer->getMode()) &&
            substr($queueName, 0, strlen('publish')) === 'publish') ? 1 : 0;
        $priority += ($this->isModeActive(QueueReadModeEnum::MODE_PREFER_SYNC->value, $queueDynamicSettingsTransfer->getMode()) &&
            substr($queueName, 0, strlen('sync')) === 'sync') ? 1 : 0;

        $priority += ($this->isModeActive(QueueReadModeEnum::MODE_PREFER_BIG->value, $queueDynamicSettingsTransfer->getMode()) &&
            $queueMetrics->getMessageToChunkSizeRatio() > $bigQueueThresholdBatchesAmount) ? 1 : 0;
        $priority += ($this->isModeActive(QueueReadModeEnum::MODE_PREFER_SMALL->value, $queueDynamicSettingsTransfer->getMode()) &&
            $queueMetrics->getMessageToChunkSizeRatio() < $bigQueueThresholdBatchesAmount) ? 1 : 0;

        $priority += $queueMetrics->getMessageCount() < $queueMetrics->getBatchSize() ? -100 : 0;

        return $priority;
    }

    /**
     * @param int $mode
     * @param int $dynamicSettingMode
     *
     * @return bool
     */
    protected function isModeActive(int $mode, int $dynamicSettingMode): bool
    {
        return ($dynamicSettingMode & $mode) === $mode;
    }

    /**
     * @param int $dynamicSettingMode
     *
     * @return void
     */
    protected function logActiveParameters(int $dynamicSettingMode): void
    {
        $availableModes = [
            QueueReadModeEnum::MODE_READ_ORDER->value => 'default-order',
            QueueReadModeEnum::MODE_PREFER_PUB->value => 'prefer pub',
            QueueReadModeEnum::MODE_PREFER_SYNC->value => 'prefer sync',
            QueueReadModeEnum::MODE_PREFER_BIG->value => 'prefer big',
            QueueReadModeEnum::MODE_PREFER_SMALL->value => 'prefer small',
            QueueReadModeEnum::MODE_PREFER_DEFAULT_STORE->value => 'default store',
            QueueReadModeEnum::MODE_PREFER_FAST->value => 'prefer fast',
            QueueReadModeEnum::MODE_PREFER_SLOW->value => 'prefer slow',
            QueueReadModeEnum::MODE_ONLY_PREFERRED->value => 'only-preferred',
        ];
        $activeModes = [];

        foreach ($availableModes as $mode => $modeName) {
            if ($this->isModeActive($mode, $dynamicSettingMode)) {
                $activeModes[] = $modeName;
            }
        }
        $this->consoleLogger->info(sprintf('WORKER ACTIVE MODES: %s', implode(', ', $activeModes)));
    }

    /**
     * @param \Generated\Shared\Transfer\QueueDynamicSettingsTransfer $queueDynamicSettingsTransfer
     *
     * @return \Generated\Shared\Transfer\QueueDynamicSettingsTransfer
     */
    protected function updateDynamicSettings(QueueDynamicSettingsTransfer $queueDynamicSettingsTransfer): QueueDynamicSettingsTransfer
    {
        foreach ($this->dynamicSettingsUpdaterPlugins as $dynamicSettingsUpdaterPlugin) {
            $dynamicSettingsUpdaterPlugin->update($queueDynamicSettingsTransfer);
        }

        return $queueDynamicSettingsTransfer;
    }
}
