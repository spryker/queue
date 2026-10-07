<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Business\SystemResources;

use Codeception\Test\Unit;
use RuntimeException;
use Spryker\Zed\Queue\Business\SystemResources\SystemFreeMemoryReaderInterface;
use Spryker\Zed\Queue\Business\SystemResources\SystemResourcesManager;
use Spryker\Zed\Queue\QueueConfig;

/**
 * Auto-generated group annotations
 *
 * @group SprykerTest
 * @group Zed
 * @group Queue
 * @group Business
 * @group SystemResources
 * @group SystemResourcesManagerTest
 * Add your own group annotations below this line
 */
class SystemResourcesManagerTest extends Unit
{
    /**
     * @var \SprykerTest\Zed\Queue\QueueBusinessTester
     */
    protected $tester;

    /**
     * @var int
     */
    protected const FREE_MEMORY_BUFFER_MB = 350;

    /**
     * @dataProvider enoughResourcesDataProvider
     *
     * @param int $freeMemoryMb
     * @param bool $expected
     *
     * @return void
     */
    public function testEnoughResourcesComparesFreeMemoryAgainstTheBuffer(int $freeMemoryMb, bool $expected): void
    {
        // Arrange
        $systemResourcesManager = $this->createSystemResourcesManager($freeMemoryMb);

        // Act
        $hasEnoughResources = $systemResourcesManager->enoughResources(true);

        // Assert
        $this->assertSame($expected, $hasEnoughResources);
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function enoughResourcesDataProvider(): array
    {
        return [
            'far below the buffer suppresses spawning' => [10, false],
            'just below the buffer suppresses spawning' => [static::FREE_MEMORY_BUFFER_MB - 1, false],
            'exactly at the buffer suppresses spawning' => [static::FREE_MEMORY_BUFFER_MB, false],
            'just above the buffer permits spawning' => [static::FREE_MEMORY_BUFFER_MB + 1, true],
            'far above the buffer permits spawning' => [8192, true],
        ];
    }

    /**
     * Free memory of 0 means the reader could not work out the answer, not that the machine is out of
     * memory.
     *
     * @return void
     */
    public function testUndetectableFreeMemoryThrowsWhenTheWorkerIsConfiguredNotToIgnoreIt(): void
    {
        // Arrange
        $systemResourcesManager = $this->createSystemResourcesManager(0);

        // Assert
        $this->expectException(runtimeException::class);
        $this->expectExceptionMessage('Could not detect free memory');

        // Act
        $systemResourcesManager->enoughResources(false);
    }

    public function testUndetectableFreeMemoryIsTreatedAsNotEnoughWhenIgnoringIsEnabled(): void
    {
        // Arrange
        $systemResourcesManager = $this->createSystemResourcesManager(0);

        // Act
        $hasEnoughResources = $systemResourcesManager->enoughResources(true);

        // Assert
        $this->assertFalse(
            $hasEnoughResources,
            'Ignoring the read failure avoids the exception but still leaves 0 below the buffer, so '
            . 'the worker stops spawning rather than carrying on blind.',
        );
    }

    public function testTheConfiguredReadTimeoutIsPassedToTheMemoryReader(): void
    {
        // Arrange
        $memoryReadTimeout = 7;

        $systemFreeMemoryReaderMock = $this->createMock(SystemFreeMemoryReaderInterface::class);
        $systemFreeMemoryReaderMock
            ->expects($this->once())
            ->method('getFreeMemory')
            ->with($memoryReadTimeout)
            ->willReturn(1024);

        $queueConfigMock = $this->createMock(QueueConfig::class);
        $queueConfigMock->method('memoryReadProcessTimeout')->willReturn($memoryReadTimeout);
        $queueConfigMock->method('getFreeMemoryBuffer')->willReturn(static::FREE_MEMORY_BUFFER_MB);

        $systemResourcesManager = new SystemResourcesManager($queueConfigMock, $systemFreeMemoryReaderMock);

        // Act
        $systemResourcesManager->enoughResources(true);

        // Assert
        // Handled by the `with()` expectation above.
    }

    public function testGetFreeMemoryPassesTheGivenTimeoutStraightThrough(): void
    {
        // Arrange
        $systemFreeMemoryReaderMock = $this->createMock(SystemFreeMemoryReaderInterface::class);
        $systemFreeMemoryReaderMock
            ->expects($this->once())
            ->method('getFreeMemory')
            ->with(3)
            ->willReturn(512);

        $systemResourcesManager = new SystemResourcesManager(
            $this->createMock(QueueConfig::class),
            $systemFreeMemoryReaderMock,
        );

        // Act
        $freeMemory = $systemResourcesManager->getFreeMemory(3);

        // Assert
        $this->assertSame(512, $freeMemory);
    }

    /**
     * The baseline is per manager, so a worker measures its own growth.
     *
     * @return void
     */
    public function testTheMemoryBaselineIsPerInstance(): void
    {
        // Arrange
        $firstManager = $this->createSystemResourcesManager(1024);
        $firstManager->getOwnPeakMemoryGrowth();

        // Grow the peak so a manager anchored earlier would report growth.
        $peakBeforeBallast = memory_get_peak_usage(true);
        $ballast = str_repeat('x', 64 * 1024 * 1024);

        // memory_get_peak_usage(true) moves in allocator chunks, not per allocation. If the ballast
        // did not move it, this test proves nothing about the baseline, so say so rather than
        // passing quietly.
        $this->assertGreaterThan(
            $peakBeforeBallast,
            memory_get_peak_usage(true),
            'The ballast must move the real peak for this test to discriminate.',
        );

        // Act
        $secondManager = $this->createSystemResourcesManager(1024);
        $growthOfFreshManager = $secondManager->getOwnPeakMemoryGrowth();

        // Assert
        $this->assertSame(
            0,
            $growthOfFreshManager,
            'A manager created after the allocation anchors on the current peak, so it sees no growth.',
        );
        $this->assertNotSame('', $ballast);
    }

    /**
     * Exercises the growth branch, including the division by the baseline.
     *
     * @return void
     */
    public function testGrowthAfterTheBaselineIsReportedAsAPercentage(): void
    {
        // Arrange
        $systemResourcesManager = $this->createSystemResourcesManager(1024);
        $systemResourcesManager->getOwnPeakMemoryGrowth();

        // Act
        $ballast = str_repeat('y', 64 * 1024 * 1024);
        $growth = $systemResourcesManager->getOwnPeakMemoryGrowth();

        // Assert
        $this->assertGreaterThan(
            0,
            $growth,
            'Allocating 64 MB after the baseline must show up as a positive growth factor.',
        );
        $this->assertNotSame('', $ballast);
    }

    protected function createSystemResourcesManager(int $freeMemoryMb): SystemResourcesManager
    {
        $systemFreeMemoryReaderMock = $this->createMock(SystemFreeMemoryReaderInterface::class);
        $systemFreeMemoryReaderMock->method('getFreeMemory')->willReturn($freeMemoryMb);

        $queueConfigMock = $this->createMock(QueueConfig::class);
        $queueConfigMock->method('getFreeMemoryBuffer')->willReturn(static::FREE_MEMORY_BUFFER_MB);
        $queueConfigMock->method('memoryReadProcessTimeout')->willReturn(5);

        return new SystemResourcesManager($queueConfigMock, $systemFreeMemoryReaderMock);
    }
}
