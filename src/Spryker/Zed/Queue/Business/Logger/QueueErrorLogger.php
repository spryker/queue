<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Logger;

use Psr\Log\LoggerInterface;

class QueueErrorLogger implements QueueErrorLoggerInterface
{
    /**
     * @var string
     */
    protected const MESSAGE_BODY_KEY_ERROR_MESSAGE = 'errorMessage';

    /**
     * @var string
     */
    protected const COLOR_YELLOW = "\033[33m";

    /**
     * @var string
     */
    protected const COLOR_RED = "\033[31m";

    /**
     * @var string
     */
    protected const COLOR_RESET = "\033[0m";

    /**
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(protected LoggerInterface $logger)
    {
    }

    /**
     * @param string $queueName
     * @param array<\Generated\Shared\Transfer\QueueReceiveMessageTransfer> $messages
     *
     * @return void
     */
    public function logFailedMessages(string $queueName, array $messages): void
    {
        $failedMessages = $this->filterFailedMessages($messages);

        if ($failedMessages === []) {
            return;
        }

        $this->outputToStderr($queueName, $failedMessages);
    }

    /**
     * @param string $queueName
     * @param array<\Generated\Shared\Transfer\QueueReceiveMessageTransfer> $failedMessages
     *
     * @return void
     */
    protected function outputToStderr(string $queueName, array $failedMessages): void
    {
        $output = $this->formatHeader($queueName, count($failedMessages));
        $output .= $this->formatMessages($failedMessages);

        $this->logger->error($output);
    }

    /**
     * @param string $queueName
     * @param int $failedMessagesCount
     *
     * @return string
     */
    protected function formatHeader(string $queueName, int $failedMessagesCount): string
    {
        return sprintf(
            '%s%s[Queue Message Errors]%s%s' .
            'Queue: %s%s%s | Failed messages: %s%d%s%s%s',
            PHP_EOL,
            static::COLOR_YELLOW,
            static::COLOR_RESET,
            PHP_EOL,
            static::COLOR_YELLOW,
            $queueName,
            static::COLOR_RESET,
            static::COLOR_RED,
            $failedMessagesCount,
            static::COLOR_RESET,
            PHP_EOL,
            PHP_EOL,
        );
    }

    /**
     * @param array<\Generated\Shared\Transfer\QueueReceiveMessageTransfer> $failedMessages
     *
     * @return string
     */
    protected function formatMessages(array $failedMessages): string
    {
        $output = '';

        foreach ($failedMessages as $index => $messageTransfer) {
            $queueMessage = $messageTransfer->getQueueMessage();

            if ($queueMessage === null) {
                continue;
            }

            $output .= $this->formatSingleMessage($index + 1, $queueMessage->getBody());
        }

        return $output;
    }

    /**
     * @param int $messageNumber
     * @param string $body
     *
     * @return string
     */
    protected function formatSingleMessage(int $messageNumber, string $body): string
    {
        $output = sprintf('Message #%d:%s', $messageNumber, PHP_EOL);
        $bodyData = json_decode($body, true);

        if (!is_array($bodyData) || !isset($bodyData[static::MESSAGE_BODY_KEY_ERROR_MESSAGE])) {
            return $output . sprintf('  Body: %s%s%s', $body, PHP_EOL, PHP_EOL);
        }

        $errorMessage = $bodyData[static::MESSAGE_BODY_KEY_ERROR_MESSAGE];
        unset($bodyData[static::MESSAGE_BODY_KEY_ERROR_MESSAGE]);

        $output .= $this->formatMessageBody($bodyData);
        $output .= $this->formatErrorMessage($errorMessage);

        return $output;
    }

    /**
     * @param array $bodyData
     *
     * @return string
     */
    protected function formatMessageBody(array $bodyData): string
    {
        $bodyWithoutError = json_encode($bodyData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return sprintf('  Body: %s%s%s', $bodyWithoutError, PHP_EOL, PHP_EOL);
    }

    /**
     * @param string $errorMessage
     *
     * @return string
     */
    protected function formatErrorMessage(string $errorMessage): string
    {
        return sprintf(
            '  Error: %s%s%s%s%s',
            static::COLOR_RED,
            $errorMessage,
            static::COLOR_RESET,
            PHP_EOL,
            PHP_EOL,
        );
    }

    /**
     * @param array<\Generated\Shared\Transfer\QueueReceiveMessageTransfer> $messages
     *
     * @return array<\Generated\Shared\Transfer\QueueReceiveMessageTransfer>
     */
    protected function filterFailedMessages(array $messages): array
    {
        $failedMessages = [];

        foreach ($messages as $message) {
            if (!$message->getHasError()) {
                continue;
            }

            $failedMessages[] = $message;
        }

        return $failedMessages;
    }
}
