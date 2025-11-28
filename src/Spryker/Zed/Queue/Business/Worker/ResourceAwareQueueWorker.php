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

class ResourceAwareQueueWorker implements WorkerInterface
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
        $shouldIgnoreZeroMemory = $this->queueConfig->shouldIgnoreNotDetectedFreeMemory();

        $startTime = microtime(true);
        $lastStart = 0;

        while (microtime(true) - $startTime < $maxThreshold) {
            $this->stats->addCycle();

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

                $this->stats
                    ->addNoSlotCycle()
                    ->addSkipCycle();
            } elseif ((microtime(true) - $lastStart) * 1000 > $delayIntervalMilliseconds) {
                $lastStart = microtime(true);
                $this->executeQueueProcessingStrategy($freeIndex, $command);
            } else {
                $this->stats
                    ->addCooldownCycle()
                    ->addSkipCycle();
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

        $this->workerLogger->info('DONE');
        $this->workerLogger->info(var_export($this->stats->getStats(), true));
        $this->workerLogger->info(sprintf('Success Rate = %d%%', $this->stats->getSuccessRate()));
        $this->workerLogger->info(var_export($this->stats->getCycleEfficiency(), true));
    }

    /**
     * Runs as many times as it can per X minutes.
     *
     * @param int $freeIndex
     * @param string $command
     *
     * @return void
     */
    protected function executeQueueProcessingStrategy(int $freeIndex, string $command): void
    {
        try {
            $queueMetrics = $this->queueProcessingStrategy->getNextQueue();
        } catch (Throwable $exception) {
            $this->workerLogger->error('QUEUE READ ERROR: ' . $exception->getMessage());

            $this->workerLogger->debug('QUEUE READ ERROR: ' . $exception->getTraceAsString());

            $this->stats
                ->addErrorQuantity('RMQ-connection')
                ->addSkipCycle();

            return;
        }
        if (!$queueMetrics) {
            $this->workerLogger->debug('EMPTY: no more queues to process');

            $this->stats->addEmptyCycle()->addSkipCycle();

            return;
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
    }

    /**
     * Waits for a normal complete of each task/process for a limited amount of time.
     * In case process didn't finish after that period of time - Worker will terminate together with a process,
     * a process will be killed by the OS.
     * We don't want to wait for a malfunctioning child process indefinitely
     *
     * @return void
     */
    protected function waitProcessesToComplete(): void
    {
        $waitingProcessesCompleteTime = $this->queueConfig->getWaitingProcessesCompleteTimeout();
        $checkProcessesCompleteInterval = $this->queueConfig->getQueueWorkerCheckProcessesCompleteInterval();

        $processesCompleteStartTime = microtime(true);
        $lastCheck = 0;

        while (microtime(true) - $processesCompleteStartTime < $waitingProcessesCompleteTime) {
            if ($this->runningProcessesCount === 0) {
                break;
            }

            if ((microtime(true) - $lastCheck) * 1000 <= $checkProcessesCompleteInterval) {
                continue;
            }

            $lastCheck = microtime(true);
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

                $this->workerLogger->error(
                    sprintf('> --- FREE: %d MB', $this->sysResManager->getFreeMemory($this->queueConfig->memoryReadProcessTimeout())),
                );
                $this->workerLogger->error($process->getCommandLine());
                $this->workerLogger->error('Std output:' . $process->getOutput());
                $this->workerLogger->error('Error output: ' . $process->getErrorOutput());
                $this->workerLogger->error('< ---');
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

    /**
     * @return bool
     */
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

    /**
     * @param \Spryker\Zed\Queue\Business\Queue\QueueMetrics $queueMetrics
     * @param string $command
     *
     * @return string
     */
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
