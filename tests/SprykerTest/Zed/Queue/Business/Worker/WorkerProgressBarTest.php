<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Business\Worker;

use Codeception\Test\Unit;
use Spryker\Zed\Queue\Business\Worker\ProcessMemoryTrackerInterface;
use Spryker\Zed\Queue\Business\Worker\QueueMessageFormatterInterface;
use Spryker\Zed\Queue\Business\Worker\WorkerProgressBar;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Auto-generated group annotations
 *
 * @group SprykerTest
 * @group Zed
 * @group Queue
 * @group Business
 * @group Worker
 * @group WorkerProgressBarTest
 * Add your own group annotations below this line
 */
class WorkerProgressBarTest extends Unit
{
    /**
     * @var \SprykerTest\Zed\Queue\QueueBusinessTester
     */
    protected $tester;

    /**
     * Every public method has to survive being called before start(), because the worker calls display() and
     * setProgress() from its loop regardless of verbosity.
     *
     * @return void
     */
    public function testEveryOperationIsSafeBeforeTheBarHasBeenStarted(): void
    {
        // Arrange
        $output = $this->createOutput(OutputInterface::VERBOSITY_NORMAL);
        $workerProgressBar = $this->createWorkerProgressBar($output);

        // Act
        $workerProgressBar->advance();
        $workerProgressBar->clear();
        $workerProgressBar->display();
        $workerProgressBar->setProgress(5);
        $workerProgressBar->finish();
        $workerProgressBar->writeConsoleMessage(1, 'event', 4242, 0, 1, null, null);

        // Assert
        $this->assertSame('', $output->fetch(), 'Nothing is written until the bar exists.');
    }

    public function testNoProgressBarIsCreatedAtTheDefaultVerbosity(): void
    {
        // Arrange
        $output = $this->createOutput(OutputInterface::VERBOSITY_NORMAL);
        $workerProgressBar = $this->createWorkerProgressBar($output);

        // Act
        $workerProgressBar->start(59, 1);
        $workerProgressBar->display();
        $workerProgressBar->writeConsoleMessage(1, 'event', 4242, 0, 1, null, null);

        // Assert
        $this->assertSame('', $output->fetch());
    }

    public function testTheProgressBarIsCreatedWhenRunningVerbosely(): void
    {
        // Arrange
        $output = $this->createOutput(OutputInterface::VERBOSITY_VERBOSE);
        $workerProgressBar = $this->createWorkerProgressBar($output);

        // Act
        $workerProgressBar->start(59, 1);
        $workerProgressBar->display();

        // Assert
        $this->assertStringContainsString('Main Queue Process <execution round #1>', $output->fetch());
    }

    /**
     * The guard in start() compares against `VERBOSITY_NORMAL` (32) rather than `VERBOSITY_QUIET` (16),
     * which reads like an off-by-one until you follow it through: the guard's job is to keep the bar off the
     * DEFAULT run, and anything quieter than normal is then filtered a second time by Symfony's own
     * `Output::write()`, which drops any message whose verbosity exceeds the output's.
     *
     * @return void
     */
    public function testQuietVerbosityBuildsABarThatSymfonyThenSuppresses(): void
    {
        // Arrange
        $output = $this->createOutput(OutputInterface::VERBOSITY_QUIET);
        $workerProgressBar = $this->createWorkerProgressBar($output);

        // Act
        $workerProgressBar->start(59, 1);
        $workerProgressBar->display();
        $workerProgressBar->writeConsoleMessage(1, 'event', 4242, 0, 1, null, null);

        // Assert
        $this->assertSame(
            '',
            $output->fetch(),
            'The bar is constructed, because the guard only catches VERBOSITY_NORMAL, but Symfony '
            . 'discards every write below its own threshold, so --quiet stays silent either way.',
        );
    }

    public function testWriteConsoleMessageAsksTheTrackerAndFormatterForTheLine(): void
    {
        // Arrange
        $output = $this->createOutput(OutputInterface::VERBOSITY_VERBOSE);

        $processMemoryTrackerMock = $this->createMock(ProcessMemoryTrackerInterface::class);
        $processMemoryTrackerMock
            ->expects($this->once())
            ->method('getMemoryInfoForPid')
            ->with(4242)
            ->willReturn(' Memory: 42 MB');

        $queueMessageFormatterMock = $this->createMock(QueueMessageFormatterInterface::class);
        $queueMessageFormatterMock
            ->expects($this->once())
            ->method('formatQueueStatusMessage')
            ->willReturn('01) [EU:event] New: 1 Busy: 0 Memory: 42 MB');

        $workerProgressBar = new WorkerProgressBar($output, $processMemoryTrackerMock, $queueMessageFormatterMock);
        $workerProgressBar->start(59, 1);
        $output->fetch();

        // Act
        $workerProgressBar->writeConsoleMessage(1, 'event', 4242, 0, 1, null, null);

        // Assert
        $this->assertStringContainsString('01) [EU:event] New: 1 Busy: 0 Memory: 42 MB', $output->fetch());
    }

    public function testWriteErrorsForwardsEveryLine(): void
    {
        // Arrange
        $output = $this->createOutput(OutputInterface::VERBOSITY_NORMAL);
        $workerProgressBar = $this->createWorkerProgressBar($output);

        // Act
        $workerProgressBar->writeErrors(['first failure', 'second failure']);

        // Assert
        $written = $output->fetch();
        $this->assertStringContainsString('first failure', $written);
        $this->assertStringContainsString('second failure', $written);
    }

    public function testResetDiscardsTheBarAndClearsTheMemoryTracker(): void
    {
        // Arrange
        $output = $this->createOutput(OutputInterface::VERBOSITY_VERBOSE);

        $processMemoryTrackerMock = $this->createMock(ProcessMemoryTrackerInterface::class);
        $processMemoryTrackerMock->expects($this->once())->method('reset');

        $workerProgressBar = new WorkerProgressBar(
            $output,
            $processMemoryTrackerMock,
            $this->createMock(QueueMessageFormatterInterface::class),
        );
        $workerProgressBar->start(59, 1);
        $output->fetch();

        // Act
        $workerProgressBar->reset();
        $workerProgressBar->display();

        // Assert
        $this->assertSame('', $output->fetch(), 'The discarded bar no longer renders.');
    }

    protected function createOutput(int $verbosity): BufferedOutput
    {
        return new BufferedOutput($verbosity);
    }

    protected function createWorkerProgressBar(OutputInterface $output): WorkerProgressBar
    {
        return new WorkerProgressBar(
            $output,
            $this->createMock(ProcessMemoryTrackerInterface::class),
            $this->createMock(QueueMessageFormatterInterface::class),
        );
    }
}
