<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Communication\Console;

use Spryker\Shared\Queue\QueueConfig;
use Spryker\Zed\Kernel\Communication\Console\Console;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @method \Spryker\Zed\Queue\Business\QueueFacadeInterface getFacade()
 * @method \Spryker\Zed\Queue\Persistence\QueueQueryContainerInterface getQueryContainer()
 * @method \Spryker\Zed\Queue\Communication\QueueCommunicationFactory getFactory()
 */
class QueueWorkerConsole extends Console
{
    /**
     * @var string
     */
    public const COMMAND_NAME = 'queue:worker:start';

    /**
     * @var string
     */
    public const DESCRIPTION = 'Start queue workers';

    /**
     * @var string
     */
    public const OPTION_STOP_WHEN_EMPTY = 'stop-when-empty';

    /**
     * @var string
     */
    public const OPTION_STOP_WHEN_EMPTY_SHORT = 's';

    public const QUEUE_RUNNER_COMMAND = APPLICATION_VENDOR_DIR . '/bin/console queue:task:start';

    public const VERBOSITY_DEBUG_MODE = '-vvv';

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName(static::COMMAND_NAME);
        $this->setDescription(static::DESCRIPTION);

        $this->addOption(static::OPTION_STOP_WHEN_EMPTY, static::OPTION_STOP_WHEN_EMPTY_SHORT, InputOption::VALUE_NONE, 'Stops worker execution only when the queues are empty.');

        parent::configure();
    }

    /**
     * @param \Symfony\Component\Console\Input\InputInterface $input
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $options = [
            QueueConfig::CONFIG_WORKER_STOP_WHEN_EMPTY => $input->getOption(static::OPTION_STOP_WHEN_EMPTY),
        ];

        $this->getFacade()->startWorker($this->getQueueRunnerCommand(), $output, $options);

        return static::CODE_SUCCESS;
    }

    /**
     * @return string
     */
    protected function getQueueRunnerCommand(): string
    {
        if ($this->output->getVerbosity() === OutputInterface::VERBOSITY_DEBUG) {
            return static::QUEUE_RUNNER_COMMAND . ' ' . static::VERBOSITY_DEBUG_MODE;
        }

        return static::QUEUE_RUNNER_COMMAND;
    }
}
