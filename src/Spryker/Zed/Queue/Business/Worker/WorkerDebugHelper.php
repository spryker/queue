<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Worker;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

class WorkerDebugHelper implements WorkerDebugHelperInterface
{
    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     */
    public function __construct(private readonly OutputInterface $output)
    {
    }

    /**
     * @param string $queue
     *
     * @return void
     */
    public function writeQueueProcessStarted(string $queue): void
    {
        if ($this->output->getVerbosity() !== OutputInterface::VERBOSITY_DEBUG) {
            return;
        }

        $this->output->writeln(sprintf('Start processing queue "%s"', $queue));
        $this->output->writeln('');
    }

    /**
     * @param Process $process
     * 
     * @return void
     */
    public function logProcessTermination(Process $process): void
    {
        if ($this->output->getVerbosity() !== OutputInterface::VERBOSITY_DEBUG) {
            return;
        }

        $output = $process->getIncrementalOutput();
        if (empty($output)) {
            return;
        }

        $this->output->writeln($output);
    }
}
