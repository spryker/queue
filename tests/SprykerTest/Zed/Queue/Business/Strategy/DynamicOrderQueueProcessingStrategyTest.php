<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Business\Strategy;

use ArrayObject;
use Codeception\Test\Unit;
use PHPUnit\Framework\MockObject\MockObject;
use Spryker\Shared\Queue\Enum\QueueReadModeEnum;
use Spryker\Zed\Queue\Business\Logger\WorkerLoggerInterface;
use Spryker\Zed\Queue\Business\Queue\QueueMetrics;
use Spryker\Zed\Queue\Business\Scanner\QueueScannerInterface;
use Spryker\Zed\Queue\Business\Strategy\DynamicOrderQueueProcessingStrategy;
use Spryker\Zed\Queue\QueueConfig;
use SprykerTest\Zed\Queue\Helper\FakeDynamicSettingsUpdaterPlugin;

/**
 * Auto-generated group annotations
 *
 * @group SprykerTest
 * @group Zed
 * @group Queue
 * @group Business
 * @group Strategy
 * @group DynamicOrderQueueProcessingStrategyTest
 * Add your own group annotations below this line
 */
class DynamicOrderQueueProcessingStrategyTest extends Unit
{
    /**
     * @var \SprykerTest\Zed\Queue\QueueBusinessTester
     */
    protected $tester;

    /**
     * @var int
     */
    protected const BIG_QUEUE_THRESHOLD_BATCHES = 10;

    /**
     * @var int
     */
    protected const LIMIT_PER_QUEUE = 5;

    public function testPreferPubModePromotesPublishQueuesAboveTheRest(): void
    {
        // Arrange
        $strategy = $this->createStrategy(
            [
                $this->createQueuemetrics('event', messageCount: 100),
                $this->createQueuemetrics('publish.product', messageCount: 100),
            ],
            mode: QueueReadModeEnum::MODE_PREFER_PUB->value,
        );

        // Act
        $order = $this->drainQueueNames($strategy, 4);

        // Assert
        $this->assertSame(['publish.product', 'event'], $order);
    }

    public function testPreferSyncModePromotesSyncQueuesAboveTheRest(): void
    {
        // Arrange
        $strategy = $this->createStrategy(
            [
                $this->createQueuemetrics('event', messageCount: 100),
                $this->createQueuemetrics('sync.storage', messageCount: 100),
            ],
            mode: QueueReadModeEnum::MODE_PREFER_SYNC->value,
        );

        // Act
        $order = $this->drainQueueNames($strategy, 4);

        // Assert
        $this->assertSame(['sync.storage', 'event'], $order);
    }

    public function testPreferBigModePromotesQueuesHoldingMoreThanTheBatchThreshold(): void
    {
        // Arrange
        $strategy = $this->createStrategy(
            [
                $this->createQueuemetrics('small', messageCount: 100, ratio: static::BIG_QUEUE_THRESHOLD_BATCHES - 1),
                $this->createQueuemetrics('big', messageCount: 100, ratio: static::BIG_QUEUE_THRESHOLD_BATCHES + 1),
            ],
            mode: QueueReadModeEnum::MODE_PREFER_BIG->value,
        );

        // Act
        // Enough calls to get past the repeats of the first queue and reach the second.
        $order = $this->drainQueueNames($strategy, 30);

        // Assert
        $this->assertSame(['big', 'small'], $order);
    }

    public function testPreferSmallModePromotesQueuesHoldingLessThanTheBatchThreshold(): void
    {
        // Arrange
        $strategy = $this->createStrategy(
            [
                $this->createQueuemetrics('big', messageCount: 100, ratio: static::BIG_QUEUE_THRESHOLD_BATCHES + 1),
                $this->createQueuemetrics('small', messageCount: 100, ratio: static::BIG_QUEUE_THRESHOLD_BATCHES - 1),
            ],
            mode: QueueReadModeEnum::MODE_PREFER_SMALL->value,
        );

        // Act
        // Enough calls to get past the repeats of the first queue and reach the second.
        $order = $this->drainQueueNames($strategy, 30);

        // Assert
        $this->assertSame(['small', 'big'], $order);
    }

    /**
     * A queue holding less than one full batch is pushed far down the order, so partially filled queues
     * never starve a queue that can fill a whole batch.
     *
     * @return void
     */
    public function testAQueueHoldingLessThanOneBatchIsHeavilyDeprioritised(): void
    {
        // Arrange
        $strategy = $this->createStrategy(
            [
                $this->createQueuemetrics('under-filled', messageCount: 5, batchSize: 100),
                $this->createQueuemetrics('full-batch', messageCount: 100, batchSize: 100),
            ],
            mode: QueueReadModeEnum::MODE_READ_ORDER->value,
        );

        // Act
        $order = $this->drainQueueNames($strategy, 4);

        // Assert
        $this->assertSame(['full-batch', 'under-filled'], $order);
    }

    public function testQueuesOfEqualPriorityAreOrderedByMessageCountDescending(): void
    {
        // Arrange
        $strategy = $this->createStrategy(
            [
                $this->createQueuemetrics('fewer', messageCount: 50),
                $this->createQueuemetrics('most', messageCount: 900),
                $this->createQueuemetrics('middle', messageCount: 300),
            ],
            mode: QueueReadModeEnum::MODE_READ_ORDER->value,
        );

        // Act
        $order = $this->drainQueueNames($strategy, 6);

        // Assert
        $this->assertSame(['most', 'middle', 'fewer'], $order);
    }

    /**
     * MODE_READ_ORDER is the integer 0, and the mode check is a bitmask test `($setting & $mode) === $mode`,
     * which is true for every setting when $mode is 0.
     *
     * @return void
     */
    public function testTheDefaultReadOrderModeNeverChangesAnyPriority(): void
    {
        // Arrange
        $strategy = $this->createStrategy(
            [
                $this->createQueuemetrics('publish.product', messageCount: 100),
                $this->createQueuemetrics('sync.storage', messageCount: 200),
                $this->createQueuemetrics('event', messageCount: 300),
            ],
            mode: QueueReadModeEnum::MODE_READ_ORDER->value,
        );

        // Act
        $order = $this->drainQueueNames($strategy, 6);

        // Assert
        $this->assertSame(
            ['event', 'sync.storage', 'publish.product'],
            $order,
            'With no preference active the order collapses to message count descending.',
        );
    }

    // -----------------------------------------------------------------------------------------
    // Repeat behaviour
    // -----------------------------------------------------------------------------------------

    /**
     * A queue is handed out once per batch of messages waiting on it, so a deep queue claims proportionally
     * more of the worker pool than a shallow one.
     *
     * @dataProvider handOutCountDataProvider
     *
     * @param int $ratio
     * @param int $limitPerQueue
     * @param int $expectedHandOuts
     *
     * @return void
     */
    public function testAQueueIsHandedOutOncePerWaitingBatchUpToThePerQueueLimit(
        int $ratio,
        int $limitPerQueue,
        int $expectedHandOuts
    ): void {
        // Arrange
        $strategy = $this->createStrategy(
            [$this->createQueuemetrics('event', messageCount: 10000, ratio: $ratio)],
            limitPerQueue: $limitPerQueue,
        );

        // Act
        $handOuts = $this->countHandOutsBeforeRescan($strategy);

        // Assert
        $this->assertSame($expectedHandOuts, $handOuts);
    }

    /**
     * These numbers encode current behaviour, not desired behaviour.
     *
     * @return array<string, array<int>>
     */
    public function handOutCountDataProvider(): array
    {
        // ratio (batches waiting), per-queue limit, expected hand outs
        return [
            'one batch waiting' => [1, 10, 2],
            'three batches waiting' => [3, 10, 4],
            'ten batches waiting' => [10, 10, 11],
            'the per-queue limit caps a very deep queue' => [100, 5, 6],
            'a limit of one allows a single repeat' => [100, 1, 2],
        ];
    }

    /**
     * The batch ratio is what makes a deep queue claim more of the pool than a shallow one, so the two must
     * not come out equal.
     *
     * @return void
     */
    public function testADeepQueueIsHandedOutMoreOftenThanAShallowOne(): void
    {
        // Arrange
        $shallowStrategy = $this->createStrategy(
            [$this->createQueuemetrics('shallow', messageCount: 10000, ratio: 1)],
            limitPerQueue: 20,
        );
        $deepStrategy = $this->createStrategy(
            [$this->createQueuemetrics('deep', messageCount: 10000, ratio: 15)],
            limitPerQueue: 20,
        );

        // Act
        $shallowHandOuts = $this->countHandOutsBeforeRescan($shallowStrategy);
        $deepHandOuts = $this->countHandOutsBeforeRescan($deepStrategy);

        // Assert
        $this->assertGreaterThan(
            $shallowHandOuts,
            $deepHandOuts,
            'A queue with fifteen batches waiting must claim more processes than one with a single batch.',
        );
    }

    public function testAPerQueueLimitOfZeroStopsTheQueueRepeating(): void
    {
        // Arrange
        $strategy = $this->createStrategy(
            [
                $this->createQueuemetrics('event', messageCount: 10000, ratio: 100),
                $this->createQueuemetrics('other', messageCount: 9000, ratio: 100),
            ],
            limitPerQueue: 0,
        );

        // Act
        $handedOut = [
            $strategy->getNextQueue()?->getQueueName(),
            $strategy->getNextQueue()?->getQueueName(),
        ];

        // Assert
        $this->assertSame(
            ['event', 'other'],
            $handedOut,
            'A zero limit is the one setting that does suppress the repeat.',
        );
    }

    // -----------------------------------------------------------------------------------------
    // Settings
    // -----------------------------------------------------------------------------------------

    public function testDynamicSettingsPluginsAreAppliedOnEveryRescan(): void
    {
        // Arrange
        $strategy = $this->createStrategy(
            [$this->createQueuemetrics('event', messageCount: 100)],
            mode: QueueReadModeEnum::MODE_PREFER_PUB->value,
        );

        // Act
        // Two full passes over a single-queue scan result means two rescans.
        for ($call = 0; $call < 4; $call++) {
            $strategy->getNextQueue();
        }

        // Assert
        $this->assertGreaterThanOrEqual(
            2,
            $this->settingsPlugin?->getUpdateCallCount() ?? 0,
            'Settings are re-applied per scan so a plugin can steer the worker while it runs.',
        );
    }

    /**
     * Each strategy resolves its own configuration.
     *
     * @return void
     */
    public function testEachStrategyReadsItsOwnConfiguredPerQueueLimit(): void
    {
        // Arrange
        $strategyWithLimitOfZero = $this->createStrategy(
            [$this->createQueuemetrics('event', messageCount: 100, ratio: 100)],
            limitPerQueue: 0,
            useSettingsPlugin: false,
        );
        $strategyWithHighLimit = $this->createStrategy(
            [$this->createQueuemetrics('event', messageCount: 100, ratio: 100)],
            limitPerQueue: 6,
            useSettingsPlugin: false,
        );

        // Act
        $handOutsWithLimitOfZero = $this->countHandOutsBeforeRescan($strategyWithLimitOfZero);
        $handOutsWithHighLimit = $this->countHandOutsBeforeRescan($strategyWithHighLimit);

        // Assert
        $this->assertSame(1, $handOutsWithLimitOfZero, 'A limit of zero allows no repeat.');
        $this->assertSame(7, $handOutsWithHighLimit, 'A limit of six allows six repeats.');
    }

    // -----------------------------------------------------------------------------------------
    // Scanning
    // -----------------------------------------------------------------------------------------

    public function testAnEmptyScanYieldsNoQueue(): void
    {
        // Arrange
        $strategy = $this->createStrategy([]);

        // Act
        $queuemetrics = $strategy->getNextQueue();

        // Assert
        $this->assertNull($queuemetrics);
    }

    public function testTheIgnoreEmptyScanCooldownflagIsForwardedToTheScanner(): void
    {
        // Arrange
        $queueScannerMock = $this->createMock(QueueScannerInterface::class);
        $queueScannerMock
            ->expects($this->once())
            ->method('scanQueues')
            ->with([], 5, true)
            ->willReturn(new ArrayObject());

        $strategy = new DynamicOrderQueueProcessingStrategy(
            $queueScannerMock,
            $this->createMock(WorkerLoggerInterface::class),
            $this->createQueueConfigMock(),
            [],
        );

        // Act
        $strategy->getNextQueue(true);

        // Assert
        // Handled by the `with()` expectation above.
    }

    /**
     * @var int
     */
    protected int $scanCount = 0;

    /**
     * @var \SprykerTest\Zed\Queue\Helper\FakeDynamicSettingsUpdaterPlugin|null
     */
    protected ?FakeDynamicSettingsUpdaterPlugin $settingsPlugin = null;

    /**
     * @param array<\Spryker\Zed\Queue\Business\Queue\QueueMetrics> $queuemetrics
     * @param int $mode
     * @param int $limitPerQueue
     * @param bool $useSettingsPlugin
     *
     * @return \Spryker\Zed\Queue\Business\Strategy\DynamicOrderQueueProcessingStrategy
     */
    protected function createStrategy(
        array $queuemetrics,
        int $mode = 0,
        int $limitPerQueue = self::LIMIT_PER_QUEUE,
        bool $useSettingsPlugin = true
    ): DynamicOrderQueueProcessingStrategy {
        $queueScannerMock = $this->createMock(QueueScannerInterface::class);
        $queueScannerMock
            ->method('scanQueues')
            ->willReturnCallback(function () use ($queuemetrics): ArrayObject {
                $this->scanCount++;

                // A fresh copy per scan: the strategy sorts and re-prioritises in place.
                $result = new ArrayObject();
                foreach ($queuemetrics as $queuemetric) {
                    $result->append(clone $queuemetric);
                }

                return $result;
            });

        $plugins = [];
        if ($useSettingsPlugin) {
            $this->settingsPlugin = new FakeDynamicSettingsUpdaterPlugin(
                $mode,
                static::BIG_QUEUE_THRESHOLD_BATCHES,
                $limitPerQueue,
            );
            $plugins[] = $this->settingsPlugin;
        }

        return new DynamicOrderQueueProcessingStrategy(
            $queueScannerMock,
            $this->createMock(WorkerLoggerInterface::class),
            $this->createQueueConfigMock($mode, $limitPerQueue),
            $plugins,
        );
    }

    protected function createQueueConfigMock(int $mode = 0, int $limitPerQueue = self::LIMIT_PER_QUEUE): QueueConfig|MockObject
    {
        $queueConfigMock = $this->createMock(QueueConfig::class);
        $queueConfigMock->method('getQueueProcessingWorkerDynamicMode')->willReturn($mode);
        $queueConfigMock->method('getQueueProcessingBigQueueThresholdBatchesAmount')
            ->willReturn(static::BIG_QUEUE_THRESHOLD_BATCHES);
        $queueConfigMock->method('getProcessingLimitOfProcessesPerQueue')->willReturn($limitPerQueue);

        return $queueConfigMock;
    }

    protected function createQueuemetrics(
        string $queueName,
        int $messageCount = 100,
        int $ratio = 1,
        int $batchSize = 1
    ): QueueMetrics {
        return (new QueueMetrics())
            ->setQueueName($queueName)
            ->setStoreName('DE')
            ->setRegionName('EU')
            ->setMessageCount($messageCount)
            ->setBatchSize($batchSize)
            ->setMessageToChunkSizeRatio($ratio)
            ->setPriority(0);
    }

    /**
     * Returns the distinct queue names in the order the strategy first hands them out.
     *
     * @param \Spryker\Zed\Queue\Business\Strategy\DynamicOrderQueueProcessingStrategy $strategy
     * @param int $calls
     *
     * @return array<string>
     */
    protected function drainQueueNames(DynamicOrderQueueProcessingStrategy $strategy, int $calls): array
    {
        $seen = [];

        for ($call = 0; $call < $calls; $call++) {
            $queueName = $strategy->getNextQueue()?->getQueueName();
            if ($queueName !== null && !in_array($queueName, $seen, true)) {
                $seen[] = $queueName;
            }
        }

        return $seen;
    }

    /**
     * Counts how many times the strategy hands out a queue before it has to rescan.
     *
     * @param \Spryker\Zed\Queue\Business\Strategy\DynamicOrderQueueProcessingStrategy $strategy
     *
     * @return int
     */
    protected function countHandOutsBeforeRescan(DynamicOrderQueueProcessingStrategy $strategy): int
    {
        $scanCountBefore = $this->scanCount;
        $calls = 0;

        while ($calls < 200) {
            $strategy->getNextQueue();
            $calls++;

            // The first call performs the initial scan; the round is over when a second one runs.
            if ($this->scanCount > $scanCountBefore + 1) {
                break;
            }
        }

        if ($calls >= 200) {
            $this->fail('The strategy never rescanned within 200 calls; the hand-out count is meaningless.');
        }

        // The call that triggered the rescan belongs to the next round, not this one.
        return $calls - 1;
    }
}
