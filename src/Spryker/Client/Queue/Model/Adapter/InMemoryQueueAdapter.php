<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Client\Queue\Model\Adapter;

use Generated\Shared\Transfer\QueueReceiveMessageTransfer;
use Generated\Shared\Transfer\QueueSendMessageTransfer;
use Ramsey\Uuid\Uuid;

/**
 * Broker-free queue adapter that keeps messages in process memory instead of talking to RabbitMQ or
 * Symfony Messenger. Selected through QUEUE_ADAPTER_CONFIGURATION when a broker is intentionally
 * absent — e.g. the SQLite host-lane database build, where the EventBehavior post-run hook of
 * setup:init-db / data:import would otherwise abort resolving a default broker connection.
 * Nothing consumes against it on that path, so enqueued messages simply accumulate and vanish
 * with the process.
 */
class InMemoryQueueAdapter implements AdapterInterface
{
    /**
     * Kept static so every instance created within a request shares the same queues: the client
     * factory constructs a fresh adapter per resolution, but a single in-memory broker has to span
     * them all.
     *
     * @var array<string, array<\Generated\Shared\Transfer\QueueSendMessageTransfer>>
     */
    protected static array $queues = [];

    /**
     * @param string $queueName
     * @param array<string, mixed> $options
     *
     * @return array
     */
    public function createQueue($queueName, array $options = []): array
    {
        static::$queues[$queueName] = [];

        return static::$queues[$queueName];
    }

    /**
     * @param string $queueName
     * @param array<string, mixed> $options
     *
     * @return bool
     */
    public function purgeQueue($queueName, array $options = []): bool
    {
        static::$queues[$queueName] = [];

        return true;
    }

    /**
     * @param string $queueName
     * @param array<string, mixed> $options
     *
     * @return bool
     */
    public function deleteQueue($queueName, array $options = []): bool
    {
        unset(static::$queues[$queueName]);

        return true;
    }

    /**
     * @param string $queueName
     * @param int $chunkSize
     * @param array<string, mixed> $options
     *
     * @return array<\Generated\Shared\Transfer\QueueReceiveMessageTransfer>
     */
    public function receiveMessages($queueName, $chunkSize = 100, array $options = []): array
    {
        if (!isset(static::$queues[$queueName])) {
            return [];
        }

        $queueReceiveMessageTransfers = [];
        foreach (array_splice(static::$queues[$queueName], 0, $chunkSize) as $queueSendMessageTransfer) {
            $queueReceiveMessageTransfers[] = $this->buildQueueReceiveMessageTransfer($queueSendMessageTransfer, $queueName);
        }

        return $queueReceiveMessageTransfers;
    }

    /**
     * @param string $queueName
     * @param array<string, mixed> $options
     *
     * @return \Generated\Shared\Transfer\QueueReceiveMessageTransfer
     */
    public function receiveMessage($queueName, array $options = []): QueueReceiveMessageTransfer
    {
        if (!isset(static::$queues[$queueName])) {
            return new QueueReceiveMessageTransfer();
        }

        $queueSendMessageTransfer = array_shift(static::$queues[$queueName]);
        if ($queueSendMessageTransfer === null) {
            return new QueueReceiveMessageTransfer();
        }

        return $this->buildQueueReceiveMessageTransfer($queueSendMessageTransfer, $queueName);
    }

    public function acknowledge(QueueReceiveMessageTransfer $queueReceiveMessageTransfer): void
    {
    }

    public function reject(QueueReceiveMessageTransfer $queueReceiveMessageTransfer): void
    {
    }

    public function handleError(QueueReceiveMessageTransfer $queueReceiveMessageTransfer): bool
    {
        return true;
    }

    /**
     * @param string $queueName
     * @param \Generated\Shared\Transfer\QueueSendMessageTransfer $queueSendMessageTransfer
     *
     * @return void
     */
    public function sendMessage($queueName, QueueSendMessageTransfer $queueSendMessageTransfer): void
    {
        static::$queues[$queueName][] = $queueSendMessageTransfer;
    }

    /**
     * @param string $queueName
     * @param array<\Generated\Shared\Transfer\QueueSendMessageTransfer> $queueSendMessageTransfers
     *
     * @return void
     */
    public function sendMessages($queueName, array $queueSendMessageTransfers): void
    {
        foreach ($queueSendMessageTransfers as $queueSendMessageTransfer) {
            static::$queues[$queueName][] = $queueSendMessageTransfer;
        }
    }

    /**
     * Drops every in-memory queue. Lets an in-process test reset the shared static state between
     * runs; the broker-free command path never needs it.
     *
     * @return void
     */
    public static function cleanState(): void
    {
        static::$queues = [];
    }

    protected function buildQueueReceiveMessageTransfer(
        QueueSendMessageTransfer $queueSendMessageTransfer,
        string $queueName
    ): QueueReceiveMessageTransfer {
        return (new QueueReceiveMessageTransfer())
            ->setQueueMessage($queueSendMessageTransfer)
            ->setQueueName($queueName)
            ->setDeliveryTag(Uuid::uuid4()->toString());
    }
}
