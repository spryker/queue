<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Business\Worker;

use Codeception\Test\Unit;
use SprykerTest\Zed\Queue\Helper\TestableProcessMemoryTracker;

/**
 * Auto-generated group annotations
 *
 * @group SprykerTest
 * @group Zed
 * @group Queue
 * @group Business
 * @group Worker
 * @group ProcessMemoryTrackerTest
 * Add your own group annotations below this line
 */
class ProcessMemoryTrackerTest extends Unit
{
    /**
     * @var \SprykerTest\Zed\Queue\QueueBusinessTester
     */
    protected $tester;

    /**
     * @var int
     */
    protected const PID = 4242;

    /**
     * @var int
     */
    protected const ONE_MB = 1048576;

    public function testTheFirstMeasurementReportsAnAbsoluteFigureWithNoDelta(): void
    {
        // Arrange
        $tracker = $this->createTracker([static::PID => 10 * static::ONE_MB]);

        // Act
        $memoryInfo = $tracker->getMemoryInfoForPid(static::PID);

        // Assert
        $this->assertSame(' Memory: 10 MB', $memoryInfo);
    }

    public function testLaterMeasurementsReportTheChangeSinceTheLastOne(): void
    {
        // Arrange
        $tracker = $this->createTracker([static::PID => 10 * static::ONE_MB]);
        $tracker->getMemoryInfoForPid(static::PID);
        $tracker->setMemoryForPid(static::PID, 14 * static::ONE_MB);

        // Act
        $memoryInfo = $tracker->getMemoryInfoForPid(static::PID);

        // Assert
        $this->assertSame(' Memory: 14 MB (+4 MB)', $memoryInfo);
    }

    public function testAShrinkingProcessReportsANegativeDelta(): void
    {
        // Arrange
        $tracker = $this->createTracker([static::PID => 20 * static::ONE_MB]);
        $tracker->getMemoryInfoForPid(static::PID);
        $tracker->setMemoryForPid(static::PID, 12 * static::ONE_MB);

        // Act
        $memoryInfo = $tracker->getMemoryInfoForPid(static::PID);

        // Assert
        $this->assertSame(' Memory: 12 MB (-8 MB)', $memoryInfo);
    }

    public function testAProcessThatHasNotBeenSeenYetReportsThatItIsStarting(): void
    {
        // Arrange
        $tracker = $this->createTracker([]);

        // Act
        $memoryInfo = $tracker->getMemoryInfoForPid(static::PID);

        // Assert
        $this->assertSame(' Memory: starting...', $memoryInfo);
    }

    public function testAFinishedProcessReportsItsLastKnownFigure(): void
    {
        // Arrange
        $tracker = $this->createTracker([static::PID => 33 * static::ONE_MB]);
        $tracker->getMemoryInfoForPid(static::PID);
        $tracker->setMemoryForPid(static::PID, 0);

        // Act
        $memoryInfo = $tracker->getMemoryInfoForPid(static::PID);

        // Assert
        $this->assertSame(' Memory: 33 MB (finished)', $memoryInfo);
    }

    /**
     * The progress bar resets between execution rounds, and the tracker must forget with it or the first
     * measurement of a recycled pid would be reported as a delta against a stale figure.
     *
     * @return void
     */
    public function testResetForgetsEveryRememberedProcess(): void
    {
        // Arrange
        $tracker = $this->createTracker([static::PID => 10 * static::ONE_MB]);
        $tracker->getMemoryInfoForPid(static::PID);

        // Act
        $tracker->reset();
        $tracker->setMemoryForPid(static::PID, 15 * static::ONE_MB);
        $memoryInfo = $tracker->getMemoryInfoForPid(static::PID);

        // Assert
        $this->assertSame(' Memory: 15 MB', $memoryInfo, 'No delta, because nothing is remembered.');
    }

    /**
     * @param array<int, int> $memoryByPid
     *
     * @return \SprykerTest\Zed\Queue\Helper\TestableProcessMemoryTracker
     */
    protected function createTracker(array $memoryByPid): TestableProcessMemoryTracker
    {
        return new TestableProcessMemoryTracker($memoryByPid);
    }
}
