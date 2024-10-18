<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Task;

use Exception;
use Symfony\Component\Console\Output\OutputInterface;

class TaskDebugHelper implements TaskDebugHelperInterface
{
    /**
     * @var \Symfony\Component\Console\Output\OutputInterface|null
     */
    protected $output;

    /**
     * @var float
     */
    protected $startTime;

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface|null $output
     */
    public function __construct(?OutputInterface $output = null)
    {
        $this->output = $output;
    }

    /**
     * @param array<\Generated\Shared\Transfer\QueueReceiveMessageTransfer> $messages
     *
     * @return void
     */
    public function startMessages(array $messages): void
    {
        if (!$this->output) {
            return;
        }

        if ($this->output->getVerbosity() < OutputInterface::VERBOSITY_DEBUG) {
            return;
        }

        $this->startTime = microtime(true);

        $this->output->writeln('Start processing messages');
        $this->output->writeln('Messages: ' . count($messages));
        foreach ($messages as $i => $message) {
            $this->output->writeln('');
            $this->output->writeln('Message #' . $i);
            $this->output->writeln($this->prettifyJson($message->getQueueMessage()->getBody()));
        }
    }

    /**
     * @param array<\Generated\Shared\Transfer\QueueReceiveMessageTransfer> $messages
     *
     * @return void
     */
    public function finishMessages(array $messages): void
    {
        if (!$this->output) {
            return;
        }

        if ($this->output->getVerbosity() < OutputInterface::VERBOSITY_DEBUG) {
            return;
        }

        $this->output->writeln('');
        $this->output->writeln('Finish processing messages');
        $this->output->writeln('Processed messages: ' . count($messages));
        $this->output->writeln('Processing time: ' . (microtime(true) - $this->startTime) . 's');

        foreach ($messages as $i => $message) {
            if ($message->getHasError()) {
                $this->output->writeln('');
                $this->output->writeln('Error in message #' . $i);

                $messageBody = json_decode($message->getQueueMessage()->getBody(), true);

                $this->output->writeln('Error message: ' . $messageBody['errorMessage'] ?? 'unknown');
            }
        }
    }

    /**
     * @param string $json
     *
     * @throws \Exception
     *
     * @return string
     */
    private function prettifyJson(string $json): string
    {
        $decodedJson = json_decode($json, false);

        if (json_last_error()) {
            throw new Exception(
                'Cannot prettify invalid json',
            );
        }

        return json_encode($decodedJson, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
