<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Business\Reader;

use Codeception\Test\Unit;
use Spryker\Shared\Queue\QueueConfig as SharedQueueConfig;
use Spryker\Zed\Queue\Business\Reader\QueueConfigReader;
use Spryker\Zed\Queue\QueueConfig;

/**
 * Auto-generated group annotations
 *
 * @group SprykerTest
 * @group Zed
 * @group Queue
 * @group Business
 * @group Reader
 * @group QueueConfigReaderTest
 * Add your own group annotations below this line
 */
class QueueConfigReaderTest extends Unit
{
    /**
     * @var \SprykerTest\Zed\Queue\QueueBusinessTester
     */
    protected $tester;

    /**
     * @var string
     */
    protected const QUEUE_NAME = 'event';

    /**
     * @var string
     */
    protected const ADAPTER_NAME = 'rabbitmq';

    public function testAQueueWithItsOwnConfigurationUsesItsOwnWorkerLimit(): void
    {
        // Arrange
        $queueConfigReader = $this->createQueueConfigReader(
            queueAdapterConfiguration: [
                static::QUEUE_NAME => [
                    SharedQueueConfig::CONFIG_QUEUE_ADAPTER => static::ADAPTER_NAME,
                    SharedQueueConfig::CONFIG_MAX_WORKER_NUMBER => 7,
                ],
            ],
        );

        // Act
        $maxQueueWorker = $queueConfigReader->getMaxQueueWorkerByQueueName(static::QUEUE_NAME);

        // Assert
        $this->assertSame(7, $maxQueueWorker);
    }

    /**
     * This is the behaviour behind "one worker per queue no matter how big the pool is": the DEFAULT adapter
     * block usually carries max_worker_number => 1, and every queue not listed explicitly inherits it.
     *
     * @return void
     */
    public function testAnUnlistedQueueInheritsTheDefaultBlocksWorkerLimit(): void
    {
        // Arrange
        $queueConfigReader = $this->createQueueConfigReader(
            defaultQueueAdapterConfiguration: [
                SharedQueueConfig::CONFIG_QUEUE_ADAPTER => static::ADAPTER_NAME,
                SharedQueueConfig::CONFIG_MAX_WORKER_NUMBER => 1,
            ],
        );

        // Act
        $maxQueueWorker = $queueConfigReader->getMaxQueueWorkerByQueueName('a-queue-nobody-configured');

        // Assert
        $this->assertSame(
            1,
            $maxQueueWorker,
            'The DEFAULT block wins over the DEFAULT_MAX_QUEUE_WORKER constant whenever it supplies the key.',
        );
    }

    /**
     * `QueueConfig::DEFAULT_MAX_QUEUE_WORKER` is reachable only when no configuration at any level supplies
     * `max_worker_number`.
     *
     * @return void
     */
    public function testTheBuiltInDefaultAppliesOnlyWhenNoConfigurationSuppliesAWorkerLimit(): void
    {
        // Arrange
        $queueConfigReader = $this->createQueueConfigReader(
            defaultQueueAdapterConfiguration: [
                SharedQueueConfig::CONFIG_QUEUE_ADAPTER => static::ADAPTER_NAME,
            ],
        );

        // Act
        $maxQueueWorker = $queueConfigReader->getMaxQueueWorkerByQueueName(static::QUEUE_NAME);

        // Assert
        $this->assertSame(QueueConfig::DEFAULT_MAX_QUEUE_WORKER, $maxQueueWorker);
    }

    public function testTheBuiltInDefaultAppliesWhenThereIsNoConfigurationAtAll(): void
    {
        // Arrange
        $queueConfigReader = $this->createQueueConfigReader();

        // Act
        $maxQueueWorker = $queueConfigReader->getMaxQueueWorkerByQueueName(static::QUEUE_NAME);

        // Assert
        $this->assertSame(QueueConfig::DEFAULT_MAX_QUEUE_WORKER, $maxQueueWorker);
    }

    public function testTheAdapterOfAnExplicitlyConfiguredQueueIsReturned(): void
    {
        // Arrange
        $queueConfigReader = $this->createQueueConfigReader(
            queueAdapterConfiguration: [
                static::QUEUE_NAME => [SharedQueueConfig::CONFIG_QUEUE_ADAPTER => 'custom-adapter'],
            ],
            defaultQueueAdapterConfiguration: [
                SharedQueueConfig::CONFIG_QUEUE_ADAPTER => static::ADAPTER_NAME,
            ],
        );

        // Act
        $queueAdapter = $queueConfigReader->getQueueAdapter(static::QUEUE_NAME);

        // Assert
        $this->assertSame('custom-adapter', $queueAdapter);
    }

    public function testAnUnlistedQueueFallsBackToTheDefaultAdapter(): void
    {
        // Arrange
        $queueConfigReader = $this->createQueueConfigReader(
            defaultQueueAdapterConfiguration: [
                SharedQueueConfig::CONFIG_QUEUE_ADAPTER => static::ADAPTER_NAME,
            ],
        );

        // Act
        $queueAdapter = $queueConfigReader->getQueueAdapter('a-queue-nobody-configured');

        // Assert
        $this->assertSame(static::ADAPTER_NAME, $queueAdapter);
    }

    public function testTheAdapterIsNullWhenNothingConfiguresOne(): void
    {
        // Arrange
        $queueConfigReader = $this->createQueueConfigReader();

        // Act
        $queueAdapter = $queueConfigReader->getQueueAdapter(static::QUEUE_NAME);

        // Assert
        $this->assertNull(
            $queueAdapter,
            'A null adapter is what makes QueueScanner fall back to its default message count.',
        );
    }

    /**
     * @param array<string, mixed> $queueAdapterConfiguration
     * @param array<string, mixed> $defaultQueueAdapterConfiguration
     *
     * @return \Spryker\Zed\Queue\Business\Reader\QueueConfigReader
     */
    protected function createQueueConfigReader(
        array $queueAdapterConfiguration = [],
        array $defaultQueueAdapterConfiguration = []
    ): QueueConfigReader {
        $queueConfigMock = $this->createMock(QueueConfig::class);
        $queueConfigMock->method('getQueueAdapterConfiguration')->willReturn($queueAdapterConfiguration);
        $queueConfigMock->method('getDefaultQueueAdapterConfiguration')->willReturn($defaultQueueAdapterConfiguration);

        return new QueueConfigReader($queueConfigMock);
    }
}
