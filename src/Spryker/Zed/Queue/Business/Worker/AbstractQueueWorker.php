<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Worker;

use Spryker\Shared\Queue\QueueConfig as SharedQueueConfig;
use Spryker\Zed\Queue\Business\Process\ProcessManagerInterface;
use Spryker\Zed\Queue\QueueConfig;

abstract class AbstractQueueWorker implements WorkerInterface
{
    public const int SECOND_TO_MILLISECONDS = 1000;

    protected function registerKillSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        $handler = function (): void {
            $this->getProcessManager()->flushAllWorkerProcesses();

            exit(0);
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGHUP, $handler);
    }

    /**
     * @param int $delayIntervalMilliseconds
     * @param array<\Symfony\Component\Process\Process> $processes
     *
     * @return void
     */
    public function executeUsleep(int $delayIntervalMilliseconds, array $processes): void
    {
        if (count($processes) > 0) {
            $delayIntervalMilliseconds = $this->getQueueConfig()->getDelayWhenQueueIsNotEmptyMilliseconds();
        }

        usleep($delayIntervalMilliseconds * static::SECOND_TO_MILLISECONDS);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return bool
     */
    protected function isWorkerStopsWhenEmptyQueueEnabled(array $options): bool
    {
        return isset($options[SharedQueueConfig::CONFIG_WORKER_STOP_WHEN_EMPTY]) && $options[SharedQueueConfig::CONFIG_WORKER_STOP_WHEN_EMPTY];
    }

    abstract protected function getProcessManager(): ProcessManagerInterface;

    abstract protected function getQueueConfig(): QueueConfig;
}
