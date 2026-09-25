<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Client\Queue\Model\Adapter;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\QueueSendMessageTransfer;
use Spryker\Client\Queue\Model\Adapter\InMemoryQueueAdapter;

/**
 * Auto-generated group annotations
 *
 * @group SprykerTest
 * @group Client
 * @group Queue
 * @group Model
 * @group Adapter
 * @group InMemoryQueueAdapterTest
 * Add your own group annotations below this line
 */
class InMemoryQueueAdapterTest extends Unit
{
    protected const string QUEUE_NAME = 'publish';

    protected function _before(): void
    {
        InMemoryQueueAdapter::cleanState();
    }

    protected function _after(): void
    {
        InMemoryQueueAdapter::cleanState();
    }

    public function testGivenSentMessagesWhenReceivingThenTheyAreReturnedInOrder(): void
    {
        // Arrange
        $adapter = new InMemoryQueueAdapter();
        $adapter->sendMessages(static::QUEUE_NAME, [
            (new QueueSendMessageTransfer())->setBody('first'),
            (new QueueSendMessageTransfer())->setBody('second'),
        ]);

        // Act
        $queueReceiveMessageTransfers = $adapter->receiveMessages(static::QUEUE_NAME);

        // Assert
        $this->assertCount(2, $queueReceiveMessageTransfers);
        $this->assertSame('first', $queueReceiveMessageTransfers[0]->getQueueMessage()->getBody());
        $this->assertSame(static::QUEUE_NAME, $queueReceiveMessageTransfers[0]->getQueueName());
    }

    public function testGivenNoBrokerWhenSendingASingleMessageThenItEnqueuesWithoutError(): void
    {
        // Arrange
        $adapter = new InMemoryQueueAdapter();

        // Act
        $adapter->sendMessage(static::QUEUE_NAME, (new QueueSendMessageTransfer())->setBody('only'));

        // Assert
        $queueReceiveMessageTransfer = $adapter->receiveMessage(static::QUEUE_NAME);
        $this->assertSame('only', $queueReceiveMessageTransfer->getQueueMessage()->getBody());
    }

    public function testGivenSharedStaticStateWhenAnotherInstanceReadsThenItSeesTheSameQueue(): void
    {
        // Arrange
        $writer = new InMemoryQueueAdapter();
        $writer->sendMessage(static::QUEUE_NAME, (new QueueSendMessageTransfer())->setBody('shared'));

        // Act
        $reader = new InMemoryQueueAdapter();
        $queueReceiveMessageTransfers = $reader->receiveMessages(static::QUEUE_NAME);

        // Assert
        $this->assertCount(1, $queueReceiveMessageTransfers);
        $this->assertSame('shared', $queueReceiveMessageTransfers[0]->getQueueMessage()->getBody());
    }

    public function testGivenAReceivedChunkWhenReceivingAgainThenOnlyTheRemainingMessagesComeBack(): void
    {
        // Arrange
        $adapter = new InMemoryQueueAdapter();
        $adapter->sendMessages(static::QUEUE_NAME, [
            (new QueueSendMessageTransfer())->setBody('first'),
            (new QueueSendMessageTransfer())->setBody('second'),
        ]);

        // Act — acknowledging is a no-op here, so receiving is what consumes.
        $adapter->receiveMessages(static::QUEUE_NAME, 1);
        $queueReceiveMessageTransfers = $adapter->receiveMessages(static::QUEUE_NAME);

        // Assert
        $this->assertCount(1, $queueReceiveMessageTransfers);
        $this->assertSame('second', $queueReceiveMessageTransfers[0]->getQueueMessage()->getBody());
    }

    public function testGivenAnUnknownQueueWhenReceivingThenAnEmptyListIsReturned(): void
    {
        // Arrange
        $adapter = new InMemoryQueueAdapter();

        // Act
        $queueReceiveMessageTransfers = $adapter->receiveMessages('never-created');

        // Assert
        $this->assertSame([], $queueReceiveMessageTransfers);
    }

    public function testGivenAPopulatedQueueWhenPurgedThenItBecomesEmpty(): void
    {
        // Arrange
        $adapter = new InMemoryQueueAdapter();
        $adapter->sendMessage(static::QUEUE_NAME, new QueueSendMessageTransfer());

        // Act
        $adapter->purgeQueue(static::QUEUE_NAME);

        // Assert
        $this->assertSame([], $adapter->receiveMessages(static::QUEUE_NAME));
    }
}
