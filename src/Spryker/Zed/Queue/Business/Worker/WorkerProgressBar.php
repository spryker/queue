<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Worker;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

class WorkerProgressBar implements WorkerProgressBarInterface
{
    /**
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    protected $output;

    /**
     * @var \Symfony\Component\Console\Helper\ProgressBar|null
     */
    protected $progressBar;

    /**
     * @var \Spryker\Zed\Queue\Business\Worker\ProcessMemoryTrackerInterface
     */
    protected ProcessMemoryTrackerInterface $processMemoryTracker;

    /**
     * @var \Spryker\Zed\Queue\Business\Worker\QueueMessageFormatterInterface
     */
    protected QueueMessageFormatterInterface $queueMessageFormatter;

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     * @param \Spryker\Zed\Queue\Business\Worker\ProcessMemoryTrackerInterface $processMemoryTracker
     * @param \Spryker\Zed\Queue\Business\Worker\QueueMessageFormatterInterface $queueMessageFormatter
     */
    public function __construct(
        OutputInterface $output,
        ProcessMemoryTrackerInterface $processMemoryTracker,
        QueueMessageFormatterInterface $queueMessageFormatter
    ) {
        $this->output = $output;
        $this->processMemoryTracker = $processMemoryTracker;
        $this->queueMessageFormatter = $queueMessageFormatter;
    }

    /**
     * @param int $steps
     * @param int $round
     *
     * @return void
     */
    public function start($steps, $round)
    {
        if ($this->output->getVerbosity() === OutputInterface::VERBOSITY_NORMAL) {
            return;
        }

        $this->progressBar = $this->createProgressBar($steps);
        $this->progressBar->setFormatDefinition('queue', '%message% %current%/%max% sec [%bar%] %percent:3s%%');
        $this->progressBar->setFormat('queue');
        $this->progressBar->setMessage(sprintf('Main Queue Process <execution round #%d>:', $round));
    }

    /**
     * @param int $step
     *
     * @return void
     */
    public function advance($step = 1)
    {
        if ($this->progressBar) {
            $this->progressBar->advance($step);
        }
    }

    /**
     * @return void
     */
    public function clear(): void
    {
        if ($this->progressBar) {
            $this->progressBar->clear();
        }
    }

    /**
     * @return void
     */
    public function display(): void
    {
        if ($this->progressBar) {
            $this->progressBar->display();
        }
    }

    /**
     * @param int $progress
     *
     * @return void
     */
    public function setProgress(int $progress): void
    {
        if ($this->progressBar) {
            $this->progressBar->setProgress($progress);
        }
    }

    /**
     * @return void
     */
    public function finish(): void
    {
        if ($this->progressBar) {
            $this->progressBar->finish();
        }
    }

    /**
     * @param int $rowId
     * @param string $queueName
     * @param int $pid
     * @param int $busyProcessNumber
     * @param int $newProcessNumber
     * @param int|null $batchSize
     * @param float|null $elapsedTime
     *
     * @return void
     */
    public function writeConsoleMessage(
        $rowId,
        $queueName,
        $pid,
        $busyProcessNumber,
        $newProcessNumber,
        $batchSize = null,
        $elapsedTime = null
    ) {
        if (!$this->progressBar) {
            return;
        }

        $memoryInfo = $this->processMemoryTracker->getMemoryInfoForPid($pid);
        $message = $this->queueMessageFormatter->formatQueueStatusMessage(
            $rowId,
            $queueName,
            $busyProcessNumber,
            $newProcessNumber,
            $batchSize,
            $elapsedTime,
            $memoryInfo,
        );

        $this->output->writeln($message);
    }

    /**
     * @param array<string> $errors
     *
     * @return void
     */
    public function writeErrors(array $errors): void
    {
        foreach ($errors as $error) {
            $this->output->writeln($error);
        }
    }

    /**
     * @return void
     */
    public function reset()
    {
        $this->progressBar = null;
        $this->processMemoryTracker->reset();
    }

    /**
     * @param int $steps
     *
     * @return \Symfony\Component\Console\Helper\ProgressBar
     */
    protected function createProgressBar($steps)
    {
        return new ProgressBar($this->output, $steps);
    }
}
