<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Helper;

use Generated\Shared\Transfer\QueueProcessTransfer;
use Psr\Log\LoggerInterface;
use Spryker\Shared\Log\Config\LoggerConfigInterface;
use Spryker\Zed\Queue\Business\Process\ProcessManager;
use Symfony\Component\Process\Process;

/**
 * Test seam for {@link \Spryker\Zed\Queue\Business\Process\ProcessManager}.
 */
class TestableProcessManager extends ProcessManager
{
    /**
     * @var array<\Symfony\Component\Process\Process>
     */
    protected array $createdProcesses = [];

    /**
     * Process returned by the next createProcess() call.
     *
     * @var \Symfony\Component\Process\Process|null
     */
    protected ?Process $processToCreate = null;

    /**
     * Pids the fake `ps` lookup reports as alive.
     *
     * @var array<int>
     */
    protected array $runningPids = [];

    /**
     * @var array<int>
     */
    protected array $zombiePids = [];

    /**
     * @var array<\Generated\Shared\Transfer\QueueProcessTransfer>
     */
    protected array $savedProcesses = [];

    /**
     * @var array<string>
     */
    protected array $createdCommands = [];

    /**
     * Logger the production code reaches through LoggerTrait, which offers no setter of its own.
     *
     * @var \Psr\Log\LoggerInterface|null
     */
    protected ?LoggerInterface $testLogger = null;

    /**
     * @param \Psr\Log\LoggerInterface $logger
     *
     * @return $this
     */
    public function setLogger(LoggerInterface $logger)
    {
        $this->testLogger = $logger;

        return $this;
    }

    protected function getLogger(?LoggerConfigInterface $loggerConfig = null): LoggerInterface
    {
        return $this->testLogger ?? parent::getLogger($loggerConfig);
    }

    /**
     * @param \Symfony\Component\Process\Process $process
     *
     * @return $this
     */
    public function setProcessToCreate(Process $process)
    {
        $this->processToCreate = $process;

        return $this;
    }

    /**
     * Whether the fake `ps` can be run at all.
     *
     * @var bool
     */
    protected bool $isPsAvailable = true;

    /**
     * @param bool $isPsAvailable
     *
     * @return $this
     */
    public function setPsAvailable(bool $isPsAvailable)
    {
        $this->isPsAvailable = $isPsAvailable;

        return $this;
    }

    /**
     * @param array<int> $runningPids
     *
     * @return $this
     */
    public function setRunningPids(array $runningPids)
    {
        $this->runningPids = $runningPids;

        return $this;
    }

    /**
     * @param array<int> $zombiePids
     *
     * @return $this
     */
    public function setZombiePids(array $zombiePids)
    {
        $this->zombiePids = $zombiePids;

        return $this;
    }

    /**
     * @return array<\Generated\Shared\Transfer\QueueProcessTransfer>
     */
    public function getSavedProcesses(): array
    {
        return $this->savedProcesses;
    }

    /**
     * @return array<string>
     */
    public function getCreatedCommands(): array
    {
        return $this->createdCommands;
    }

    /**
     * @return array<string>
     */
    public function getErrorBuffer(): array
    {
        return $this->errorBuffer;
    }

    public function callForwardOutputWithoutBootstrapInfo(string $buffer): void
    {
        $this->forwardOutputWithoutBootstrapInfo($buffer);
    }

    /**
     * @param array<int> $processIds
     *
     * @return int
     */
    public function callReleaseIdleProcesses(array $processIds): int
    {
        return $this->releaseIdleProcesses($processIds);
    }

    /**
     * @param string $command
     *
     * @return \Symfony\Component\Process\Process
     */
    protected function createProcess($command)
    {
        $this->createdCommands[] = $command;
        $process = $this->processToCreate ?? new FakeProcess();
        $this->createdProcesses[] = $process;

        return $process;
    }

    /**
     * Replaces the `ps` shell-out only.
     *
     * @param int $processId
     *
     * @return array<string>|null
     */
    protected function queryProcessState(int $processId): ?array
    {
        if (!$this->isPsAvailable) {
            return null;
        }

        if (!in_array($processId, $this->runningPids, true)) {
            return [];
        }

        return [sprintf('%d ttys000    0:00.01 php', $processId)];
    }

    /**
     * @return array<int>
     */
    protected function findZombiePhpProcessPids(): array
    {
        return $this->zombiePids;
    }

    /**
     * Records the row the production code would have written to `spy_queue_process`.
     *
     * @param \Generated\Shared\Transfer\QueueProcessTransfer $queueProcessTransfer
     *
     * @return \Generated\Shared\Transfer\QueueProcessTransfer
     */
    protected function saveProcess(QueueProcessTransfer $queueProcessTransfer)
    {
        if ($this->queueConfig->isResourceAwareQueueWorkerEnabled()) {
            return $queueProcessTransfer;
        }

        $this->savedProcesses[] = $queueProcessTransfer;

        return $queueProcessTransfer;
    }
}
