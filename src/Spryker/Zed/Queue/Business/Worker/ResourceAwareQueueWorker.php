<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Worker;

use SplFixedArray;
use Spryker\Client\Queue\QueueClientInterface;
use Spryker\Zed\Queue\Business\Logger\WorkerLoggerInterface;
use Spryker\Zed\Queue\Business\Process\ProcessManagerInterface;
use Spryker\Zed\Queue\Business\Queue\QueueMetrics;
use Spryker\Zed\Queue\Business\SignalHandler\SignalDispatcherInterface;
use Spryker\Zed\Queue\Business\Strategy\QueueProcessingStrategyInterface;
use Spryker\Zed\Queue\Business\SystemResources\SystemResourcesManagerInterface;
use Spryker\Zed\Queue\QueueConfig;
use Throwable;

class ResourceAwareQueueWorker extends AbstractQueueWorker
{
    /**
     * @var \SplFixedArray<\Symfony\Component\Process\Process>
     */
    protected SplFixedArray $processes;

    /**
     * @var int
     */
    protected int $runningProcessesCount = 0;

    /**
     * @var \Spryker\Zed\Queue\Business\Worker\WorkerStats
     */
    protected WorkerStats $stats;

    /**
     * @param \Spryker\Zed\Queue\Business\Process\ProcessManagerInterface $processManager
     * @param \Spryker\Zed\Queue\QueueConfig $queueConfig
     * @param \Spryker\Client\Queue\QueueClientInterface $queueClient
     * @param array<string> $queueNames
     * @param \Spryker\Zed\Queue\Business\Strategy\QueueProcessingStrategyInterface $queueProcessingStrategy
     * @param \Spryker\Zed\Queue\Business\SignalHandler\SignalDispatcherInterface $signalDispatcher
     * @param \Spryker\Zed\Queue\Business\SystemResources\SystemResourcesManagerInterface $sysResManager
     * @param \Spryker\Zed\Queue\Business\Logger\WorkerLoggerInterface $workerLogger
     */
    public function __construct(
        protected ProcessManagerInterface $processManager,
        protected QueueConfig $queueConfig,
        protected QueueClientInterface $queueClient,
        protected array $queueNames,
        protected QueueProcessingStrategyInterface $queueProcessingStrategy,
        protected SignalDispatcherInterface $signalDispatcher,
        protected SystemResourcesManagerInterface $sysResManager,
        protected WorkerLoggerInterface $workerLogger
    ) {
        $this->signalDispatcher->dispatch($this->queueConfig->getSignalsForGracefulWorkerShutdown());

        $this->processes = new SplFixedArray($this->queueConfig->getQueueWorkerMaxProcesses());
        $this->stats = new WorkerStats();
    }

    /**
     * @param string $command
     * @param array<string, mixed> $options
     *
     * @return void
     */
    public function start(string $command, array $options = []): void
    {
        $maxThreshold = $this->queueConfig->getQueueWorkerMaxThreshold();
        $delayIntervalMilliseconds = $this->queueConfig->getQueueWorkerInterval();
        $delayForNotEmptyQueueIntervalMilliseconds = $this->queueConfig->getDelayWhenQueueIsNotEmptyMilliseconds();
        $shouldIgnoreZeroMemory = $this->queueConfig->shouldIgnoreNotDetectedFreeMemory();

        $this->registerKillSignalHandlers();
        $this->processManager->flushZombieProcesses();

        $startTime = microtime(true);

        while ($this->continueExecution($startTime, $maxThreshold, $options)) {
            $this->stats->addCycle();

            $previousRunningProcessesCount = $this->runningProcessesCount;
            $freeIndex = $this->rescanProcesses();

            if (!$this->sysResManager->enoughResources($shouldIgnoreZeroMemory)) {
                $this->workerLogger->logNotOftenThan('no-mem', 'NO MEMORY');
                $this->stats->addNoMemoryCycle()->addSkipCycle();

                continue;
            }

            if ($freeIndex === null) {
                $this->workerLogger->logNotOftenThan(
                    'no-proc',
                    sprintf('BUSY: no free slots available for a new process, waiting'),
                );

                $this->stats->addNoSlotCycle()->addSkipCycle();

                usleep($delayForNotEmptyQueueIntervalMilliseconds * static::SECOND_TO_MILLISECONDS);
            } else {
                $isQueueEmpty = $this->executeQueueProcessingStrategy($freeIndex, $command, $this->isWorkerStopsWhenEmptyQueueEnabled($options));
                $this->executeUsleep($delayIntervalMilliseconds, array_filter($this->processes->toArray()));

                if ($this->shouldStopWhenQueueEmpty($isQueueEmpty, $previousRunningProcessesCount, $options)) {
                    break;
                }
            }

            $this->workerLogger->logNotOftenThan(
                'time-mem',
                function () use ($startTime) {
                    return sprintf('TIME: %0.2f sec' . "\n", microtime(true) - $startTime) .
                        sprintf('FREE MEM = %d MB', $this->sysResManager->getFreeMemory($this->queueConfig->memoryReadProcessTimeout()));
                },
                'info',
            );

            if ($this->ownWorkerMemGrowthDetected()) {
                break;
            }
        }

        $this->waitProcessesToComplete();

        $message = implode(', ', [
            'DONE',
            var_export($this->stats->getStats(), true),
            sprintf('Success Rate = %d%%', $this->stats->getSuccessRate()),
            var_export($this->stats->getCycleEfficiency(), true),
        ]);

        $this->workerLogger->info($message);
    }

    /**
     * @param float $startTime
     * @param int $maxThreshold
     * @param array<string, mixed> $options
     *
     * @return bool
     */
    protected function continueExecution(float $startTime, int $maxThreshold, array $options): bool
    {
        return microtime(true) - $startTime < $maxThreshold || $this->isWorkerStopsWhenEmptyQueueEnabled($options);
    }

    /**
     * @param bool $isQueueEmpty
     * @param int $previousRunningProcessesCount
     * @param array<string, mixed> $options
     *
     * @return bool
     */
    protected function shouldStopWhenQueueEmpty(bool $isQueueEmpty, int $previousRunningProcessesCount, array $options): bool
    {
        if (!$this->isWorkerStopsWhenEmptyQueueEnabled($options)) {
            return false;
        }

        if (!$isQueueEmpty || $this->runningProcessesCount !== 0) {
            return false;
        }

        return $previousRunningProcessesCount === 0;
    }

    /**
     * Runs as many times as it can per X minutes.
     *
     * @param int $freeIndex
     * @param string $command
     * @param bool $ignoreEmptyScanCooldown
     *
     * @return bool Returns true when there are no more queues to process, false otherwise.
     */
    protected function executeQueueProcessingStrategy(int $freeIndex, string $command, bool $ignoreEmptyScanCooldown = false): bool
    {
        try {
            $queueMetrics = $this->queueProcessingStrategy->getNextQueue($ignoreEmptyScanCooldown);
        } catch (Throwable $exception) {
            $this->workerLogger->error('QUEUE READ ERROR: ' . $exception->getMessage());

            $this->workerLogger->debug('QUEUE READ ERROR: ' . $exception->getTraceAsString());

            $this->stats
                ->addErrorQuantity('RMQ-connection')
                ->addSkipCycle();

            return false;
        }
        if (!$queueMetrics) {
            $this->workerLogger->debug('EMPTY: no more queues to process');

            $this->stats->addEmptyCycle()->addSkipCycle();

            return true;
        }

        $this->workerLogger->info(sprintf(
            'RUN [%d +1] %s:%s',
            $this->runningProcessesCount,
            $queueMetrics->getStoreName() ?? $queueMetrics->getRegionName(),
            $queueMetrics->getQueueName(),
        ));

        $processCommand = $this->getProcessCommand($queueMetrics, $command);

        $process = $this->processManager->triggerQueueProcess(
            $processCommand,
            $queueMetrics->getQueueName(),
        );

        $this->processes[$freeIndex] = $process;
        $this->runningProcessesCount++;

        $this->stats->addProcQuantity('new');
        $this->stats->addQueueQuantity($queueMetrics->getQueueName());
        $this->stats->addLocationQuantity($queueMetrics->getStoreName() ?? $queueMetrics->getRegionName());
        $this->stats->addQueueQuantity(sprintf('%s:%s', $queueMetrics->getStoreName() ?? $queueMetrics->getRegionName(), $queueMetrics->getQueueName()));

        return false;
    }

    protected function getProcessManager(): ProcessManagerInterface
    {
        return $this->processManager;
    }

    protected function getQueueConfig(): QueueConfig
    {
        return $this->queueConfig;
    }

    /**
     * Waits for each task/process to complete normally, finishing as soon as none are left.
     * When the wait limit is enabled, waiting is capped at the configured maximum: any process still
     * running after that will be killed by the OS once the Worker terminates, so we don't wait for a
     * malfunctioning child process indefinitely. When the wait limit is disabled, the Worker waits
     * until all processes complete on their own.
     *
     * @return void
     */
    protected function waitProcessesToComplete(): void
    {
        if ($this->runningProcessesCount === 0) {
            return;
        }

        $checkProcessesCompleteInterval = $this->queueConfig->getQueueWorkerCheckProcessesCompleteInterval();
        $isWaitLimitEnabled = $this->queueConfig->isQueueWorkerWaitLimitEnabled();
        $maxWaitSeconds = $this->queueConfig->getQueueWorkerMaxWaitingSeconds();

        $processesCompleteStartTime = microtime(true);

        while ($this->runningProcessesCount > 0) {
            if ($isWaitLimitEnabled && microtime(true) - $processesCompleteStartTime >= $maxWaitSeconds) {
                break;
            }

            usleep($checkProcessesCompleteInterval * static::SECOND_TO_MILLISECONDS);

            $this->workerLogger->debug(sprintf('Waiting to complete %d processes.', $this->runningProcessesCount));

            $this->rescanProcesses();
        }
    }

    /**
     * Removes finished processes from the processes array and updates stats accordingly
     *
     * @return int|null returns free index if available or null
     */
    protected function rescanProcesses(): ?int
    {
        $runningProcCount = 0;
        $freeIndex = null;

        foreach ($this->processes as $idx => $process) {
            if (!$process) {
                $freeIndex = $freeIndex ?? $idx;

                continue;
            }

            if ($process->isRunning()) {
                $runningProcCount++;

                continue;
            }

            unset($this->processes[$idx]);

            $freeIndex = $freeIndex ?? $idx;

            $this->workerLogger->debug(sprintf('DONE %s', $process->getExitCodeText()));

            if ($process->getExitCode() !== 0) {
                $this->stats->addProcQuantity('failed');

                $messages = implode(', ', [
                    sprintf('> --- FREE: %d MB', $this->sysResManager->getFreeMemory($this->queueConfig->memoryReadProcessTimeout())),
                    $process->getCommandLine(),
                    'Std output:' . $process->getOutput(),
                    'Error output: ' . $process->getErrorOutput(),
                    '< ---',
                ]);
                $this->workerLogger->error($messages);
            }

            $this->stats->addErrorQuantity($process->getExitCodeText());
        }

        if ($this->runningProcessesCount !== $runningProcCount) {
            $this->workerLogger->debug(sprintf('RUNNING PROC = %d', $runningProcCount));
        }

        $this->stats->addProcQuantity('max', (int)max($this->runningProcessesCount, $runningProcCount));

        $this->runningProcessesCount = $runningProcCount;

        return $freeIndex;
    }

    protected function ownWorkerMemGrowthDetected(): bool
    {
        $ownMemGrowthFactor = $this->sysResManager->getOwnPeakMemoryGrowth();
        $this->stats->addMetric('mem-growth', $ownMemGrowthFactor);

        if ($ownMemGrowthFactor > 0) {
            $this->workerLogger->logNotOftenThan(
                'own-mem',
                sprintf('OWN MEM: GROWTH FACTOR = %d%%', $ownMemGrowthFactor),
                'info',
            );
        }

        if ($ownMemGrowthFactor > $this->queueConfig->maxAllowedWorkerMemoryGrowthFactor()) {
            $this->workerLogger->error(
                sprintf('Worker memory grew more than %d%%, probably a memory leak, exiting', $ownMemGrowthFactor),
            );

            return true;
        }

        return false;
    }

    protected function getProcessCommand(QueueMetrics $queueMetrics, string $command): string
    {
        if (!$queueMetrics->getStoreName()) {
            return sprintf(
                $this->queueConfig->getQueueWorkerCommandPattern(),
                $command,
                $queueMetrics->getQueueName(),
            );
        }

        return sprintf(
            $this->queueConfig->getStoreQueueWorkerCommandPattern(),
            $queueMetrics->getStoreName(),
            $command,
            $queueMetrics->getQueueName(),
        );
    }
}
