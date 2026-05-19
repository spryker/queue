<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Business;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\QueueReceiveMessageTransfer;
use Generated\Shared\Transfer\QueueSendMessageTransfer;
use Spryker\Client\Queue\QueueClient;
use Spryker\Zed\Queue\Business\QueueBusinessFactory;
use Spryker\Zed\Queue\Business\QueueFacade;
use Spryker\Zed\Queue\Business\QueueFacadeInterface;
use Spryker\Zed\Queue\Dependency\Plugin\QueueMessageProcessorPluginInterface;
use Spryker\Zed\Queue\QueueConfig;
use Spryker\Zed\Queue\QueueDependencyProvider;

/**
 * Auto-generated group annotations
 *
 * @group SprykerTest
 * @group Zed
 * @group Queue
 * @group Business
 * @group TaskManagerTest
 * Add your own group annotations below this line
 */
class TaskManagerTest extends Unit
{
    /**
     * @var string
     */
    protected const QUEUE_NAME = 'test.queue';

    /**
     * @var string
     */
    protected const QUEUE_NAME_RETRY = 'test.queue.retry';

    /**
     * @var int
     */
    protected const CHUNK_SIZE = 1;

    /**
     * @var \SprykerTest\Zed\Queue\QueueBusinessTester
     */
    protected $tester;

    public function testFailedMessageGoesToErrorQueueWhenRetryQueueIsNotRegistered(): void
    {
        // Arrange: only the main queue processor is registered — no retry queue
        $capturedMessages = [];
        $processorPlugin = $this->createCapturingProcessorPlugin($capturedMessages);

        $this->tester->setDependency(QueueDependencyProvider::QUEUE_MESSAGE_PROCESSOR_PLUGINS, [
            static::QUEUE_NAME => $processorPlugin,
        ]);
        $this->tester->setDependency(QueueDependencyProvider::CLIENT_QUEUE, $this->createQueueClientMock());

        // Act
        $this->createFacade()->startTask(static::QUEUE_NAME);

        // Assert: isRetryExist must be false so the processor routes to error queue, not lost in a non-existent retry queue
        $this->assertNotEmpty($capturedMessages);

        foreach ($capturedMessages as $message) {
            $this->assertFalse(
                $message->getIsRetryExist(),
                'Message must have isRetryExist=false when no retry queue processor is registered.',
            );
        }
    }

    public function testMessageKeepsIsRetryExistNullWhenRetryQueueIsRegistered(): void
    {
        // Arrange: both main queue and retry queue processors are registered
        $capturedMessages = [];
        $processorPlugin = $this->createCapturingProcessorPlugin($capturedMessages);
        $retryQueueMessages = [];
        $retryProcessorPlugin = $this->createCapturingProcessorPlugin($retryQueueMessages);

        $this->tester->setDependency(QueueDependencyProvider::QUEUE_MESSAGE_PROCESSOR_PLUGINS, [
            static::QUEUE_NAME => $processorPlugin,
            static::QUEUE_NAME_RETRY => $retryProcessorPlugin,
        ]);
        $this->tester->setDependency(QueueDependencyProvider::CLIENT_QUEUE, $this->createQueueClientMock());

        // Act
        $this->createFacade()->startTask(static::QUEUE_NAME);

        // Assert: isRetryExist is not explicitly set to false, so retry is allowed
        $this->assertNotEmpty($capturedMessages);

        foreach ($capturedMessages as $message) {
            $this->assertNotFalse(
                $message->getIsRetryExist(),
                'Message must not have isRetryExist=false when retry queue processor is registered.',
            );
        }
    }

    public function testMessagesFromRetryQueueAreNotModified(): void
    {
        // Arrange: processing from the retry queue itself must not change isRetryExist
        $capturedMessages = [];
        $retryProcessorPlugin = $this->createCapturingProcessorPlugin($capturedMessages);

        $mainQueueMessages = [];
        $this->tester->setDependency(QueueDependencyProvider::QUEUE_MESSAGE_PROCESSOR_PLUGINS, [
            static::QUEUE_NAME => $this->createCapturingProcessorPlugin($mainQueueMessages),
            static::QUEUE_NAME_RETRY => $retryProcessorPlugin,
        ]);
        $this->tester->setDependency(QueueDependencyProvider::CLIENT_QUEUE, $this->createQueueClientMock());

        // Act: process from the retry queue
        $this->createFacade()->startTask(static::QUEUE_NAME_RETRY);

        // Assert: messages from retry queue are returned as-is, isRetryExist is not touched
        $this->assertNotEmpty($capturedMessages);

        foreach ($capturedMessages as $message) {
            $this->assertNull(
                $message->getIsRetryExist(),
                'Messages from retry queue must not have isRetryExist modified.',
            );
        }
    }

    protected function createCapturingProcessorPlugin(array &$capturedMessages): QueueMessageProcessorPluginInterface
    {
        $plugin = $this->getMockBuilder(QueueMessageProcessorPluginInterface::class)->getMock();

        $plugin->method('processMessages')->willReturnCallback(
            function (array $messages) use (&$capturedMessages): array {
                $capturedMessages = $messages;

                return $messages;
            },
        );
        $plugin->method('getChunkSize')->willReturn(static::CHUNK_SIZE);

        return $plugin;
    }

    protected function createQueueClientMock(): QueueClient
    {
        $queueClientMock = $this->getMockBuilder(QueueClient::class)->getMock();

        $message = (new QueueReceiveMessageTransfer())
            ->setQueueMessage((new QueueSendMessageTransfer())->setBody('{}'));

        $queueClientMock->method('receiveMessages')->willReturn([$message]);

        return $queueClientMock;
    }

    protected function createFacade(): QueueFacadeInterface
    {
        $queueConfigMock = $this->getMockBuilder(QueueConfig::class)->getMock();
        $queueConfigMock->method('getQueueServerId')->willReturn($this->tester->getServerName());
        $queueConfigMock->method('getQueueReceiverOption')->willReturn([]);

        $factory = new QueueBusinessFactory();
        $factory->setConfig($queueConfigMock);

        $facade = new QueueFacade();
        $facade->setFactory($factory);

        return $facade;
    }
}
