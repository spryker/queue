<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Business\Worker;

use Codeception\Test\Unit;
use Spryker\Shared\Queue\QueueConfig as SharedQueueConfig;
use Spryker\Zed\Queue\Business\Process\ProcessManagerInterface;
use Spryker\Zed\Queue\QueueConfig;
use SprykerTest\Zed\Queue\Helper\FakeProcess;
use SprykerTest\Zed\Queue\Helper\QueueWorkerFixtures;
use SprykerTest\Zed\Queue\Helper\TestableAbstractQueueWorker;

/**
 * Auto-generated group annotations
 *
 * @group SprykerTest
 * @group Zed
 * @group Queue
 * @group Business
 * @group Worker
 * @group AbstractQueueWorkerTest
 * Add your own group annotations below this line
 */
class AbstractQueueWorkerTest extends Unit
{
    /**
     * @var \SprykerTest\Zed\Queue\QueueBusinessTester
     */
    protected $tester;

    public function testExecuteUsleepUsesTheNotEmptyDelayWhenProcessesAreRunning(): void
    {
        // Arrange
        $queueConfigMock = $this->createMock(QueueConfig::class);
        $queueConfigMock
            ->expects($this->once())
            ->method('getDelayWhenQueueIsNotEmptyMilliseconds')
            ->willReturn(QueueWorkerFixtures::NO_SLEEP_MILLISECONDS);

        $worker = $this->createWorker($queueConfigMock);

        // Act
        $worker->executeUsleep(QueueWorkerFixtures::NO_SLEEP_MILLISECONDS, [new FakeProcess()]);

        // Assert
        // Handled by the `once()` expectation above: the passed interval is discarded in favour of
        // the not-empty delay as soon as at least one process is still running.
    }

    public function testExecuteUsleepKeepsThePassedIntervalWhenNoProcessesAreRunning(): void
    {
        // Arrange
        $queueConfigMock = $this->createMock(QueueConfig::class);
        $queueConfigMock
            ->expects($this->never())
            ->method('getDelayWhenQueueIsNotEmptyMilliseconds');

        $worker = $this->createWorker($queueConfigMock);

        // Act
        $worker->executeUsleep(QueueWorkerFixtures::NO_SLEEP_MILLISECONDS, []);

        // Assert
        // Handled by the `never()` expectation above.
    }

    /**
     * @dataProvider workerStopsWhenEmptyQueueOptionDataProvider
     *
     * @param array<string, mixed> $options
     * @param bool $expected
     *
     * @return void
     */
    public function testIsWorkerStopsWhenEmptyQueueEnabledReadsTheStopWhenEmptyOption(
        array $options,
        bool $expected
    ): void {
        // Arrange
        $worker = $this->createWorker($this->createMock(QueueConfig::class));

        // Act
        $isEnabled = $worker->callIsWorkerStopsWhenEmptyQueueEnabled($options);

        // Assert
        $this->assertSame($expected, $isEnabled);
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function workerStopsWhenEmptyQueueOptionDataProvider(): array
    {
        return [
            'option absent' => [[], false],
            'option false' => [[SharedQueueConfig::CONFIG_WORKER_STOP_WHEN_EMPTY => false], false],
            'option null' => [[SharedQueueConfig::CONFIG_WORKER_STOP_WHEN_EMPTY => null], false],
            'option true' => [[SharedQueueConfig::CONFIG_WORKER_STOP_WHEN_EMPTY => true], true],
            'unrelated option only' => [['some-other-option' => true], false],
        ];
    }

    protected function createWorker(QueueConfig $queueConfig): TestableAbstractQueueWorker
    {
        return new TestableAbstractQueueWorker(
            $this->createMock(ProcessManagerInterface::class),
            $queueConfig,
        );
    }
}
