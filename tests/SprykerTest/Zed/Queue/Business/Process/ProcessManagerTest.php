<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Business\Process;

use Codeception\Test\Unit;
use Orm\Zed\Queue\Persistence\SpyQueueProcessQuery;
use PHPUnit\Framework\MockObject\MockObject;
use Propel\Runtime\Collection\ArrayCollection;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use Spryker\Zed\Queue\Persistence\QueueQueryContainerInterface;
use Spryker\Zed\Queue\QueueConfig;
use SprykerTest\Zed\Queue\Helper\FakeProcess;
use SprykerTest\Zed\Queue\Helper\QueueWorkerFixtures;
use SprykerTest\Zed\Queue\Helper\TestableProcessManager;

/**
 * Auto-generated group annotations
 *
 * @group SprykerTest
 * @group Zed
 * @group Queue
 * @group Business
 * @group Process
 * @group ProcessManagerTest
 * Add your own group annotations below this line
 */
class ProcessManagerTest extends Unit
{
    /**
     * @var \SprykerTest\Zed\Queue\QueueBusinessTester
     */
    protected $tester;

    /**
     * @var string
     */
    protected const SERVER_ID = 'test-server';

    /**
     * @var string
     */
    protected const QUEUE_NAME = 'event';

    /**
     * @var string
     */
    protected const COMMAND = '/app/vendor/bin/console queue:task:start event';

    /**
     * Out of range for any operating system pid, so posix_kill can never reach a real process.
     *
     * @var int
     */
    protected const UNREACHABLE_PID = 2147483646;

    /**
     * ProcessManager throttles its own error logging through a static cache; reset it so one test cannot
     * silence the log line another test expects.
     *
     * @return void
     */
    protected function _before(): void
    {
        parent::_before();

        (new ReflectionClass(TestableProcessManager::class))
            ->getProperty('logCache')
            ->setValue(null, []);
    }

    // -----------------------------------------------------------------------------------------
    // triggerQueueProcess()
    // -----------------------------------------------------------------------------------------

    public function testTriggerQueueProcessStartsTheProcessAndReturnsIt(): void
    {
        // Arrange
        $process = new FakeProcess(isRunning: true, pid: 4242);
        $processManager = $this->createProcessManager()->setProcessToCreate($process);

        // Act
        $startedProcess = $processManager->triggerQueueProcess(static::COMMAND, static::QUEUE_NAME);

        // Assert
        $this->assertSame($process, $startedProcess);
        $this->assertSame(1, $process->getStartCallCount());
        $this->assertSame([static::COMMAND], $processManager->getCreatedCommands());
    }

    public function testTriggerQueueProcessRecordsTheProcessAgainstItsQueueAndServer(): void
    {
        // Arrange
        $pid = 4242;
        $processManager = $this->createProcessManager()
            ->setProcessToCreate(new FakeProcess(isRunning: true, pid: $pid));

        // Act
        $processManager->triggerQueueProcess(static::COMMAND, static::QUEUE_NAME);

        // Assert
        $savedProcesses = $processManager->getSavedProcesses();
        $this->assertCount(1, $savedProcesses);
        $this->assertSame(static::QUEUE_NAME, $savedProcesses[0]->getQueueName());
        $this->assertSame($pid, $savedProcesses[0]->getProcessPid());
        $this->assertSame(static::SERVER_ID, $savedProcesses[0]->getServerId());
    }

    /**
     * The resource-aware worker tracks its own pool in memory, so the per-process database row is skipped
     * entirely on that path.
     *
     * @return void
     */
    public function testTriggerQueueProcessWritesNoProcessRowWhenTheResourceAwareWorkerIsEnabled(): void
    {
        // Arrange
        $processManager = $this->createProcessManager(isResourceAwareQueueWorkerEnabled: true)
            ->setProcessToCreate(new FakeProcess(isRunning: true));

        // Act
        $processManager->triggerQueueProcess(static::COMMAND, static::QUEUE_NAME);

        // Assert
        $this->assertSame([], $processManager->getSavedProcesses());
    }

    public function testTriggerQueueProcessRecordsNothingWhenTheProcessFailedToStart(): void
    {
        // Arrange
        $processManager = $this->createProcessManager()
            ->setProcessToCreate(new FakeProcess(isRunning: false, exitCode: 1));

        // Act
        $processManager->triggerQueueProcess(static::COMMAND, static::QUEUE_NAME);

        // Assert
        $this->assertSame(
            [],
            $processManager->getSavedProcesses(),
            'A process that exited immediately is never recorded as busy.',
        );
    }

    /**
     * The failure log is throttled to one line per 30 seconds.
     *
     * @return void
     */
    public function testASecondQueuesSpawnFailureIsNotSuppressedByTheFirstQueues(): void
    {
        // Arrange
        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects($this->exactly(2))->method('log');

        $processManager = $this->createProcessManager(logger: $loggerMock)
            ->setProcessToCreate(new FakeProcess(isRunning: false, exitCode: 1));

        // Act
        $processManager->triggerQueueProcess(QueueWorkerFixtures::COMMAND, 'queue-a');
        $processManager->triggerQueueProcess(QueueWorkerFixtures::COMMAND, 'queue-b');

        // Assert
        // Handled by the `exactly(2)` expectation above.
    }

    public function testRepeatedFailuresOnTheSameQueueAreStillThrottled(): void
    {
        // Arrange
        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects($this->once())->method('log');

        $processManager = $this->createProcessManager(logger: $loggerMock)
            ->setProcessToCreate(new FakeProcess(isRunning: false, exitCode: 1));

        // Act
        $processManager->triggerQueueProcess(QueueWorkerFixtures::COMMAND, 'queue-a');
        $processManager->triggerQueueProcess(QueueWorkerFixtures::COMMAND, 'queue-a');
        $processManager->triggerQueueProcess(QueueWorkerFixtures::COMMAND, 'queue-a');

        // Assert
        // Handled by the `once()` expectation above: the throttle still does its job per queue.
    }

    // -----------------------------------------------------------------------------------------
    // Busy-process accounting
    // -----------------------------------------------------------------------------------------

    public function testGetBusyProcessNumberCountsOnlyTheProcessesStillAlive(): void
    {
        // Arrange
        $processManager = $this->createProcessManager(processIds: [11, 22, 33])
            ->setRunningPids([11, 33]);

        // Act
        $busyProcessNumber = $processManager->getBusyProcessNumber(static::QUEUE_NAME);

        // Assert
        $this->assertSame(2, $busyProcessNumber);
    }

    public function testGetBusyProcessNumberDeletesTheRowsOfProcessesThatHaveFinished(): void
    {
        // Arrange
        $queryContainerMock = $this->createQueryContainerMock([11, 22, 33]);
        $queryContainerMock
            ->expects($this->once())
            ->method('queryProcessesByProcessIds')
            ->with([22])
            ->willReturn($this->createQueryMock([]));

        $processManager = $this->createProcessManager(queryContainer: $queryContainerMock)
            ->setRunningPids([11, 33]);

        // Act
        $processManager->getBusyProcessNumber(static::QUEUE_NAME);

        // Assert
        // Handled by the `with([22])` expectation: only the dead pid is cleaned up.
    }

    public function testReleaseIdleProcessesLeavesTheTableAloneWhenEverythingIsStillRunning(): void
    {
        // Arrange
        $queryContainerMock = $this->createQueryContainerMock([11, 22]);
        $queryContainerMock->expects($this->never())->method('queryProcessesByProcessIds');

        $processManager = $this->createProcessManager(queryContainer: $queryContainerMock)
            ->setRunningPids([11, 22]);

        // Act
        $busyProcessNumber = $processManager->callReleaseIdleProcesses([11, 22]);

        // Assert
        $this->assertSame(2, $busyProcessNumber);
    }

    public function testGetRunningProcessPidsReturnsOnlyTheLivePids(): void
    {
        // Arrange
        $processManager = $this->createProcessManager(processIds: [11, 22, 33])
            ->setRunningPids([22]);

        // Act
        $runningPids = $processManager->getRunningProcessPids(static::QUEUE_NAME);

        // Assert
        $this->assertSame([22], $runningPids);
    }

    public function testIsProcessRunningIsFalseForANullPid(): void
    {
        // Arrange
        $processManager = $this->createProcessManager()->setRunningPids([11]);

        // Act & Assert
        $this->assertFalse($processManager->isProcessRunning(null));
    }

    /**
     * An image without procps, or a refused exec, previously read as "this process has finished" for every
     * pid.
     *
     * @return void
     */
    public function testAnUnusablePsIsReportedRatherThanReadAsEveryProcessHavingFinished(): void
    {
        // Arrange
        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects($this->once())->method('log');

        $processManager = $this->createProcessManager(logger: $loggerMock)
            ->setRunningPids([11])
            ->setPsAvailable(false);

        // Act
        $isRunning = $processManager->isProcessRunning(11);

        // Assert
        $this->assertFalse($isRunning);
    }

    public function testAWorkingPsReportsNothingExtra(): void
    {
        // Arrange
        $loggerMock = $this->createMock(LoggerInterface::class);
        $loggerMock->expects($this->never())->method('log');

        $processManager = $this->createProcessManager(logger: $loggerMock)->setRunningPids([11]);

        // Act & Assert
        $this->assertTrue($processManager->isProcessRunning(11));
        $this->assertFalse(
            $processManager->isProcessRunning(22),
            'A pid ps does not know about has genuinely finished; that is not an error.',
        );
    }

    // -----------------------------------------------------------------------------------------
    // Flushing
    // -----------------------------------------------------------------------------------------

    public function testFlushIdleProcessesDoesNothingWhenNoProcessesAreRegistered(): void
    {
        // Arrange
        $queryContainerMock = $this->createQueryContainerMock([]);
        $queryContainerMock->expects($this->never())->method('queryProcessesByProcessIds');

        $processManager = $this->createProcessManager(queryContainer: $queryContainerMock);

        // Act
        $processManager->flushIdleProcesses();

        // Assert
        // Handled by the `never()` expectation above.
    }

    public function testFlushAllWorkerProcessesDeletesEveryRegisteredProcessRow(): void
    {
        // Arrange
        $processIds = [static::UNREACHABLE_PID, static::UNREACHABLE_PID - 1];

        $queryContainerMock = $this->createQueryContainerMock($processIds, asArray: true);
        $queryContainerMock
            ->expects($this->once())
            ->method('queryProcessesByProcessIds')
            ->with($processIds)
            ->willReturn($this->createQueryMock([]));

        $processManager = $this->createProcessManager(queryContainer: $queryContainerMock);

        // Act
        $processManager->flushAllWorkerProcesses();

        // Assert
        // Handled by the `once()` expectation. The pids are deliberately out of range for any real
        // process, so the posix_kill calls cannot signal anything on the host running the tests.
    }

    public function testFlushZombieProcessesDeletesTheDefunctPidsItFound(): void
    {
        // Arrange
        $zombiePids = [static::UNREACHABLE_PID];

        $queryContainerMock = $this->createQueryContainerMock([]);
        $queryContainerMock
            ->expects($this->once())
            ->method('queryProcessesByProcessIds')
            ->with($zombiePids)
            ->willReturn($this->createQueryMock([]));

        $processManager = $this->createProcessManager(queryContainer: $queryContainerMock)
            ->setZombiePids($zombiePids);

        // Act
        $processManager->flushZombieProcesses();

        // Assert
        // Handled by the `once()` expectation above.
    }

    public function testFlushZombieProcessesDoesNothingWhenThereAreNoZombies(): void
    {
        // Arrange
        $queryContainerMock = $this->createQueryContainerMock([]);
        $queryContainerMock->expects($this->never())->method('queryProcessesByProcessIds');

        $processManager = $this->createProcessManager(queryContainer: $queryContainerMock)
            ->setZombiePids([]);

        // Act
        $processManager->flushZombieProcesses();

        // Assert
        // Handled by the `never()` expectation above.
    }

    // -----------------------------------------------------------------------------------------
    // Error buffer
    // -----------------------------------------------------------------------------------------

    public function testProcessOutputIsCapturedLineByLine(): void
    {
        // Arrange
        $processManager = $this->createProcessManager();

        // Act
        $processManager->callForwardOutputWithoutBootstrapInfo("first line\nsecond line\n");

        // Assert
        $this->assertSame(['first line', 'second line'], $processManager->getErrorBuffer());
    }

    /**
     * @dataProvider consoleBootstrapNoiseDataProvider
     *
     * @param string $line
     *
     * @return void
     */
    public function testConsoleBootstrapNoiseIsNotCapturedAsProcessOutput(string $line): void
    {
        // Arrange
        $processManager = $this->createProcessManager();

        // Act
        $processManager->callForwardOutputWithoutBootstrapInfo($line . "\n");

        // Assert
        $this->assertSame([], $processManager->getErrorBuffer());
    }

    /**
     * @return array<string, array<string>>
     */
    public function consoleBootstrapNoiseDataProvider(): array
    {
        return [
            'region banner' => ['Region: EU'],
            'code bucket banner' => ['Code bucket: DE'],
            'environment banner' => ['Environment: development'],
            'empty line' => [''],
        ];
    }

    public function testFlushErrorBufferReturnsTheCapturedLinesAndEmptiesTheBuffer(): void
    {
        // Arrange
        $processManager = $this->createProcessManager();
        $processManager->callForwardOutputWithoutBootstrapInfo("something broke\n");

        // Act
        $firstFlush = $processManager->flushErrorBuffer();
        $secondFlush = $processManager->flushErrorBuffer();

        // Assert
        $this->assertSame(['something broke'], $firstFlush);
        $this->assertSame([], $secondFlush, 'Errors are reported once, not on every display cycle.');
    }

    // -----------------------------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------------------------

    /**
     * @param array<int> $processIds
     * @param \Spryker\Zed\Queue\Persistence\QueueQueryContainerInterface|null $queryContainer
     * @param bool $isResourceAwareQueueWorkerEnabled
     * @param \Psr\Log\LoggerInterface|null $logger
     *
     * @return \SprykerTest\Zed\Queue\Helper\TestableProcessManager
     */
    protected function createProcessManager(
        array $processIds = [],
        ?QueueQueryContainerInterface $queryContainer = null,
        bool $isResourceAwareQueueWorkerEnabled = false,
        ?LoggerInterface $logger = null
    ): TestableProcessManager {
        $queueConfigMock = $this->createMock(QueueConfig::class);
        $queueConfigMock->method('isResourceAwareQueueWorkerEnabled')
            ->willReturn($isResourceAwareQueueWorkerEnabled);

        $processManager = new TestableProcessManager(
            $queryContainer ?? $this->createQueryContainerMock($processIds),
            static::SERVER_ID,
            $queueConfigMock,
        );

        if ($logger !== null) {
            $processManager->setLogger($logger);
        }

        return $processManager;
    }

    /**
     * @param array<int> $processIds
     * @param bool $asArray
     *
     * @return \Spryker\Zed\Queue\Persistence\QueueQueryContainerInterface|\PHPUnit\Framework\MockObject\MockObject
     */
    protected function createQueryContainerMock(
        array $processIds,
        bool $asArray = false
    ): QueueQueryContainerInterface|MockObject {
        $queryContainerMock = $this->createMock(QueueQueryContainerInterface::class);
        $queryContainerMock->method('queryProcessesByServerIdAndQueueName')
            ->willReturn($this->createQueryMock($processIds));
        $queryContainerMock->method('queryProcessesByServerId')
            ->willReturn($this->createQueryMock($processIds, $asArray));
        $queryContainerMock->method('queryProcessesByProcessIds')
            ->willReturn($this->createQueryMock([]));

        return $queryContainerMock;
    }

    /**
     * @param array<int> $processIds
     * @param bool $asArray
     *
     * @return \Orm\Zed\Queue\Persistence\SpyQueueProcessQuery|\PHPUnit\Framework\MockObject\MockObject
     */
    protected function createQueryMock(array $processIds, bool $asArray = false): SpyQueueProcessQuery|MockObject
    {
        $queryMock = $this->createMock(SpyQueueProcessQuery::class);
        $queryMock->method('setFormatter')->willReturnSelf();
        $queryMock->method('delete')->willReturn(count($processIds));

        if ($asArray) {
            $collectionMock = $this->createMock(ArrayCollection::class);
            $collectionMock->method('toArray')->willReturn($processIds);
            $queryMock->method('find')->willReturn($collectionMock);

            return $queryMock;
        }

        $queryMock->method('find')->willReturn($processIds);

        return $queryMock;
    }
}
