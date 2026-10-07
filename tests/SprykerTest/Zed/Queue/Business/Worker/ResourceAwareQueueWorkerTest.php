<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Business\Worker;

use Codeception\Test\Unit;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use Spryker\Client\Queue\QueueClientInterface;
use Spryker\Shared\Queue\QueueConfig as SharedQueueConfig;
use Spryker\Zed\Queue\Business\Logger\WorkerLoggerInterface;
use Spryker\Zed\Queue\Business\Process\ProcessManagerInterface;
use Spryker\Zed\Queue\Business\Queue\QueueMetrics;
use Spryker\Zed\Queue\Business\SignalHandler\SignalDispatcherInterface;
use Spryker\Zed\Queue\Business\Strategy\QueueProcessingStrategyInterface;
use Spryker\Zed\Queue\Business\SystemResources\SystemResourcesManagerInterface;
use Spryker\Zed\Queue\QueueConfig;
use SprykerTest\Zed\Queue\Helper\FakeProcess;
use SprykerTest\Zed\Queue\Helper\QueueWorkerFixtures;
use SprykerTest\Zed\Queue\Helper\TestableResourceAwareQueueWorker;
use TypeError;

/**
 * Auto-generated group annotations
 *
 * @group SprykerTest
 * @group Zed
 * @group Queue
 * @group Business
 * @group Worker
 * @group ResourceAwareQueueWorkerTest
 * Add your own group annotations below this line
 */
class ResourceAwareQueueWorkerTest extends Unit
{
    /**
     * @var \SprykerTest\Zed\Queue\QueueBusinessTester
     */
    protected $tester;

    /**
     * Project configuration, not a framework guarantee - expressed as fixtures so a config change shows up
     * as a fixture change rather than as a mysterious assertion failure.
     *
     * @var int
     */
    protected const MAX_PROCESSES = 4;

    /**
     * @var int
     */
    protected const WORKER_INTERVAL_MILLISECONDS = 100;

    /**
     * @var int
     */
    protected const MAX_THRESHOLD_SECONDS = 59;

    /**
     * @var int
     */
    protected const MAX_MEMORY_GROWTH_FACTOR = 50;

    /**
     * @var int
     */
    protected const MEMORY_READ_TIMEOUT_SECONDS = 5;

    /**
     * @var string
     */
    protected const STORE_NAME = 'DE';

    /**
     * @var string
     */
    protected const REGION_NAME = 'EU';

    /**
     * @var array<int>
     */
    protected const GRACEFUL_SHUTDOWN_SIGNALS = [2, 15];

    public function testConstructorSizesTheProcessPoolFromTheMaxProcessesConfig(): void
    {
        // Act
        $worker = $this->createWorker();

        // Assert
        $this->assertCount(
            static::MAX_PROCESSES,
            $worker->getProcesses(),
            'The fixed-size process pool must be sized from QUEUE_WORKER_MAX_PROCESSES.',
        );
    }

    public function testConstructorDispatchesTheConfiguredGracefulShutdownSignals(): void
    {
        // Arrange
        $signalDispatcherMock = $this->createMock(SignalDispatcherInterface::class);
        $signalDispatcherMock
            ->expects($this->once())
            ->method('dispatch')
            ->with(static::GRACEFUL_SHUTDOWN_SIGNALS);

        // Act
        $this->createWorker(signalDispatcher: $signalDispatcherMock);

        // Assert
        // Handled by the `once()` expectation above.
    }

    // -----------------------------------------------------------------------------------------
    // rescanProcesses()
    // -----------------------------------------------------------------------------------------

    public function testRescanProcessesReturnsTheFirstIndexWhenThePoolIsEmpty(): void
    {
        // Arrange
        $worker = $this->createWorker();

        // Act
        $freeIndex = $worker->callRescanProcesses();

        // Assert
        $this->assertSame(0, $freeIndex);
        $this->assertSame(0, $worker->getRunningProcessesCount());
    }

    public function testRescanProcessesReturnsNullWhenEverySlotHoldsARunningProcess(): void
    {
        // Arrange
        $worker = $this->createWorker();
        for ($index = 0; $index < static::MAX_PROCESSES; $index++) {
            $worker->setProcessAt($index, new FakeProcess(isRunning: true));
        }

        // Act
        $freeIndex = $worker->callRescanProcesses();

        // Assert
        $this->assertNull($freeIndex, 'A full pool must report no free slot.');
        $this->assertSame(static::MAX_PROCESSES, $worker->getRunningProcessesCount());
    }

    public function testRescanProcessesReturnsTheLowestFreeIndex(): void
    {
        // Arrange
        $worker = $this->createWorker();
        $worker->setProcessAt(0, new FakeProcess(isRunning: true));
        $worker->setProcessAt(1, new FakeProcess(isRunning: true));
        // Slots 2 and 3 stay empty.

        // Act
        $freeIndex = $worker->callRescanProcesses();

        // Assert
        $this->assertSame(2, $freeIndex);
        $this->assertSame(2, $worker->getRunningProcessesCount());
    }

    public function testRescanProcessesReleasesAFinishedProcessAndFreesItsSlot(): void
    {
        // Arrange
        $worker = $this->createWorker();
        $worker->setProcessAt(0, new FakeProcess(isRunning: true));
        $worker->setProcessAt(1, new FakeProcess(isRunning: false, exitCode: 0));
        $worker->setProcessAt(2, new FakeProcess(isRunning: true));
        $worker->setProcessAt(3, new FakeProcess(isRunning: true));

        // Act
        $freeIndex = $worker->callRescanProcesses();

        // Assert
        $this->assertSame(1, $freeIndex, 'The finished process must free its own slot.');
        $this->assertNull($worker->getProcesses()[1], 'The finished process must be removed from the pool.');
        $this->assertSame(3, $worker->getRunningProcessesCount());
    }

    public function testRescanProcessesRecordsAFailedProcessForANonZeroExitCode(): void
    {
        // Arrange
        $worker = $this->createWorker();
        $worker->setProcessAt(0, new FakeProcess(isRunning: false, exitCode: 1));

        // Act
        $worker->callRescanProcesses();

        // Assert
        $this->assertSame(1, $worker->getProcStats()['failed'] ?? 0);
        $this->assertArrayHasKey(
            'Exit code 1',
            $worker->getErrorStats(),
            'A non-zero exit must be recorded against its exit-code text.',
        );
    }

    public function testRescanProcessesDoesNotRecordAFailureForACleanExit(): void
    {
        // Arrange
        $worker = $this->createWorker();
        $worker->setProcessAt(0, new FakeProcess(isRunning: false, exitCode: 0));

        // Act
        $worker->callRescanProcesses();

        // Assert
        $this->assertArrayNotHasKey('failed', $worker->getProcStats());
    }

    public function testRescanProcessesTracksThePeakConcurrentProcessCount(): void
    {
        // Arrange
        $worker = $this->createWorker();
        $worker->setProcessAt(0, new FakeProcess(isRunning: true));
        $worker->setProcessAt(1, new FakeProcess(isRunning: true));
        $worker->setProcessAt(2, new FakeProcess(isRunning: true));

        // Act
        $worker->callRescanProcesses();

        // Assert
        $this->assertSame(3, $worker->getProcStats()['max'] ?? 0);
    }

    // -----------------------------------------------------------------------------------------
    // start(): the memory gate and the slot gate
    // -----------------------------------------------------------------------------------------

    public function testStartDoesNotSpawnOrEvenConsultTheStrategyWhenMemoryIsInsufficient(): void
    {
        // Arrange
        $systemResourcesManagerMock = $this->createSystemResourcesManagerMock(enoughResources: false);

        $strategyMock = $this->createMock(QueueProcessingStrategyInterface::class);
        $strategyMock->expects($this->never())->method('getNextQueue');

        $processManagerMock = $this->createMock(ProcessManagerInterface::class);
        $processManagerMock->expects($this->never())->method('triggerQueueProcess');

        $worker = $this->createWorker(
            processManager: $processManagerMock,
            queueProcessingStrategy: $strategyMock,
            sysResManager: $systemResourcesManagerMock,
        );
        $worker->setLoopIterations(1);

        // Act
        $worker->start(QueueWorkerFixtures::COMMAND);

        // Assert
        $this->assertSame(1, $worker->getCycleStats()['no_mem'] ?? 0);
        $this->assertSame(1, $worker->getCycleStats()['skip-cycle'] ?? 0);
        $this->assertSame(0, $worker->getRunningProcessesCount());
    }

    public function testStartRecordsANoSlotCycleAndSkipsTheStrategyWhenThePoolIsFull(): void
    {
        // Arrange
        $strategyMock = $this->createMock(QueueProcessingStrategyInterface::class);
        $strategyMock->expects($this->never())->method('getNextQueue');

        $worker = $this->createWorker(queueProcessingStrategy: $strategyMock);
        for ($index = 0; $index < static::MAX_PROCESSES; $index++) {
            $worker->setProcessAt($index, (new FakeProcess(isRunning: true))->finishAfter(1));
        }
        $worker->setLoopIterations(1);

        // Act
        $worker->start(QueueWorkerFixtures::COMMAND);

        // Assert
        $this->assertSame(1, $worker->getCycleStats()['no_slot'] ?? 0);
        $this->assertSame(1, $worker->getCycleStats()['skip-cycle'] ?? 0);
    }

    public function testStartRegistersKillSignalHandlersAndFlushesZombiesOnce(): void
    {
        // Arrange
        $processManagerMock = $this->createMock(ProcessManagerInterface::class);
        $processManagerMock->expects($this->once())->method('flushZombieProcesses');

        $worker = $this->createWorker(processManager: $processManagerMock);
        $worker->setLoopIterations(0);

        // Act
        $worker->start(QueueWorkerFixtures::COMMAND);

        // Assert
        $this->assertSame(1, $worker->getRegisterKillSignalHandlersCallCount());
    }

    /**
     * Whatever a child process wrote must reach the operator.
     *
     * @return void
     */
    public function testChildProcessOutputIsSurfacedEveryCycle(): void
    {
        // Arrange
        $processManagerMock = $this->createMock(ProcessManagerInterface::class);
        $processManagerMock
            ->method('flushErrorBuffer')
            ->willReturnOnConsecutiveCalls(['first failure'], ['second failure'], []);

        $workerLoggerMock = $this->createMock(WorkerLoggerInterface::class);
        $reported = [];
        $workerLoggerMock
            ->method('error')
            ->willReturnCallback(function (string $message) use (&$reported): void {
                $reported[] = $message;
            });

        $worker = $this->createWorker(
            processManager: $processManagerMock,
            workerLogger: $workerLoggerMock,
        );
        $worker->setLoopIterations(2);

        // Act
        $worker->start(QueueWorkerFixtures::COMMAND);

        // Assert
        $this->assertSame(['first failure', 'second failure'], $reported);
    }

    public function testNothingIsReportedWhenTheChildProcessesWereQuiet(): void
    {
        // Arrange
        $processManagerMock = $this->createMock(ProcessManagerInterface::class);
        $processManagerMock->method('flushErrorBuffer')->willReturn([]);

        $workerLoggerMock = $this->createMock(WorkerLoggerInterface::class);
        $workerLoggerMock->expects($this->never())->method('error');

        $worker = $this->createWorker(
            processManager: $processManagerMock,
            workerLogger: $workerLoggerMock,
        );
        $worker->setLoopIterations(2);

        // Act
        $worker->start(QueueWorkerFixtures::COMMAND);

        // Assert
        // Handled by the `never()` expectation above: an empty buffer is not a log line.
    }

    // -----------------------------------------------------------------------------------------
    // executeQueueProcessingStrategy()
    // -----------------------------------------------------------------------------------------

    public function testExecuteQueueProcessingStrategyReportsEmptyWhenTheStrategyYieldsNoQueue(): void
    {
        // Arrange
        $strategyMock = $this->createMock(QueueProcessingStrategyInterface::class);
        $strategyMock->method('getNextQueue')->willReturn(null);

        $processManagerMock = $this->createMock(ProcessManagerInterface::class);
        $processManagerMock->expects($this->never())->method('triggerQueueProcess');

        $worker = $this->createWorker(
            processManager: $processManagerMock,
            queueProcessingStrategy: $strategyMock,
        );

        // Act
        $isQueueEmpty = $worker->callExecuteQueueProcessingStrategy(0, QueueWorkerFixtures::COMMAND);

        // Assert
        $this->assertTrue($isQueueEmpty);
        $this->assertSame(1, $worker->getCycleStats()['empty'] ?? 0);
        $this->assertSame(1, $worker->getCycleStats()['skip-cycle'] ?? 0);
    }

    /**
     * A broker outage must not kill the worker: the exception is caught, recorded, and the loop continues on
     * the next cycle.
     *
     * @return void
     */
    public function testExecuteQueueProcessingStrategySurvivesAStrategyExceptionAndRecordsAConnectionError(): void
    {
        // Arrange
        $strategyMock = $this->createMock(QueueProcessingStrategyInterface::class);
        $strategyMock->method('getNextQueue')->willThrowException(new runtimeException('broker is gone'));

        $workerLoggerMock = $this->createMock(WorkerLoggerInterface::class);
        $workerLoggerMock
            ->expects($this->once())
            ->method('error')
            ->with($this->stringContains('broker is gone'));

        $processManagerMock = $this->createMock(ProcessManagerInterface::class);
        $processManagerMock->expects($this->never())->method('triggerQueueProcess');

        $worker = $this->createWorker(
            processManager: $processManagerMock,
            queueProcessingStrategy: $strategyMock,
            workerLogger: $workerLoggerMock,
        );

        // Act
        $isQueueEmpty = $worker->callExecuteQueueProcessingStrategy(0, QueueWorkerFixtures::COMMAND);

        // Assert
        $this->assertFalse(
            $isQueueEmpty,
            'A read error is not an empty queue: reporting true here would stop the worker on a broker blip.',
        );
        $this->assertSame(1, $worker->getErrorStats()['RMQ-connection'] ?? 0);
        $this->assertSame(1, $worker->getCycleStats()['skip-cycle'] ?? 0);
    }

    public function testExecuteQueueProcessingStrategyStoresTheStartedProcessAtTheGivenFreeIndex(): void
    {
        // Arrange
        $freeIndex = 2;
        $startedProcess = new FakeProcess();

        $strategyMock = $this->createMock(QueueProcessingStrategyInterface::class);
        $strategyMock->method('getNextQueue')->willReturn($this->createQueuemetrics());

        $processManagerMock = $this->createMock(ProcessManagerInterface::class);
        $processManagerMock
            ->expects($this->once())
            ->method('triggerQueueProcess')
            ->with($this->anything(), QueueWorkerFixtures::QUEUE_NAME)
            ->willReturn($startedProcess);

        $worker = $this->createWorker(
            processManager: $processManagerMock,
            queueProcessingStrategy: $strategyMock,
        );

        // Act
        $isQueueEmpty = $worker->callExecuteQueueProcessingStrategy($freeIndex, QueueWorkerFixtures::COMMAND);

        // Assert
        $this->assertFalse($isQueueEmpty);
        $this->assertSame($startedProcess, $worker->getProcesses()[$freeIndex]);
        $this->assertSame(1, $worker->getRunningProcessesCount());
        $this->assertSame(1, $worker->getProcStats()['new'] ?? 0);
    }

    public function testExecuteQueueProcessingStrategyRecordsQueueAndLocationStats(): void
    {
        // Arrange
        $strategyMock = $this->createMock(QueueProcessingStrategyInterface::class);
        $strategyMock->method('getNextQueue')->willReturn($this->createQueuemetrics());

        $processManagerMock = $this->createMock(ProcessManagerInterface::class);
        $processManagerMock->method('triggerQueueProcess')->willReturn(new FakeProcess());

        $worker = $this->createWorker(
            processManager: $processManagerMock,
            queueProcessingStrategy: $strategyMock,
        );

        // Act
        $worker->callExecuteQueueProcessingStrategy(0, QueueWorkerFixtures::COMMAND);

        // Assert
        $stats = $worker->getStats()->getStats();
        $this->assertSame(1, $stats['queues'][QueueWorkerFixtures::QUEUE_NAME] ?? 0);
        $this->assertSame(
            1,
            $stats['queues'][static::STORE_NAME . ':' . QueueWorkerFixtures::QUEUE_NAME] ?? 0,
            'The store-qualified queue counter is recorded alongside the bare queue name.',
        );
        $this->assertSame(1, $stats['locations'][static::STORE_NAME] ?? 0);
    }

    /**
     * A worker whose memory reader is broken skips every cycle, spawns nothing and used to say so only at
     * debug level.
     */
    public function testInsufficientMemoryIsReportedAtErrorLevel(): void
    {
        // Arrange
        $reportedLevels = [];
        $workerLoggerMock = $this->createMock(WorkerLoggerInterface::class);
        $workerLoggerMock
            ->method('logNotOftenThan')
            ->willReturnCallback(function (string $timerName, $message, string $level = 'debug') use (&$reportedLevels): void {
                $reportedLevels[$timerName] = $level;
            });

        $worker = $this->createWorker(
            sysResManager: $this->createSystemResourcesManagerMock(enoughResources: false),
            workerLogger: $workerLoggerMock,
        );
        $worker->setLoopIterations(1);

        // Act
        $worker->start(QueueWorkerFixtures::COMMAND);

        // Assert
        $this->assertSame('error', $reportedLevels['no-mem'] ?? null);
    }

    /**
     * A programming fault inside the strategy or one of its plugins is not a broker outage, and filing both
     * under the same key sends whoever reads the stats to the wrong system.
     */
    public function testAProgrammingErrorIsNotRecordedAsABrokerFailure(): void
    {
        // Arrange
        $strategyMock = $this->createMock(QueueProcessingStrategyInterface::class);
        $strategyMock->method('getNextQueue')->willThrowException(new TypeError('a plugin returned null'));

        $worker = $this->createWorker(queueProcessingStrategy: $strategyMock);

        // Act
        $worker->callExecuteQueueProcessingStrategy(0, QueueWorkerFixtures::COMMAND);

        // Assert
        $this->assertSame(1, $worker->getErrorStats()['strategy-error'] ?? 0);
        $this->assertArrayNotHasKey('RMQ-connection', $worker->getErrorStats());
    }

    /**
     * A genuine broker failure keeps its own key, so the two remain distinguishable.
     */
    public function testABrokerFailureIsStillRecordedAsAConnectionError(): void
    {
        // Arrange
        $strategyMock = $this->createMock(QueueProcessingStrategyInterface::class);
        $strategyMock->method('getNextQueue')->willThrowException(new runtimeException('broker is gone'));

        $worker = $this->createWorker(queueProcessingStrategy: $strategyMock);

        // Act
        $worker->callExecuteQueueProcessingStrategy(0, QueueWorkerFixtures::COMMAND);

        // Assert
        $this->assertSame(1, $worker->getErrorStats()['RMQ-connection'] ?? 0);
        $this->assertArrayNotHasKey('strategy-error', $worker->getErrorStats());
    }

    /**
     * A command that fails instantly on every attempt would otherwise report a healthy success rate, because
     * a process that never ran the queue was counted as work started.
     */
    public function testAProcessThatDiedImmediatelyIsNotCountedAsWorkStarted(): void
    {
        // Arrange
        $strategyMock = $this->createMock(QueueProcessingStrategyInterface::class);
        $strategyMock->method('getNextQueue')->willReturn($this->createQueuemetrics());

        $processManagerMock = $this->createMock(ProcessManagerInterface::class);
        $processManagerMock
            ->method('triggerQueueProcess')
            ->willReturn(new FakeProcess(isRunning: false, exitCode: 127));

        $worker = $this->createWorker(
            processManager: $processManagerMock,
            queueProcessingStrategy: $strategyMock,
        );

        // Act
        $worker->callExecuteQueueProcessingStrategy(0, QueueWorkerFixtures::COMMAND);

        // Assert
        $this->assertSame(1, $worker->getProcStats()['failed-to-start'] ?? 0);
        $this->assertArrayNotHasKey(
            'new',
            $worker->getProcStats(),
            'A process that exited 127 before doing anything is not work started.',
        );
    }

    /**
     * A process that finished cleanly between spawning and the check did do its work, and must not be
     * mistaken for one that failed to start.
     */
    public function testAProcessThatFinishedCleanlyIsStillCountedAsWorkStarted(): void
    {
        // Arrange
        $strategyMock = $this->createMock(QueueProcessingStrategyInterface::class);
        $strategyMock->method('getNextQueue')->willReturn($this->createQueuemetrics());

        $processManagerMock = $this->createMock(ProcessManagerInterface::class);
        $processManagerMock
            ->method('triggerQueueProcess')
            ->willReturn(new FakeProcess(isRunning: false, exitCode: 0));

        $worker = $this->createWorker(
            processManager: $processManagerMock,
            queueProcessingStrategy: $strategyMock,
        );

        // Act
        $worker->callExecuteQueueProcessingStrategy(0, QueueWorkerFixtures::COMMAND);

        // Assert
        $this->assertSame(1, $worker->getProcStats()['new'] ?? 0);
        $this->assertArrayNotHasKey('failed-to-start', $worker->getProcStats());
    }

    /**
     * The end-of-run summary went through info(), invisible without -v, so a worker that failed every
     * process it started looked exactly like one that did clean work.
     */
    public function testTheSummaryIsReportedAtErrorLevelWhenEveryStartedProcessFailed(): void
    {
        // Arrange
        $reported = [];
        $workerLoggerMock = $this->createMock(WorkerLoggerInterface::class);
        $workerLoggerMock->method('error')->willReturnCallback(function (string $message) use (&$reported): void {
            $reported[] = $message;
        });
        $workerLoggerMock->expects($this->never())->method('info');

        $worker = $this->createWorker(workerLogger: $workerLoggerMock);
        $worker->getStats()->addProcQuantity('new', 3)->addProcQuantity('failed', 3);
        $worker->setLoopIterations(0);

        // Act
        $worker->start(QueueWorkerFixtures::COMMAND);

        // Assert
        $this->assertNotSame([], $reported, 'A run in which nothing succeeded must be reported as an error.');
        $this->assertStringContainsString('DONE', $reported[count($reported) - 1]);
    }

    /**
     * A run that did work keeps the quiet channel, so the error level stays meaningful.
     */
    public function testTheSummaryStaysAtInfoLevelWhenSomeWorkSucceeded(): void
    {
        // Arrange
        $workerLoggerMock = $this->createMock(WorkerLoggerInterface::class);
        $workerLoggerMock->expects($this->once())->method('info')->with($this->stringContains('DONE'));

        $worker = $this->createWorker(workerLogger: $workerLoggerMock);
        $worker->getStats()->addProcQuantity('new', 3)->addProcQuantity('failed', 1);
        $worker->setLoopIterations(0);

        // Act
        $worker->start(QueueWorkerFixtures::COMMAND);

        // Assert
        // Handled by the once()/with() expectation above.
    }

    // -----------------------------------------------------------------------------------------
    // getProcessCommand()
    // -----------------------------------------------------------------------------------------

    public function testGetProcessCommandUsesTheStorePatternWhenAStoreNameIsPresent(): void
    {
        // Arrange
        $worker = $this->createWorker();

        // Act
        $processCommand = $worker->callGetProcessCommand($this->createQueuemetrics(), QueueWorkerFixtures::COMMAND);

        // Assert
        $this->assertSame(
            sprintf('APPLICATION_STORE=%s %s %s', static::STORE_NAME, QueueWorkerFixtures::COMMAND, QueueWorkerFixtures::QUEUE_NAME),
            $processCommand,
        );
    }

    public function testGetProcessCommandUsesThePlainPatternWhenNoStoreNameIsPresent(): void
    {
        // Arrange
        $worker = $this->createWorker();
        $queuemetrics = $this->createQueuemetrics(storeName: null);

        // Act
        $processCommand = $worker->callGetProcessCommand($queuemetrics, QueueWorkerFixtures::COMMAND);

        // Assert
        $this->assertSame(
            sprintf('%s %s', QueueWorkerFixtures::COMMAND, QueueWorkerFixtures::QUEUE_NAME),
            $processCommand,
            'Without a store the command carries no APPLICATION_STORE prefix, even though a region is set.',
        );
    }

    // -----------------------------------------------------------------------------------------
    // shouldStopWhenQueueEmpty()
    // -----------------------------------------------------------------------------------------

    /**
     * @dataProvider shouldStopWhenQueueEmptyDataProvider
     *
     * @return void
     */
    public function testShouldStopWhenQueueEmpty(
        bool $stopWhenEmptyEnabled,
        bool $isQueueEmpty,
        int $runningProcessesCount,
        int $previousRunningProcessesCount,
        bool $expected
    ): void {
        // Arrange
        $worker = $this->createWorker();
        $worker->setRunningProcessesCount($runningProcessesCount);

        $options = $stopWhenEmptyEnabled
            ? [SharedQueueConfig::CONFIG_WORKER_STOP_WHEN_EMPTY => true]
            : [];

        // Act
        $shouldStop = $worker->callShouldStopWhenQueueEmpty($isQueueEmpty, $previousRunningProcessesCount, $options);

        // Assert
        $this->assertSame($expected, $shouldStop);
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function shouldStopWhenQueueEmptyDataProvider(): array
    {
        return [
            // stopWhenEmpty, isQueueEmpty, running, previousRunning, expected
            'stop-when-empty disabled never stops' => [false, true, 0, 0, false],
            'queue not empty keeps running' => [true, false, 0, 0, false],
            'processes still running keeps running' => [true, true, 1, 0, false],
            'processes finished this cycle keeps running one more cycle' => [true, true, 0, 2, false],
            'empty, idle, and idle last cycle stops' => [true, true, 0, 0, true],
        ];
    }

    // -----------------------------------------------------------------------------------------
    // continueExecution()
    // -----------------------------------------------------------------------------------------

    public function testContinueExecutionStopsOnceTheThresholdHasElapsed(): void
    {
        // Arrange
        $worker = $this->createWorker();
        $startedTwoSecondsAgo = microtime(true) - 2.0;

        // Act
        $shouldContinue = $worker->callContinueExecution($startedTwoSecondsAgo, 1, []);

        // Assert
        $this->assertFalse($shouldContinue);
    }

    public function testContinueExecutionKeepsRunningBeforeTheThresholdHasElapsed(): void
    {
        // Arrange
        $worker = $this->createWorker();

        // Act
        $shouldContinue = $worker->callContinueExecution(microtime(true), static::MAX_THRESHOLD_SECONDS, []);

        // Assert
        $this->assertTrue($shouldContinue);
    }

    public function testContinueExecutionIgnoresTheThresholdWhenStopWhenEmptyIsEnabled(): void
    {
        // Arrange
        $worker = $this->createWorker();
        $startedLongAgo = microtime(true) - 3600.0;

        // Act
        $shouldContinue = $worker->callContinueExecution(
            $startedLongAgo,
            1,
            [SharedQueueConfig::CONFIG_WORKER_STOP_WHEN_EMPTY => true],
        );

        // Assert
        $this->assertTrue(
            $shouldContinue,
            '--stop-only-when-empty overrides the time threshold entirely; only an empty queue ends the run.',
        );
    }

    // -----------------------------------------------------------------------------------------
    // waitProcessesToComplete()
    // -----------------------------------------------------------------------------------------

    public function testWaitProcessesToCompleteReturnsImmediatelyWhenNothingIsRunning(): void
    {
        // Arrange
        $workerLoggerMock = $this->createMock(WorkerLoggerInterface::class);
        $workerLoggerMock->expects($this->never())->method('debug');

        $worker = $this->createWorker(workerLogger: $workerLoggerMock);

        // Act
        $worker->callWaitProcessesToComplete();

        // Assert
        $this->assertSame(0, $worker->getRunningProcessesCount());
    }

    public function testWaitProcessesToCompleteWaitsUntilTheLastProcessFinishes(): void
    {
        // Arrange
        $worker = $this->createWorker($this->createQueueConfigMock(['isQueueWorkerWaitLimitEnabled' => false]));
        $worker->setProcessAt(0, (new FakeProcess(isRunning: true))->finishAfter(2));
        $worker->setRunningProcessesCount(1);

        // Act
        $worker->callWaitProcessesToComplete();

        // Assert
        $this->assertSame(0, $worker->getRunningProcessesCount());
        $this->assertNull($worker->getProcesses()[0]);
    }

    public function testWaitProcessesToCompleteGivesUpOnceTheWaitLimitIsReached(): void
    {
        // Arrange
        $queueConfigMock = $this->createQueueConfigMock([
            'isQueueWorkerWaitLimitEnabled' => true,
            'getQueueWorkerMaxWaitingSeconds' => 0,
        ]);

        $worker = $this->createWorker($queueConfigMock);
        // A process that never finishes: without the wait limit this would hang forever.
        $worker->setProcessAt(0, new FakeProcess(isRunning: true));
        $worker->setRunningProcessesCount(1);

        // Act
        $worker->callWaitProcessesToComplete();

        // Assert
        $this->assertSame(
            1,
            $worker->getRunningProcessesCount(),
            'The worker gives up with the process still running; the OS reaps it when the worker exits.',
        );
    }

    // -----------------------------------------------------------------------------------------
    // ownWorkerMemGrowthDetected()
    // -----------------------------------------------------------------------------------------

    public function testOwnWorkerMemGrowthDetectedReportsALeakAboveTheConfiguredFactor(): void
    {
        // Arrange
        $worker = $this->createWorker(
            sysResManager: $this->createSystemResourcesManagerMock(
                ownPeakMemoryGrowth: static::MAX_MEMORY_GROWTH_FACTOR + 1,
            ),
        );

        // Act
        $isLeaking = $worker->callOwnWorkerMemGrowthDetected();

        // Assert
        $this->assertTrue($isLeaking);
    }

    public function testOwnWorkerMemGrowthDetectedToleratesGrowthAtTheConfiguredFactor(): void
    {
        // Arrange
        $worker = $this->createWorker(
            sysResManager: $this->createSystemResourcesManagerMock(
                ownPeakMemoryGrowth: static::MAX_MEMORY_GROWTH_FACTOR,
            ),
        );

        // Act
        $isLeaking = $worker->callOwnWorkerMemGrowthDetected();

        // Assert
        $this->assertFalse($isLeaking, 'The comparison is strictly greater-than, so growth exactly at the factor is allowed.');
    }

    public function testOwnWorkerMemGrowthDetectedRecordsTheGrowthFactorAsAmetric(): void
    {
        // Arrange
        $growthFactor = 12;
        $worker = $this->createWorker(
            sysResManager: $this->createSystemResourcesManagerMock(ownPeakMemoryGrowth: $growthFactor),
        );

        // Act
        $worker->callOwnWorkerMemGrowthDetected();

        // Assert
        $this->assertSame($growthFactor, $worker->getStats()->getStats()['metrics']['mem-growth'] ?? null);
    }

    // -----------------------------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------------------------

    protected function createWorker(
        ?QueueConfig $queueConfig = null,
        ?ProcessManagerInterface $processManager = null,
        ?QueueProcessingStrategyInterface $queueProcessingStrategy = null,
        ?SignalDispatcherInterface $signalDispatcher = null,
        ?SystemResourcesManagerInterface $sysResManager = null,
        ?WorkerLoggerInterface $workerLogger = null
    ): TestableResourceAwareQueueWorker {
        return new TestableResourceAwareQueueWorker(
            $processManager ?? $this->createMock(ProcessManagerInterface::class),
            $queueConfig ?? $this->createQueueConfigMock(),
            $this->createMock(QueueClientInterface::class),
            [QueueWorkerFixtures::QUEUE_NAME],
            $queueProcessingStrategy ?? $this->createMock(QueueProcessingStrategyInterface::class),
            $signalDispatcher ?? $this->createMock(SignalDispatcherInterface::class),
            $sysResManager ?? $this->createSystemResourcesManagerMock(),
            $workerLogger ?? $this->createMock(WorkerLoggerInterface::class),
        );
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return \Spryker\Zed\Queue\QueueConfig|\PHPUnit\Framework\MockObject\MockObject
     */
    protected function createQueueConfigMock(array $overrides = []): QueueConfig|MockObject
    {
        $defaults = [
            'getQueueWorkerMaxProcesses' => static::MAX_PROCESSES,
            'getSignalsForGracefulWorkerShutdown' => static::GRACEFUL_SHUTDOWN_SIGNALS,
            'getQueueWorkerMaxThreshold' => static::MAX_THRESHOLD_SECONDS,
            'getQueueWorkerInterval' => static::WORKER_INTERVAL_MILLISECONDS,
            'getDelayWhenQueueIsNotEmptyMilliseconds' => QueueWorkerFixtures::NO_SLEEP_MILLISECONDS,
            'shouldIgnoreNotDetectedFreeMemory' => false,
            'memoryReadProcessTimeout' => static::MEMORY_READ_TIMEOUT_SECONDS,
            'maxAllowedWorkerMemoryGrowthFactor' => static::MAX_MEMORY_GROWTH_FACTOR,
            'getQueueWorkerCheckProcessesCompleteInterval' => QueueWorkerFixtures::NO_SLEEP_MILLISECONDS,
            'isQueueWorkerWaitLimitEnabled' => true,
            'getQueueWorkerMaxWaitingSeconds' => 0,
            'getStoreQueueWorkerCommandPattern' => 'APPLICATION_STORE=%s %s %s',
            'getQueueWorkerCommandPattern' => '%s %s',
        ];

        $queueConfigMock = $this->createMock(QueueConfig::class);

        foreach (array_merge($defaults, $overrides) as $method => $value) {
            $queueConfigMock->method($method)->willReturn($value);
        }

        return $queueConfigMock;
    }

    protected function createSystemResourcesManagerMock(
        bool $enoughResources = true,
        int $ownPeakMemoryGrowth = 0
    ): SystemResourcesManagerInterface|MockObject {
        $mock = $this->createMock(SystemResourcesManagerInterface::class);
        $mock->method('enoughResources')->willReturn($enoughResources);
        $mock->method('getOwnPeakMemoryGrowth')->willReturn($ownPeakMemoryGrowth);
        $mock->method('getFreeMemory')->willReturn(1024);

        return $mock;
    }

    protected function createQueuemetrics(
        string $queueName = QueueWorkerFixtures::QUEUE_NAME,
        ?string $storeName = self::STORE_NAME,
        ?string $regionName = self::REGION_NAME,
        int $messageCount = 100
    ): QueueMetrics {
        return (new QueueMetrics())
            ->setQueueName($queueName)
            ->setStoreName($storeName)
            ->setRegionName($regionName)
            ->setMessageCount($messageCount)
            ->setBatchSize(10)
            ->setMessageToChunkSizeRatio(10)
            ->setPriority(0);
    }
}
