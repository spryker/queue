<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Business\Logger;

use Codeception\Test\Unit;
use Spryker\Zed\Queue\Business\Logger\WorkerLogger;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Auto-generated group annotations
 *
 * @group SprykerTest
 * @group Zed
 * @group Queue
 * @group Business
 * @group Logger
 * @group WorkerLoggerTest
 * Add your own group annotations below this line
 */
class WorkerLoggerTest extends Unit
{
    /**
     * @var \SprykerTest\Zed\Queue\QueueBusinessTester
     */
    protected $tester;

    public function testInfoIsSuppressedAtnormalVerbosity(): void
    {
        // Arrange
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $workerLogger = new WorkerLogger($output);

        // Act
        $workerLogger->info('a progress line');

        // Assert
        $this->assertSame('', $output->fetch());
    }

    public function testInfoIsWrittenWhenVerbose(): void
    {
        // Arrange
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $workerLogger = new WorkerLogger($output);

        // Act
        $workerLogger->info('a progress line');

        // Assert
        $this->assertStringContainsString('a progress line', $output->fetch());
    }

    public function testDebugIsWrittenOnlyAtDebugVerbosity(): void
    {
        // Arrange
        $verboseOutput = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $debugOutput = new BufferedOutput(OutputInterface::VERBOSITY_DEBUG);

        // Act
        (new WorkerLogger($verboseOutput))->debug('a debug line');
        (new WorkerLogger($debugOutput))->debug('a debug line');

        // Assert
        $this->assertSame('', $verboseOutput->fetch());
        $this->assertStringContainsString('a debug line', $debugOutput->fetch());
    }

    /**
     * Errors are the one level that is never suppressed: a worker run at default verbosity must still
     * surface a failure.
     *
     * @return void
     */
    public function testErrorsAreWrittenEvenAtnormalVerbosity(): void
    {
        // Arrange
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $workerLogger = new WorkerLogger($output);

        // Act
        $workerLogger->error('QUEUE READ ERROR: broker is gone');

        // Assert
        $this->assertStringContainsString('QUEUE READ ERROR: broker is gone', $output->fetch());
    }

    public function testLogNotOftenThanWritesTheFirstMessageAndThenThrottlesTheTimer(): void
    {
        // Arrange
        $output = new BufferedOutput(OutputInterface::VERBOSITY_DEBUG);
        $workerLogger = new WorkerLogger($output);

        // Act
        $workerLogger->logNotOftenThan('no-mem', 'NO MEMORY');
        $workerLogger->logNotOftenThan('no-mem', 'NO MEMORY');
        $workerLogger->logNotOftenThan('no-mem', 'NO MEMORY');

        // Assert
        $this->assertSame(
            1,
            substr_count($output->fetch(), 'NO MEMORY'),
            'The worker calls this every loop iteration; without the throttle it would flood the log.',
        );
    }

    public function testEachTimerNameIsThrottledIndependently(): void
    {
        // Arrange
        $output = new BufferedOutput(OutputInterface::VERBOSITY_DEBUG);
        $workerLogger = new WorkerLogger($output);

        // Act
        $workerLogger->logNotOftenThan('no-mem', 'NO MEMORY');
        $workerLogger->logNotOftenThan('no-proc', 'BUSY');

        // Assert
        $written = $output->fetch();
        $this->assertStringContainsString('NO MEMORY', $written);
        $this->assertStringContainsString('BUSY', $written);
    }

    /**
     * The worker passes a closure that reads free memory, which is a process spawn.
     *
     * @return void
     */
    public function testACallableMessageIsNotEvaluatedWhileThrottled(): void
    {
        // Arrange
        $output = new BufferedOutput(OutputInterface::VERBOSITY_DEBUG);
        $workerLogger = new WorkerLogger($output);
        $evaluations = 0;
        $message = function () use (&$evaluations): string {
            $evaluations++;

            return 'expensive message';
        };

        // Act
        $workerLogger->logNotOftenThan('time-mem', $message);
        $workerLogger->logNotOftenThan('time-mem', $message);

        // Assert
        $this->assertSame(1, $evaluations);
        $this->assertStringContainsString('expensive message', $output->fetch());
    }

    /**
     * The template used to be a single-quoted raw ANSI escape, so the backslash-033 was four literal
     * characters and every error line was printed wrapped in visible escape noise rather than colour.
     */
    public function testAnErrorIsMarkedUpForTheConsoleRatherThanWrappedInLiteralEscapeCharacters(): void
    {
        // Arrange
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $workerLogger = new WorkerLogger($output);

        // Act
        $workerLogger->error('NO MEMORY');

        // Assert
        $written = $output->fetch();
        $this->assertStringNotContainsString(
            chr(92) . '033',
            $written,
            'A literal backslash-033 means the escape was never interpreted.',
        );
        $this->assertStringContainsString('NO MEMORY', $written);
    }

    /**
     * Only debug and info used to be handled, so a caller raising a message to error stamped the throttle
     * timer and produced nothing.
     *
     * @dataProvider levelAboveInfoDataProvider
     */
    public function testALevelAboveInfoIsWrittenWhateverTheVerbosity(string $level): void
    {
        // Arrange
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $workerLogger = new WorkerLogger($output);

        // Act
        $workerLogger->logNotOftenThan('gate', 'NO MEMORY', $level);

        // Assert
        $this->assertStringContainsString('NO MEMORY', $output->fetch());
    }

    /**
     * @return array<string, array<string>>
     */
    public function levelAboveInfoDataProvider(): array
    {
        return [
            'error' => ['error'],
            'warning' => ['warning'],
            'critical' => ['critical'],
            'an unrecognised level is not silently dropped' => ['whatever'],
        ];
    }

    /**
     * The throttle must still apply, or a per-cycle degradation floods the console.
     */
    public function testAnErrorLevelMessageIsStillThrottled(): void
    {
        // Arrange
        $output = new BufferedOutput(OutputInterface::VERBOSITY_NORMAL);
        $workerLogger = new WorkerLogger($output);

        // Act
        $workerLogger->logNotOftenThan('gate', 'NO MEMORY', 'error');
        $workerLogger->logNotOftenThan('gate', 'NO MEMORY', 'error');
        $workerLogger->logNotOftenThan('gate', 'NO MEMORY', 'error');

        // Assert
        $this->assertSame(1, substr_count($output->fetch(), 'NO MEMORY'));
    }

    public function testLogNotOftenThanRespectsTheRequestedLevel(): void
    {
        // Arrange
        $output = new BufferedOutput(OutputInterface::VERBOSITY_VERBOSE);
        $workerLogger = new WorkerLogger($output);

        // Act
        $workerLogger->logNotOftenThan('a', 'a debug line', 'debug');
        $workerLogger->logNotOftenThan('b', 'an info line', 'info');

        // Assert
        $written = $output->fetch();
        $this->assertStringNotContainsString('a debug line', $written, 'Verbose is not debug.');
        $this->assertStringContainsString('an info line', $written);
    }
}
