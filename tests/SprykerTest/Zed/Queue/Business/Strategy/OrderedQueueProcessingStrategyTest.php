<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Business\Strategy;

use ArrayObject;
use Codeception\Test\Unit;
use ReflectionClass;
use Spryker\Zed\Queue\Business\Queue\QueueMetrics;
use Spryker\Zed\Queue\Business\QueueBusinessFactory;
use Spryker\Zed\Queue\Business\Scanner\QueueScannerInterface;
use Spryker\Zed\Queue\Business\Strategy\OrderedQueueProcessingStrategy;

/**
 * Auto-generated group annotations
 *
 * @group SprykerTest
 * @group Zed
 * @group Queue
 * @group Business
 * @group Strategy
 * @group OrderedQueueProcessingStrategyTest
 * Add your own group annotations below this line
 */
class OrderedQueueProcessingStrategyTest extends Unit
{
    /**
     * @var \SprykerTest\Zed\Queue\QueueBusinessTester
     */
    protected $tester;

    public function testQueuesAreHandedOutOneAtATimeInScanOrder(): void
    {
        // Arrange
        $queueScannerMock = $this->createScannerReturning([['alpha'], []]);
        $strategy = new OrderedQueueProcessingStrategy($queueScannerMock);

        // Act & Assert
        $this->assertSame('alpha', $strategy->getNextQueue()?->getQueueName());
    }

    public function testTheConfiguredOrderOfTheScanResultIsPreserved(): void
    {
        // Arrange
        $queueScannerMock = $this->createScannerReturning([['alpha', 'beta', 'gamma']]);
        $strategy = new OrderedQueueProcessingStrategy($queueScannerMock);

        // Act
        $handedOut = [
            $strategy->getNextQueue()?->getQueueName(),
            $strategy->getNextQueue()?->getQueueName(),
            $strategy->getNextQueue()?->getQueueName(),
        ];

        // Assert
        $this->assertSame(
            ['alpha', 'beta', 'gamma'],
            $handedOut,
            'This strategy exists to preserve the order the queues were configured in.',
        );
    }

    public function testAnEmptyScanYieldsNoQueue(): void
    {
        // Arrange
        $queueScannerMock = $this->createScannerReturning([[]]);
        $strategy = new OrderedQueueProcessingStrategy($queueScannerMock);

        // Act
        $queuemetrics = $strategy->getNextQueue();

        // Assert
        $this->assertNull($queuemetrics);
    }

    /**
     * The core of the scan-frequency question: the iterator holds only the queues that had messages, so the
     * number of full rescans over a run is driven by how FEW queues are active, not by how many are
     * configured.
     *
     * @dataProvider scanFrequencyDataProvider
     *
     * @param int $activeQueueCount
     * @param int $getNextQueueCalls
     * @param int $expectedScanCount
     *
     * @return void
     */
    public function testAFullRescanHappensEveryTimeTheActiveQueuesAreExhausted(
        int $activeQueueCount,
        int $getNextQueueCalls,
        int $expectedScanCount
    ): void {
        // Arrange
        $activeQueueNames = [];
        for ($i = 0; $i < $activeQueueCount; $i++) {
            $activeQueueNames[] = 'queue-' . $i;
        }

        $scanCount = 0;
        $queueScannerMock = $this->createMock(QueueScannerInterface::class);
        $queueScannerMock
            ->method('scanQueues')
            ->willReturnCallback(function () use ($activeQueueNames, &$scanCount): ArrayObject {
                $scanCount++;

                return $this->buildScanResult($activeQueueNames);
            });

        $strategy = new OrderedQueueProcessingStrategy($queueScannerMock);

        // Act
        for ($call = 0; $call < $getNextQueueCalls; $call++) {
            $strategy->getNextQueue();
        }

        // Assert
        $this->assertSame(
            $expectedScanCount,
            $scanCount,
            sprintf(
                '%d active queues over %d calls must cost ceil(%d/%d) full rescans.',
                $activeQueueCount,
                $getNextQueueCalls,
                $getNextQueueCalls,
                $activeQueueCount,
            ),
        );
    }

    /**
     * @return array<string, array<int>>
     */
    public function scanFrequencyDataProvider(): array
    {
        // active queues, getNextQueue() calls, expected full rescans
        return [
            '20 active queues over 20 calls' => [20, 20, 1],
            '20 active queues over 40 calls' => [20, 40, 2],
            '6 active queues over 60 calls' => [6, 60, 10],
            '2 active queues over 60 calls' => [2, 60, 30],
            '1 active queue rescans on every single call' => [1, 12, 12],
        ];
    }

    public function testTheIgnoreEmptyScanCooldownFlagIsForwardedToTheScanner(): void
    {
        // Arrange
        $queueScannerMock = $this->createMock(QueueScannerInterface::class);
        $queueScannerMock
            ->expects($this->once())
            ->method('scanQueues')
            ->with([], 5, true)
            ->willReturn($this->buildScanResult([]));

        $strategy = new OrderedQueueProcessingStrategy($queueScannerMock);

        // Act
        $strategy->getNextQueue(true);

        // Assert
        // Handled by the `with()` expectation above.
    }

    public function testTheCooldownIsLeftAtTheScannerDefaultWhenNotIgnored(): void
    {
        // Arrange
        $queueScannerMock = $this->createMock(QueueScannerInterface::class);
        $queueScannerMock
            ->expects($this->once())
            ->method('scanQueues')
            ->with([], 5, false)
            ->willReturn($this->buildScanResult([]));

        $strategy = new OrderedQueueProcessingStrategy($queueScannerMock);

        // Act
        $strategy->getNextQueue();

        // Assert
        // Handled by the `with()` expectation above. The strategy never passes a store list and
        // never overrides the cooldown, so the scanner's 5 second default is what applies.
    }

    /**
     * Pins the fact that this strategy is currently unreachable, so wiring it (or deleting it) is a
     * deliberate act that breaks a test rather than a silent change.
     *
     * @return void
     */
    public function testNoFactoryMethodConstructsThisStrategy(): void
    {
        // Arrange
        $factoryFile = (new ReflectionClass(QueueBusinessFactory::class))->getFileName();
        $factorySource = file_get_contents((string)$factoryFile);

        // Act
        $isConstructedByFactory = str_contains((string)$factorySource, 'new OrderedQueueProcessingStrategy');

        // Assert
        $this->assertFalse(
            $isConstructedByFactory,
            'OrderedQueueProcessingStrategy is dead code today: createResourceAwareQueueWorker() wires '
            . 'createDynamicOrderStrategy() instead. If this strategy has just been wired up, update this '
            . 'test and close the dead-code item tracked on SOL-662.',
        );
    }

    /**
     * @param array<array<string>> $scanResults
     *
     * @return \Spryker\Zed\Queue\Business\Scanner\QueueScannerInterface
     */
    protected function createScannerReturning(array $scanResults): QueueScannerInterface
    {
        $callIndex = 0;
        $queueScannerMock = $this->createMock(QueueScannerInterface::class);
        $queueScannerMock
            ->method('scanQueues')
            ->willReturnCallback(function () use ($scanResults, &$callIndex): ArrayObject {
                $names = $scanResults[$callIndex] ?? [];
                $callIndex++;

                return $this->buildScanResult($names);
            });

        return $queueScannerMock;
    }

    /**
     * @param array<string> $queueNames
     *
     * @return \ArrayObject<int, \Spryker\Zed\Queue\Business\Queue\QueueMetrics>
     */
    protected function buildScanResult(array $queueNames): ArrayObject
    {
        $queuemetrics = new ArrayObject();

        foreach ($queueNames as $queueName) {
            $queuemetrics->append(
                (new QueueMetrics())
                    ->setQueueName($queueName)
                    ->setStoreName('DE')
                    ->setRegionName('EU')
                    ->setMessageCount(10)
                    ->setBatchSize(1)
                    ->setMessageToChunkSizeRatio(1)
                    ->setPriority(0),
            );
        }

        return $queuemetrics;
    }
}
