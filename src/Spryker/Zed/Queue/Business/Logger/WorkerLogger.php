<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Logger;

use Symfony\Component\Console\Output\OutputInterface;

class WorkerLogger implements WorkerLoggerInterface
{
    protected const string ERROR_MESSAGE_TEMPLATE = '\033[31m%s\033[0m';

    /**
     * @var array<string, float>
     */
    protected array $timers = [];

    public function __construct(protected OutputInterface $output)
    {
    }

    public function logNotOftenThan(string $timerName, string|callable $message, string $level = 'debug', int $intervalSec = 1): void
    {
        if (microtime(true) - ($this->timers[$timerName] ?? 0) >= $intervalSec) {
            $this->timers[$timerName] = microtime(true);
            if (is_callable($message)) {
                $message = $message();
            }
            if ($level === 'debug' && $this->output->isDebug()) {
                $this->output->writeln($message);
            }
            if ($level === 'info' && $this->output->isVerbose()) {
                $this->output->writeln($message);
            }
        }
    }

    public function info(string $message): void
    {
        if (!$this->output->isVerbose()) {
            return;
        }

        $this->output->writeln($message);
    }

    public function error(string $message): void
    {
        $this->output->writeln(sprintf(
            static::ERROR_MESSAGE_TEMPLATE,
            $message,
        ));
    }

    public function debug(string $message): void
    {
        if (!$this->output->isDebug()) {
            return;
        }

        $this->output->writeln($message);
    }
}
