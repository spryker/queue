<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Helper;

use SplFixedArray;
use Spryker\Zed\Queue\Business\Queue\QueueMetrics;
use Spryker\Zed\Queue\Business\Worker\ResourceAwareQueueWorker;
use Spryker\Zed\Queue\Business\Worker\WorkerStats;
use Symfony\Component\Process\Process;

/**
 * Test seam for {@link \Spryker\Zed\Queue\Business\Worker\ResourceAwareQueueWorker}.
 */
class TestableResourceAwareQueueWorker extends ResourceAwareQueueWorker
{
    /**
     * Scripted return values for continueExecution().
     *
     * @var array<bool>|null
     */
    protected ?array $continueExecutionScript = null;

    /**
     * @var int
     */
    protected int $executeUsleepCallCount = 0;

    /**
     * @var int
     */
    protected int $registerKillSignalHandlersCallCount = 0;

    /**
     * @param array<bool> $script
     *
     * @return $this
     */
    public function setContinueExecutionScript(array $script)
    {
        $this->continueExecutionScript = $script;

        return $this;
    }

    /**
     * Runs the loop body exactly $iterations times.
     *
     * @param int $iterations
     *
     * @return void
     */
    public function setLoopIterations(int $iterations): void
    {
        $this->setContinueExecutionScript(array_fill(0, $iterations, true));
    }

    public function getExecuteUsleepCallCount(): int
    {
        return $this->executeUsleepCallCount;
    }

    public function getRegisterKillSignalHandlersCallCount(): int
    {
        return $this->registerKillSignalHandlersCallCount;
    }

    /**
     * @return \SplFixedArray<\Symfony\Component\Process\Process>
     */
    public function getProcesses(): SplFixedArray
    {
        return $this->processes;
    }

    /**
     * @return $this
     */
    public function setProcessAt(int $index, ?Process $process)
    {
        $this->processes[$index] = $process;

        return $this;
    }

    public function getRunningProcessesCount(): int
    {
        return $this->runningProcessesCount;
    }

    /**
     * @return $this
     */
    public function setRunningProcessesCount(int $count)
    {
        $this->runningProcessesCount = $count;

        return $this;
    }

    public function getStats(): WorkerStats
    {
        return $this->stats;
    }

    /**
     * @return array<string, mixed>
     */
    public function getCycleStats(): array
    {
        return $this->stats->getStats()['cycles'];
    }

    /**
     * @return array<string, mixed>
     */
    public function getProcStats(): array
    {
        return $this->stats->getStats()['proc'];
    }

    /**
     * @return array<string, mixed>
     */
    public function getErrorStats(): array
    {
        return $this->stats->getStats()['errors'];
    }

    public function callRescanProcesses(): ?int
    {
        return $this->rescanProcesses();
    }

    public function callExecuteQueueProcessingStrategy(
        int $freeIndex,
        string $command,
        bool $ignoreEmptyScanCooldown = false
    ): bool {
        return $this->executeQueueProcessingStrategy($freeIndex, $command, $ignoreEmptyScanCooldown);
    }

    public function callGetProcessCommand(QueueMetrics $queuemetrics, string $command): string
    {
        return $this->getProcessCommand($queuemetrics, $command);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return bool
     */
    public function callShouldStopWhenQueueEmpty(bool $isQueueEmpty, int $previousRunningProcessesCount, array $options): bool
    {
        return $this->shouldStopWhenQueueEmpty($isQueueEmpty, $previousRunningProcessesCount, $options);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return bool
     */
    public function callContinueExecution(float $startTime, int $maxThreshold, array $options): bool
    {
        return parent::continueExecution($startTime, $maxThreshold, $options);
    }

    public function callWaitProcessesToComplete(): void
    {
        $this->waitProcessesToComplete();
    }

    public function callOwnWorkerMemGrowthDetected(): bool
    {
        return $this->ownWorkerMemGrowthDetected();
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return bool
     */
    protected function continueExecution(float $startTime, int $maxThreshold, array $options): bool
    {
        if ($this->continueExecutionScript === null) {
            return parent::continueExecution($startTime, $maxThreshold, $options);
        }

        return (bool)array_shift($this->continueExecutionScript);
    }

    /**
     * @param int $delayIntervalMilliseconds
     * @param array<\Symfony\Component\Process\Process> $processes
     *
     * @return void
     */
    public function executeUsleep(int $delayIntervalMilliseconds, array $processes): void
    {
        $this->executeUsleepCallCount++;
    }

    protected function registerKillSignalHandlers(): void
    {
        $this->registerKillSignalHandlersCallCount++;
    }
}
