<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Business\Scanner;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\QueueMetricsRequestTransfer;
use Generated\Shared\Transfer\QueueMetricsResponseTransfer;
use Generated\Shared\Transfer\StoreCollectionTransfer;
use Generated\Shared\Transfer\StoreTransfer;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;
use Spryker\Client\Queue\QueueClientInterface;
use Spryker\Zed\Event\Communication\Plugin\Queue\EventQueueMessageProcessorPlugin;
use Spryker\Zed\Queue\Business\Logger\WorkerLogger;
use Spryker\Zed\Queue\Business\Reader\QueueConfigReader;
use Spryker\Zed\Queue\Business\Scanner\QueueScanner;
use Spryker\Zed\Queue\QueueConfig;
use Spryker\Zed\QueueExtension\Dependency\Plugin\QueueMetricsReaderPluginInterface;
use Spryker\Zed\Store\Business\StoreFacadeInterface;
use SprykerTest\Zed\Queue\Helper\FakeQueuemetricsReaderPlugin;
use SprykerTest\Zed\Queue\Helper\QueueScannerStateHelper;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Auto-generated group annotations
 *
 * @group SprykerTest
 * @group Zed
 * @group Queue
 * @group Business
 * @group Scanner
 * @group QueueScannerTest
 * Add your own group annotations below this line
 */
class QueueScannerTest extends Unit
{
    /**
     * @var \SprykerTest\Zed\Queue\QueueBusinessTester
     */
    protected $tester;

    /**
     * QueueScanner caches the store list, the dynamic-store flag and the sync-queue name map in static
     * properties, which outlive any single scanner instance.
     *
     * @return void
     */
    protected function _before(): void
    {
        parent::_before();

        QueueScannerStateHelper::reset();
    }

    /**
     * @dataProvider scanQueuesDataProvider
     *
     * @param array $queueMessages
     * @param int $lastScanAtDelta
     * @param array $expectedMessages
     * @param array|null $stores
     *
     * @return void
     */
    public function testScanQueues(
        array $queueMessages,
        int $lastScanAtDelta,
        array $expectedMessages,
        ?array $stores = []
    ): void {
        // Arrange
        $queueScanner = (new QueueScanner(
            $this->getStoreFacadeMock(),
            ['event'],
            ['event' => new EventQueueMessageProcessorPlugin()],
            [$this->createQueueMetricExpanderMock($queueMessages)],
            new WorkerLogger(new ConsoleOutput()),
            new QueueConfig(),
            new QueueConfigReader(new QueueConfig()),
        ));

        // Act
        $firstQueueResult = $queueScanner->scanQueues($stores);

        $reflection = new ReflectionClass($queueScanner);
        $property = $reflection->getProperty('lastScanAt');
        $property->setValue($queueScanner, microtime(true) - $lastScanAtDelta);

        $secondQueueResult = $queueScanner->scanQueues($stores);

        // Assert
        $this->assertCount(count($expectedMessages['firstCall']), $firstQueueResult);
        $this->assertCount(count($expectedMessages['secondCall']), $secondQueueResult);

        if ($firstQueueResult->count()) {
            /** @var \Generated\Shared\Transfer\QueueTransfer $quoteTransfer */
            $quoteTransfer = $firstQueueResult->getIterator()->current();
            $this->assertSame($expectedMessages['firstCall'][0]['storeName'], $quoteTransfer->getStoreName());
            $this->assertSame($expectedMessages['firstCall'][0]['messageCount'], $quoteTransfer->getMessageCount());
        }

        if ($secondQueueResult->count()) {
            /** @var \Generated\Shared\Transfer\QueueTransfer $quoteTransfer */
            $quoteTransfer = $secondQueueResult->getIterator()->current();
            $this->assertSame($expectedMessages['secondCall'][0]['storeName'], $quoteTransfer->getStoreName());
            $this->assertSame($expectedMessages['secondCall'][0]['messageCount'], $quoteTransfer->getMessageCount());
        }
    }

    public function testScanQueuesUsesChunkSizeConfigOverrideInsteadOfPluginValue(): void
    {
        // Arrange
        $queueConfig = new class extends QueueConfig {
            /**
             * @return array<string, int>
             */
            public function getQueueMessageChunkSizeMap(): array
            {
                return ['event' => 5];
            }
        };

        $queueScanner = new QueueScanner(
            $this->getStoreFacadeMock(),
            ['event'],
            ['event' => new EventQueueMessageProcessorPlugin()],
            [$this->createQueueMetricExpanderMock(['AT' => 3])],
            new WorkerLogger(new ConsoleOutput()),
            $queueConfig,
            new QueueConfigReader($queueConfig),
        );

        // Act
        $queueMetrics = $queueScanner->scanQueues(['AT']);

        // Assert
        $this->assertCount(1, $queueMetrics);
        /** @var \Spryker\Zed\Queue\Business\Queue\QueueMetrics $queueMetric */
        $queueMetric = $queueMetrics->getIterator()->current();
        $this->assertSame(5, $queueMetric->getBatchSize());
    }

    // -----------------------------------------------------------------------------------------
    // What one scan costs
    // -----------------------------------------------------------------------------------------

    /**
     * The cost of a scan is driven by how many queues are CONFIGURED, not by how many of them actually hold
     * messages: every configured queue is looked up, for every store, on every scan.
     *
     * @return void
     */
    public function testEveryScanCostsOneLookupPerConfiguredQueuePerStore(): void
    {
        // Arrange
        $configuredQueueNames = ['event', 'mail', 'publish.product', 'sync.storage', 'retry'];
        $storeNames = ['DE', 'US'];
        // Only one of the five queues has anything waiting on it.
        $readerPlugin = new FakeQueuemetricsReaderPlugin(['event' => 25]);

        $queueScanner = $this->createQueueScanner($configuredQueueNames, $readerPlugin);

        // Act
        $queuemetrics = $queueScanner->scanQueues($storeNames);

        // Assert
        $this->assertCount(
            count($configuredQueueNames) * count($storeNames),
            $readerPlugin->getReadCallsByKey(),
            'One broker lookup per configured queue per store, regardless of how many are active.',
        );
        $this->assertSame(
            count($configuredQueueNames) * count($storeNames),
            $readerPlugin->getReadCallCount(),
        );
        $this->assertCount(
            count($storeNames),
            $queuemetrics,
            'Only the one active queue is returned, once per store, so the result is far smaller than the cost.',
        );
    }

    public function testScanCostGrowsWithTheConfiguredQueueCountWhileTheResultStaysTheSame(): void
    {
        // Arrange
        $activeQueueName = 'event';
        $smallReaderPlugin = new FakeQueuemetricsReaderPlugin([$activeQueueName => 25]);
        $largeReaderPlugin = new FakeQueuemetricsReaderPlugin([$activeQueueName => 25]);

        $smallSetup = [$activeQueueName, 'mail'];
        $largeSetup = [$activeQueueName, 'mail', 'a', 'b', 'c', 'd', 'e', 'f'];

        // Act
        $smallResult = $this->createQueueScanner($smallSetup, $smallReaderPlugin)->scanQueues(['DE']);
        QueueScannerStateHelper::reset();
        $largeResult = $this->createQueueScanner($largeSetup, $largeReaderPlugin)->scanQueues(['DE']);

        // Assert
        $this->assertCount(1, $smallResult);
        $this->assertCount(1, $largeResult, 'Same single active queue in both setups.');
        $this->assertSame(count($smallSetup), $smallReaderPlugin->getReadCallCount());
        $this->assertSame(
            count($largeSetup),
            $largeReaderPlugin->getReadCallCount(),
            'Four times the configured queues costs four times the lookups for an identical result.',
        );
    }

    public function testAQueueReportingZeroMessagesIsLeftOutOfTheResult(): void
    {
        // Arrange
        $readerPlugin = new FakeQueuemetricsReaderPlugin(['event' => 0, 'mail' => 7]);
        $queueScanner = $this->createQueueScanner(['event', 'mail'], $readerPlugin);

        // Act
        $queuemetrics = $queueScanner->scanQueues(['DE']);

        // Assert
        $this->assertCount(1, $queuemetrics);
        $this->assertSame('mail', $queuemetrics->getIterator()->current()->getQueueName());
    }

    // -----------------------------------------------------------------------------------------
    // Empty-scan cooldown
    // -----------------------------------------------------------------------------------------

    public function testAnEmptyScanIsNotRepeatedUntilTheCooldownHasElapsed(): void
    {
        // Arrange
        $readerPlugin = new FakeQueuemetricsReaderPlugin([]);
        $queueScanner = $this->createQueueScanner(['event'], $readerPlugin);
        $queueScanner->scanQueues(['DE']);
        $lookupsAfterFirstScan = $readerPlugin->getReadCallCount();

        // Act
        $queueScanner->scanQueues(['DE']);

        // Assert
        $this->assertSame(
            $lookupsAfterFirstScan,
            $readerPlugin->getReadCallCount(),
            'A second scan inside the cooldown is served from the cached empty result.',
        );
    }

    public function testAnEmptyScanIsRepeatedOnceTheCooldownHasElapsed(): void
    {
        // Arrange
        $cooldownSeconds = 5;
        $readerPlugin = new FakeQueuemetricsReaderPlugin([]);
        $queueScanner = $this->createQueueScanner(['event'], $readerPlugin);
        $queueScanner->scanQueues(['DE'], $cooldownSeconds);
        $lookupsAfterFirstScan = $readerPlugin->getReadCallCount();

        $this->setLastScanAt($queueScanner, microtime(true) - ($cooldownSeconds + 1));

        // Act
        $queueScanner->scanQueues(['DE'], $cooldownSeconds);

        // Assert
        $this->assertGreaterThan($lookupsAfterFirstScan, $readerPlugin->getReadCallCount());
    }

    /**
     * The cooldown guards idle periods only.
     *
     * @return void
     */
    public function testANonEmptyScanIsFollowedByAnImmediateRescanWithNoThrottleAtAll(): void
    {
        // Arrange
        $readerPlugin = new FakeQueuemetricsReaderPlugin(['event' => 500]);
        $queueScanner = $this->createQueueScanner(['event'], $readerPlugin);
        $queueScanner->scanQueues(['DE']);
        $lookupsAfterFirstScan = $readerPlugin->getReadCallCount();

        // Act
        $queueScanner->scanQueues(['DE']);

        // Assert
        $this->assertSame(
            $lookupsAfterFirstScan * 2,
            $readerPlugin->getReadCallCount(),
            'Under load the cooldown does nothing: back-to-back scans both hit the broker.',
        );
    }

    public function testIgnoreEmptyScanCooldownForcesAFreshScanInsideTheCooldown(): void
    {
        // Arrange
        $readerPlugin = new FakeQueuemetricsReaderPlugin([]);
        $queueScanner = $this->createQueueScanner(['event'], $readerPlugin);
        $queueScanner->scanQueues(['DE']);
        $lookupsAfterFirstScan = $readerPlugin->getReadCallCount();

        // Act
        $queueScanner->scanQueues(['DE'], 5, true);

        // Assert
        $this->assertGreaterThan(
            $lookupsAfterFirstScan,
            $readerPlugin->getReadCallCount(),
            'The stop-when-empty worker needs a truthful answer, so it bypasses the cached empty result.',
        );
    }

    // -----------------------------------------------------------------------------------------
    // Reduced synchronisation-queue scanning
    // -----------------------------------------------------------------------------------------

    public function testSyncQueuesAreSkippedBetweenIntervalsWhenReducedScanningIsEnabled(): void
    {
        // Arrange
        $syncQueueScanInterval = 10;
        $queueConfig = $this->createReducedSyncScanConfig($syncQueueScanInterval);
        $readerPlugin = new FakeQueuemetricsReaderPlugin(['event' => 5]);
        $queueScanner = $this->createQueueScanner(
            ['event', 'sync.storage'],
            $readerPlugin,
            $queueConfig,
        );

        // Act
        // First scan includes sync queues; the second falls inside the interval and skips them.
        $queueScanner->scanQueues(['DE']);
        $readerPlugin->resetCallCounts();
        $queueScanner->scanQueues(['DE']);

        // Assert
        $this->assertArrayHasKey('event:DE', $readerPlugin->getReadCallsByKey());
        $this->assertArrayNotHasKey(
            'sync.storage:DE',
            $readerPlugin->getReadCallsByKey(),
            'Synchronisation queues are not expected to receive messages, so they are polled less often.',
        );
    }

    public function testSyncQueuesAreScannedOnTheFirstScan(): void
    {
        // Arrange
        $queueConfig = $this->createReducedSyncScanConfig(10);
        $readerPlugin = new FakeQueuemetricsReaderPlugin(['event' => 5]);
        $queueScanner = $this->createQueueScanner(['event', 'sync.storage'], $readerPlugin, $queueConfig);

        // Act
        $queueScanner->scanQueues(['DE']);

        // Assert
        $this->assertArrayHasKey('sync.storage:DE', $readerPlugin->getReadCallsByKey());
    }

    public function testSyncQueuesKeepBeingScannedWhileTheyStillHoldMessages(): void
    {
        // Arrange
        $queueConfig = $this->createReducedSyncScanConfig(10);
        $readerPlugin = new FakeQueuemetricsReaderPlugin(['event' => 5, 'sync.storage' => 40]);
        $queueScanner = $this->createQueueScanner(['event', 'sync.storage'], $readerPlugin, $queueConfig);

        // Act
        $queueScanner->scanQueues(['DE']);
        $readerPlugin->resetCallCounts();
        $queueScanner->scanQueues(['DE']);

        // Assert
        $this->assertArrayHasKey(
            'sync.storage:DE',
            $readerPlugin->getReadCallsByKey(),
            'A sync queue that had messages last time is polled again immediately.',
        );
    }

    public function testAllQueuesAreScannedWhenReducedSyncScanningIsDisabled(): void
    {
        // Arrange
        $readerPlugin = new FakeQueuemetricsReaderPlugin(['event' => 5]);
        $queueScanner = $this->createQueueScanner(['event', 'sync.storage'], $readerPlugin);

        // Act
        $queueScanner->scanQueues(['DE']);
        $readerPlugin->resetCallCounts();
        $queueScanner->scanQueues(['DE']);

        // Assert
        $this->assertArrayHasKey('sync.storage:DE', $readerPlugin->getReadCallsByKey());
    }

    // -----------------------------------------------------------------------------------------
    // Message-to-chunk ratio
    // -----------------------------------------------------------------------------------------

    /**
     * @dataProvider messageToChunkRatioDataProvider
     *
     * @param int $messageCount
     * @param int $chunkSize
     * @param int $expectedRatio
     *
     * @return void
     */
    public function testTheMessageToChunkRatioIsTheRoundedNumberOfWaitingBatches(
        int $messageCount,
        int $chunkSize,
        int $expectedRatio
    ): void {
        // Arrange
        $queueConfig = new class ($chunkSize) extends QueueConfig {
            public function __construct(protected int $chunkSize)
            {
            }

            /**
             * @return array<string, int>
             */
            public function getQueueMessageChunkSizeMap(): array
            {
                return ['event' => $this->chunkSize];
            }
        };

        $queueScanner = $this->createQueueScanner(
            ['event'],
            new FakeQueuemetricsReaderPlugin(['event' => $messageCount]),
            $queueConfig,
        );

        // Act
        $queuemetrics = $queueScanner->scanQueues(['DE']);

        // Assert
        /** @var \Spryker\Zed\Queue\Business\Queue\QueueMetrics $queuemetric */
        $queuemetric = $queuemetrics->getIterator()->current();
        $this->assertSame($expectedRatio, $queuemetric->getMessageToChunkSizeRatio());
        $this->assertSame($chunkSize, $queuemetric->getBatchSize());
        $this->assertSame($messageCount, $queuemetric->getMessageCount());
    }

    /**
     * @return array<string, array<int>>
     */
    public function messageToChunkRatioDataProvider(): array
    {
        // message count, chunk size, expected ratio
        return [
            'exactly one batch' => [450, 450, 1],
            'exactly ten batches' => [4500, 450, 10],
            'rounds down below the half batch' => [500, 450, 1],
            'rounds up above the half batch' => [700, 450, 2],
            'less than half a batch rounds down to zero' => [10, 450, 0],
        ];
    }

    public function testAQueueWithNoConfiguredChunkSizeReportsARatioOfOneAndNoBatchSize(): void
    {
        // Arrange
        $queueScanner = $this->createQueueScanner(
            ['event'],
            new FakeQueuemetricsReaderPlugin(['event' => 1234]),
        );

        // Act
        $queuemetrics = $queueScanner->scanQueues(['DE']);

        // Assert
        /** @var \Spryker\Zed\Queue\Business\Queue\QueueMetrics $queuemetric */
        $queuemetric = $queuemetrics->getIterator()->current();
        $this->assertSame(1, $queuemetric->getMessageToChunkSizeRatio(), 'The count divides by itself.');
        $this->assertSame(0, $queuemetric->getBatchSize());
    }

    // -----------------------------------------------------------------------------------------
    // Store resolution
    // -----------------------------------------------------------------------------------------

    /**
     * Whether a scan is per-store or per-region is decided by SPRYKER_DYNAMIC_STORE_MODE, which is set in
     * the Docker cli container but not on a bare host.
     *
     * @return void
     */
    public function testWithDynamicStoreDisabledEachConfiguredStoreIsScannedSeparately(): void
    {
        // Arrange
        $readerPlugin = new FakeQueuemetricsReaderPlugin(['event' => 5]);
        $queueScanner = $this->createQueueScanner(
            ['event'],
            $readerPlugin,
            $this->createDynamicStoreConfig(false),
        );

        // Act
        $queuemetrics = $queueScanner->scanQueues();

        // Assert
        $this->assertSame(
            ['event:DE' => 1, 'event:US' => 1],
            $readerPlugin->getReadCallsByKey(),
            'The store facade supplies DE and US, and the queue is looked up once for each.',
        );
        $this->assertCount(2, $queuemetrics);
    }

    public function testWithDynamicStoreEnabledTheQueueIsScannedOncePerRegionInsteadOfPerStore(): void
    {
        // Arrange
        $storeFacadeMock = $this->getStoreFacadeMock();
        $storeFacadeMock->expects($this->never())->method('getStoreCollection');

        $readerPlugin = new FakeQueuemetricsReaderPlugin(['event' => 5]);
        $queueScanner = $this->createQueueScanner(
            ['event'],
            $readerPlugin,
            $this->createDynamicStoreConfig(true),
            $storeFacadeMock,
        );

        // Act
        $queuemetrics = $queueScanner->scanQueues();

        // Assert
        $this->assertSame(
            ['event' => 1],
            $readerPlugin->getReadCallsByKey(),
            'In dynamic-store mode the store list is not consulted at all: one lookup, no store name.',
        );
        $this->assertCount(1, $queuemetrics);
        $this->assertNull($queuemetrics->getIterator()->current()->getStoreName());
    }

    public function testAnExplicitStoreListOverridesDynamicStoreMode(): void
    {
        // Arrange
        $readerPlugin = new FakeQueuemetricsReaderPlugin(['event' => 5]);
        $queueScanner = $this->createQueueScanner(
            ['event'],
            $readerPlugin,
            $this->createDynamicStoreConfig(true),
        );

        // Act
        $queueScanner->scanQueues(['AT']);

        // Assert
        $this->assertSame(
            ['event:AT' => 1],
            $readerPlugin->getReadCallsByKey(),
            'Passing stores explicitly takes precedence over the dynamic-store branch.',
        );
    }

    public function testTheStoreListIsResolvedOnceAndCachedForTheWholeProcess(): void
    {
        // Arrange
        $storeFacadeMock = $this->getStoreFacadeMock();
        $storeFacadeMock->expects($this->once())->method('getStoreCollection');

        $readerPlugin = new FakeQueuemetricsReaderPlugin(['event' => 5]);
        $queueScanner = $this->createQueueScanner(
            ['event'],
            $readerPlugin,
            $this->createDynamicStoreConfig(false),
            $storeFacadeMock,
        );

        // Act
        $queueScanner->scanQueues();
        $queueScanner->scanQueues();

        // Assert
        // Handled by the `once()` expectation: the store list lives in a static property, which is
        // why QueueScannerStateHelper::reset() runs before every test in this class.
    }

    protected function createDynamicStoreConfig(bool $isDynamicStoreEnabled): QueueConfig
    {
        return new class ($isDynamicStoreEnabled) extends QueueConfig {
            public function __construct(protected bool $isDynamicStoreEnabled)
            {
            }

            public function isDynamicStoreEnabled(): bool
            {
                return $this->isDynamicStoreEnabled;
            }
        };
    }

    /**
     * @param array<string> $queueNames
     * @param \SprykerTest\Zed\Queue\Helper\FakeQueuemetricsReaderPlugin $readerPlugin
     * @param \Spryker\Zed\Queue\QueueConfig|null $queueConfig
     * @param \Spryker\Zed\Store\Business\StoreFacadeInterface|null $storeFacade
     *
     * @return \Spryker\Zed\Queue\Business\Scanner\QueueScanner
     */
    protected function createQueueScanner(
        array $queueNames,
        FakeQueuemetricsReaderPlugin $readerPlugin,
        ?QueueConfig $queueConfig = null,
        ?StoreFacadeInterface $storeFacade = null
    ): QueueScanner {
        $queueConfig = $queueConfig ?? new QueueConfig();

        return new QueueScanner(
            $storeFacade ?? $this->getStoreFacadeMock(),
            $queueNames,
            [],
            [$readerPlugin],
            new WorkerLogger(new ConsoleOutput()),
            $queueConfig,
            new QueueConfigReader($queueConfig),
        );
    }

    protected function createReducedSyncScanConfig(int $syncQueueScanInterval): QueueConfig
    {
        return new class ($syncQueueScanInterval) extends QueueConfig {
            public function __construct(protected int $syncQueueScanInterval)
            {
            }

            public function isReducedSyncQueueScanEnabled(): bool
            {
                return true;
            }

            public function getSyncQueueScanInterval(): int
            {
                return $this->syncQueueScanInterval;
            }
        };
    }

    protected function setLastScanAt(QueueScanner $queueScanner, float $lastScanAt): void
    {
        $reflectionProperty = (new ReflectionClass($queueScanner))->getProperty('lastScanAt');
        $reflectionProperty->setValue($queueScanner, $lastScanAt);
    }

    public function getQueueClientMock(): QueueClientInterface|MockObject
    {
        $queueClientMock = $this->getMockBuilder(QueueClientInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        return $queueClientMock;
    }

    public function getStoreFacadeMock(): StoreFacadeInterface|MockObject
    {
        $storeFacadeMock = $this->getMockBuilder(StoreFacadeInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $storeFacadeMock->method('getStoreCollection')
            ->willReturn(
                (new StoreCollectionTransfer())
                    ->addStore((new StoreTransfer())->setName('DE'))
                    ->addStore((new StoreTransfer())->setName('US')),
            );

        return $storeFacadeMock;
    }

    /**
     * @param array<string, int> $queueMessages
     *
     * @return \Spryker\Zed\QueueExtension\Dependency\Plugin\QueueMetricsReaderPluginInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    public function createQueueMetricExpanderMock(array $queueMessages): QueueMetricsReaderPluginInterface|MockObject
    {
        $queueMetricsExpanderMock = $this->getMockBuilder(QueueMetricsReaderPluginInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $queueMetricsExpanderMock->method('read')
            ->willReturnCallback(function (QueueMetricsRequestTransfer $queueMetricsRequestTransfer) use ($queueMessages) {
                $storeName = $queueMetricsRequestTransfer->getStoreName();
                $messageCount = $queueMessages[$storeName] ?? 0;

                return (new QueueMetricsResponseTransfer())->setMessageCount($messageCount);
            });
        $queueMetricsExpanderMock->method('isApplicable')->willReturn(true);

        return $queueMetricsExpanderMock;
    }

    /**
     * @return array<string, mixed>
     */
    protected function scanQueuesDataProvider(): array
    {
        return [
            'check when there are no messages in the queue' => [
                'queueMessages' => [
                    'DE' => 0,
                ],
                'lastScanAtDelta' => 10,
                'expectedMessages' => [
                    'firstCall' => [],
                    'secondCall' => [],
                ],
            ],
            'check when there are no messages in the queue for DE store and zero delay' => [
                'queueMessages' => [
                    'DE' => 0,
                ],
                'lastScanAtDelta' => 0,
                'expectedMessages' => [
                    'firstCall' => [],
                    'secondCall' => [],
                ],
            ],
            'check when there are messages in the queue for AT store and store is set as param' => [
                'queueMessages' => [
                    'DE' => 0,
                    'AT' => 3,
                ],
                'lastScanAtDelta' => 10,
                'expectedMessages' => [
                    'firstCall' => [['storeName' => 'AT', 'messageCount' => 3]],
                    'secondCall' => [['storeName' => 'AT', 'messageCount' => 3]],
                ],
                'stores' => ['AT'],
            ],
        ];
    }
}
