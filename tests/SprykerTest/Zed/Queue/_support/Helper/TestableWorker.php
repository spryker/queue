<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Helper;

use Spryker\Zed\Queue\Business\Worker\Worker;

/**
 * Test seam for {@link \Spryker\Zed\Queue\Business\Worker\Worker}.
 */
class TestableWorker extends Worker
{
    /**
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
     * @param string $command
     * @param float|int $elapsedSeconds
     * @param bool $shouldUpdateDisplay
     *
     * @return array<\Symfony\Component\Process\Process>
     */
    public function callExecuteOperation(string $command, int|float $elapsedSeconds = 0, bool $shouldUpdateDisplay = false): array
    {
        return $this->executeOperation($command, $elapsedSeconds, $shouldUpdateDisplay);
    }

    /**
     * @param string $command
     * @param float|int $elapsedSeconds
     * @param bool $shouldUpdateDisplay
     *
     * @return array<\Symfony\Component\Process\Process>|null
     */
    public function callExecuteOperationWithBulkCheck(string $command, int|float $elapsedSeconds = 0, bool $shouldUpdateDisplay = false): ?array
    {
        return $this->executeOperationWithBulkCheck($command, $elapsedSeconds, $shouldUpdateDisplay);
    }

    /**
     * @return array<string, mixed>
     */
    public function callStartProcesses(string $command, string $queue): array
    {
        return $this->startProcesses($command, $queue);
    }

    /**
     * @return array<string, mixed>
     */
    public function callStartProcessesForKnownNonEmptyQueue(string $command, string $queue): array
    {
        return $this->startProcessesForKnownNonEmptyQueue($command, $queue);
    }

    public function callBuildProcessCommand(string $command, string $queue): string
    {
        return $this->buildProcessCommand($command, $queue);
    }

    public function callGetQueueBatchSize(string $queueName): ?int
    {
        return $this->getQueueBatchSize($queueName);
    }

    /**
     * @param array<\Symfony\Component\Process\Process> $processes
     *
     * @return array<\Symfony\Component\Process\Process>
     */
    public function callGetPendingProcesses(array $processes): array
    {
        return $this->getPendingProcesses($processes);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return bool
     */
    public function callContinueExecution(int $totalPassedSeconds, int $maxThreshold, array $options): bool
    {
        return parent::continueExecution($totalPassedSeconds, $maxThreshold, $options);
    }

    /**
     * @param array<\Symfony\Component\Process\Process> $pendingProcesses
     * @param array<string, mixed> $options
     *
     * @return bool
     */
    public function callIsEmptyQueue(array $pendingProcesses, array $options): bool
    {
        return $this->isEmptyQueue($pendingProcesses, $options);
    }

    public function callAreQueuesEmpty(): bool
    {
        return $this->areQueuesEmpty();
    }

    /**
     * @param array<\Symfony\Component\Process\Process> $processes
     * @param array<string, mixed> $options
     *
     * @return void
     */
    public function callWaitForPendingProcesses(
        array $processes,
        string $command,
        int $round,
        int $delayIntervalSeconds,
        array $options = []
    ): void {
        $this->waitForPendingProcesses($processes, $command, $round, $delayIntervalSeconds, $options);
    }

    /**
     * @return array<string, mixed>
     */
    public function callGetQueueConfiguration(string $queueName): array
    {
        return $this->getQueueConfiguration($queueName);
    }

    /**
     * @param int $totalPassedSeconds
     * @param int $maxThreshold
     * @param array<string, mixed> $options
     *
     * @return bool
     */
    protected function continueExecution(int $totalPassedSeconds, int $maxThreshold, array $options): bool
    {
        if ($this->continueExecutionScript === null) {
            return parent::continueExecution($totalPassedSeconds, $maxThreshold, $options);
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
