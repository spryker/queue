<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Helper;

use Spryker\Zed\Queue\Business\Process\ProcessManagerInterface;
use Spryker\Zed\Queue\Business\Worker\AbstractQueueWorker;
use Spryker\Zed\Queue\QueueConfig;

/**
 * Minimal concrete implementation of {@link \Spryker\Zed\Queue\Business\Worker\AbstractQueueWorker}, so the
 * shared behaviour on the abstract base can be tested without dragging in either of the two real workers.
 */
class TestableAbstractQueueWorker extends AbstractQueueWorker
{
    public function __construct(
        protected ProcessManagerInterface $processManager,
        protected QueueConfig $queueConfig
    ) {
    }

    /**
     * @param string $command
     * @param array<string> $options
     *
     * @return void
     */
    public function start(string $command, array $options = []): void
    {
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return bool
     */
    public function callIsWorkerStopsWhenEmptyQueueEnabled(array $options): bool
    {
        return $this->isWorkerStopsWhenEmptyQueueEnabled($options);
    }

    protected function getProcessManager(): ProcessManagerInterface
    {
        return $this->processManager;
    }

    protected function getQueueConfig(): QueueConfig
    {
        return $this->queueConfig;
    }
}
