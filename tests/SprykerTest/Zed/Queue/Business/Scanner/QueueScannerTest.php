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
        $property->setAccessible(true);
        $property->setValue($queueScanner, microtime(true) - $lastScanAtDelta);

        $secondQueueResult = $queueScanner->scanQueues($stores);

        // Assert
        $this->assertCount(count($expectedMessages['firstCall']), $secondQueueResult);
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

    /**
     * @return \Spryker\Client\Queue\QueueClientInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    public function getQueueClientMock(): QueueClientInterface|MockObject
    {
        $queueClientMock = $this->getMockBuilder(QueueClientInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        return $queueClientMock;
    }

    /**
     * @return \Spryker\Zed\Store\Business\StoreFacadeInterface|\PHPUnit\Framework\MockObject\MockObject
     */
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
     * @param array $queueMessages
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
