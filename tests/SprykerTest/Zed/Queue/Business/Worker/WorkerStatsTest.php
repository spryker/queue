<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Business\Worker;

use Codeception\Test\Unit;
use Spryker\Zed\Queue\Business\Worker\WorkerStats;

/**
 * Auto-generated group annotations
 *
 * @group SprykerTest
 * @group Zed
 * @group Queue
 * @group Business
 * @group Worker
 * @group WorkerStatsTest
 * Add your own group annotations below this line
 */
class WorkerStatsTest extends Unit
{
    /**
     * @var \SprykerTest\Zed\Queue\QueueBusinessTester
     */
    protected $tester;

    public function testCountersStartEmpty(): void
    {
        // Act
        $stats = (new WorkerStats())->getStats();

        // Assert
        $this->assertSame(
            ['queues' => [], 'locations' => [], 'errors' => [], 'cycles' => [], 'proc' => [], 'metrics' => []],
            $stats,
        );
    }

    public function testEachCounterAccumulatesUnderItsOwnKey(): void
    {
        // Arrange
        $workerStats = new WorkerStats();

        // Act
        $workerStats->addCycle()->addCycle()
            ->addSkipCycle()
            ->addEmptyCycle()
            ->addNoSlotCycle()
            ->addNoMemoryCycle()
            ->addCooldownCycle()
            ->addQueueQuantity('event')->addQueueQuantity('event')
            ->addLocationQuantity('DE')
            ->addErrorQuantity('RMQ-connection');

        // Assert
        $stats = $workerStats->getStats();
        $this->assertSame(
            ['cycles' => 2, 'skip-cycle' => 1, 'empty' => 1, 'no_slot' => 1, 'no_mem' => 1, 'cooldown' => 1],
            $stats['cycles'],
        );
        $this->assertSame(['event' => 2], $stats['queues']);
        $this->assertSame(['DE' => 1], $stats['locations']);
        $this->assertSame(['RMQ-connection' => 1], $stats['errors']);
    }

    public function testProcessCountersIncrementByDefaultButCanBeSetOutright(): void
    {
        // Arrange
        $workerStats = new WorkerStats();

        // Act
        $workerStats->addProcQuantity('new')->addProcQuantity('new');
        $workerStats->addProcQuantity('max', 7);
        $workerStats->addProcQuantity('max', 3);

        // Assert
        $stats = $workerStats->getStats();
        $this->assertSame(2, $stats['proc']['new']);
        $this->assertSame(
            3,
            $stats['proc']['max'],
            'An explicit value overwrites rather than accumulates, which is how the peak is tracked.',
        );
    }

    /**
     * @dataProvider successRateDataProvider
     *
     * @param int|null $new
     * @param int|null $failed
     * @param int $expected
     *
     * @return void
     */
    public function testSuccessRate(?int $new, ?int $failed, int $expected): void
    {
        // Arrange
        $workerStats = new WorkerStats();
        if ($new !== null) {
            $workerStats->addProcQuantity('new', $new);
        }
        if ($failed !== null) {
            $workerStats->addProcQuantity('failed', $failed);
        }

        // Act
        $successRate = $workerStats->getSuccessRate();

        // Assert
        $this->assertSame($expected, $successRate);
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function successRateDataProvider(): array
    {
        return [
            'no processes started reads as 100 percent' => [null, null, 100],
            'everything succeeded' => [10, null, 100],
            'one in ten failed' => [10, 1, 90],
            'everything failed' => [10, 10, 0],
            'more failures than starts goes negative' => [2, 4, -100],
        ];
    }

    /**
     * @dataProvider failedEveryStartedProcessDataProvider
     */
    public function testHasFailedEveryStartedProcess(?int $new, ?int $failed, bool $expected): void
    {
        // Arrange
        $workerStats = new WorkerStats();
        if ($new !== null) {
            $workerStats->addProcQuantity('new', $new);
        }
        if ($failed !== null) {
            $workerStats->addProcQuantity('failed', $failed);
        }

        // Act & Assert
        $this->assertSame($expected, $workerStats->hasFailedEveryStartedProcess());
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function failedEveryStartedProcessDataProvider(): array
    {
        return [
            'nothing started is not a failed run' => [null, null, false],
            'nothing started but failures recorded' => [null, 3, false],
            'some failed' => [10, 3, false],
            'all failed' => [10, 10, true],
            'more failures than starts still counts' => [2, 5, true],
        ];
    }

    public function testCycleEfficiencyReportsTheShareOfCyclesThatDidRealWork(): void
    {
        // Arrange
        $workerStats = new WorkerStats();
        for ($cycle = 0; $cycle < 10; $cycle++) {
            $workerStats->addCycle();
        }
        $workerStats->addSkipCycle()->addSkipCycle()->addNoMemoryCycle();

        // Act
        $cycleEfficiency = $workerStats->getCycleEfficiency();

        // Assert
        $this->assertSame('80.00%', $cycleEfficiency['efficiency']);
        $this->assertSame('20.00%', $cycleEfficiency['skip-cycle']);
        $this->assertSame('10.00%', $cycleEfficiency['no_mem']);
        $this->assertArrayNotHasKey('cycles', $cycleEfficiency, 'The denominator is not itself a row.');
    }

    public function testCycleEfficiencyIsAHundredPercentBeforeAnyCycleHasRun(): void
    {
        // Act
        $cycleEfficiency = (new WorkerStats())->getCycleEfficiency();

        // Assert
        $this->assertSame('100.00%', $cycleEfficiency['efficiency']);
    }

    public function testArbitrarymetricsAreStoredVerbatimAndOverwritten(): void
    {
        // Arrange
        $workerStats = new WorkerStats();

        // Act
        $workerStats->addmetric('mem-growth', 12)->addmetric('mem-growth', 25);

        // Assert
        $this->assertSame(25, $workerStats->getStats()['metrics']['mem-growth']);
    }
}
