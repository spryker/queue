<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Process;

use Generated\Shared\Transfer\QueueProcessTransfer;
use Monolog\Logger;
use Orm\Zed\Queue\Persistence\SpyQueueProcess;
use Propel\Runtime\Formatter\SimpleArrayFormatter;
use Spryker\Shared\Log\LoggerTrait;
use Spryker\Zed\Queue\Persistence\QueueQueryContainerInterface;
use Symfony\Component\Process\Process;

class ProcessManager implements ProcessManagerInterface
{
    use LoggerTrait;

    /**
     * @var \Spryker\Zed\Queue\Persistence\QueueQueryContainerInterface
     */
    protected $queryContainer;

    /**
     * @var string
     */
    protected $serverUniqueId;

    /**
     * @var array<string, int>
     */
    protected static array $logCache = [];

    /**
     * @param \Spryker\Zed\Queue\Persistence\QueueQueryContainerInterface $queryContainer
     * @param string $serverUniqueId
     */
    public function __construct(QueueQueryContainerInterface $queryContainer, $serverUniqueId)
    {
        $this->queryContainer = $queryContainer;
        $this->serverUniqueId = $serverUniqueId;
    }

    /**
     * @param string $command
     * @param string $queue
     *
     * @return \Symfony\Component\Process\Process
     */
    public function triggerQueueProcess($command, $queue)
    {
        $process = $this->createProcess($command);
        $process->start();

        if ($process->isRunning()) {
            $queueProcessTransfer = $this->createQueueProcessTransfer($queue, $process->getPid());
            $this->saveProcess($queueProcessTransfer);
        } else {
            $this->logNotOftenThen(Logger::ERROR, 'Queue process failed to start or exited immediately', [
                'queue' => $queue,
                'command' => $command,
                'exit_code' => $process->getExitCode(),
                'error_output' => $process->getErrorOutput(),
                'output' => $process->getOutput(),
                'server_id' => $this->serverUniqueId,
            ], 30);
        }

        return $process;
    }

    /**
     * @param string $queueName
     *
     * @return int
     */
    public function getBusyProcessNumber($queueName)
    {
        /** @var array<int> $processIds */
        $processIds = $this->queryContainer
            ->queryProcessesByServerIdAndQueueName($this->serverUniqueId, $queueName)
            ->find();

        $busyProcessIndex = $this->releaseIdleProcesses($processIds);

        return $busyProcessIndex;
    }

    /**
     * @return void
     */
    public function flushIdleProcesses()
    {
        /** @var array<int> $processIds */
        $processIds = $this->queryContainer
            ->queryProcessesByServerId($this->serverUniqueId)
            ->find();

        if ($processIds) {
            $this->releaseIdleProcesses($processIds);
        }
    }

    /**
     * @return void
     */
    public function flushAllWorkerProcesses(): void
    {
        /** @var array<int> $processIds */
        $processIds = $this->queryContainer
            ->queryProcessesByServerId($this->serverUniqueId)
            ->setFormatter(SimpleArrayFormatter::class)
            ->find()
            ->toArray();

        if ($processIds) {
            $this->deleteProcesses($processIds);
        }
    }

    /**
     * @param array $processIds
     *
     * @return int
     */
    protected function releaseIdleProcesses($processIds)
    {
        $cleanupProcesses = [];
        $busyProcessIndex = 0;

        foreach ($processIds as $processId) {
            if ($this->isProcessRunning($processId)) {
                $busyProcessIndex++;
            } else {
                $cleanupProcesses[] = $processId;
            }
        }

        if ($cleanupProcesses !== []) {
            $this->deleteProcesses($cleanupProcesses);
        }

        return $busyProcessIndex;
    }

    /**
     * @param int|null $processId
     *
     * @return bool
     */
    public function isProcessRunning($processId)
    {
        if ($processId === null) {
            return false;
        }

        $output = (string)exec(sprintf('ps -p %s | grep %s | grep -v \'<defunct>\'', $processId, $processId));

        return trim($output) !== '';
    }

    /**
     * @param string $queue
     * @param int $processId
     *
     * @return \Generated\Shared\Transfer\QueueProcessTransfer
     */
    protected function createQueueProcessTransfer($queue, $processId)
    {
        $queueProcessTransfer = new QueueProcessTransfer();
        $queueProcessTransfer->setServerId($this->serverUniqueId);
        $queueProcessTransfer->setQueueName($queue);
        $queueProcessTransfer->setProcessPid($processId);
        $queueProcessTransfer->setWorkerPid(posix_getpgrp());

        return $queueProcessTransfer;
    }

    /**
     * @param \Generated\Shared\Transfer\QueueProcessTransfer $queueProcessTransfer
     *
     * @return \Generated\Shared\Transfer\QueueProcessTransfer
     */
    protected function saveProcess(QueueProcessTransfer $queueProcessTransfer)
    {
        $processEntity = new SpyQueueProcess();
        $processEntity->fromArray($queueProcessTransfer->toArray());
        $processEntity->save();

        return $this->convertToQueueProcessTransfer($processEntity);
    }

    /**
     * @param \Orm\Zed\Queue\Persistence\SpyQueueProcess $processEntity
     *
     * @return \Generated\Shared\Transfer\QueueProcessTransfer
     */
    protected function convertToQueueProcessTransfer(SpyQueueProcess $processEntity)
    {
        $queueProcessTransfer = new QueueProcessTransfer();
        $queueProcessTransfer->fromArray($processEntity->toArray(), true);

        return $queueProcessTransfer;
    }

    /**
     * @param array $processIds
     *
     * @return int
     */
    protected function deleteProcesses(array $processIds)
    {
        return $this->queryContainer
            ->queryProcessesByProcessIds($processIds)
            ->delete();
    }

    /**
     * @param string $command
     *
     * @return \Symfony\Component\Process\Process
     */
    protected function createProcess($command)
    {
        // Shim for Symfony 3.x, to be removed when Symfony dependency becomes 4.2+
        if (!method_exists(Process::class, 'fromShellCommandline')) {
            //@phpstan-ignore-next-line
            return new Process($command);
        }

        return Process::fromShellCommandline($command, APPLICATION_ROOT_DIR);
    }

    /**
     * @param mixed $level
     * @param string $message
     * @param array<mixed> $context
     * @param int $seconds
     *
     * @return void
     */
    protected function logNotOftenThen(mixed $level, string $message, array $context = [], int $seconds = 30): void
    {
        $currentTime = time();

        if (
            isset(static::$logCache[$message]) &&
            ($currentTime - static::$logCache[$message]) < $seconds
        ) {
            return;
        }

        static::$logCache[$message] = $currentTime;
        $this->getLogger()->log($level, $message, $context);
    }
}
