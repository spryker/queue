<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Business\Worker;

use Codeception\Test\Unit;
use Generated\Shared\Transfer\QueueReceiveMessageTransfer;
use Generated\Shared\Transfer\QueueSendMessageTransfer;
use PHPUnit\Framework\MockObject\MockObject;
use Spryker\Client\Queue\QueueClientInterface;
use Spryker\Shared\Queue\QueueConfig as SharedQueueConfig;
use Spryker\Zed\Queue\Business\Process\ProcessManagerInterface;
use Spryker\Zed\Queue\Business\Reader\QueueConfigReaderInterface;
use Spryker\Zed\Queue\Business\SignalHandler\SignalDispatcherInterface;
use Spryker\Zed\Queue\Business\Worker\WorkerProgressBarInterface;
use Spryker\Zed\Queue\Dependency\Plugin\QueueMessageProcessorPluginInterface;
use Spryker\Zed\Queue\QueueConfig;
use Spryker\Zed\QueueExtension\Dependency\Plugin\QueueMessageCheckerPluginInterface;
use SprykerTest\Zed\Queue\Helper\FakeBulkQueueMessageCheckerPlugin;
use SprykerTest\Zed\Queue\Helper\FakeProcess;
use SprykerTest\Zed\Queue\Helper\QueueWorkerFixtures;
use SprykerTest\Zed\Queue\Helper\TestableWorker;

/**
 * Auto-generated group annotations
 *
 * @group SprykerTest
 * @group Zed
 * @group Queue
 * @group Business
 * @group Worker
 * @group WorkerTest
 * Add your own group annotations below this line
 */
class WorkerTest extends Unit
{
    /**
     * @var \SprykerTest\Zed\Queue\QueueBusinessTester
     */
    protected $tester;

    /**
     * @var string
     */
    protected const LOG_FILE_NAME = 'queue.log';

    /**
     * Project configuration expressed as fixtures, not magic literals.
     *
     * @var int
     */
    protected const MAX_TOTAL_PROCESSES = 4;

    /**
     * @var int
     */
    protected const MAX_WORKERS_PER_QUEUE = 3;

    /**
     * @var int
     */
    protected const CHUNK_SIZE_FROM_CONFIG = 450;

    /**
     * @var int
     */
    protected const CHUNK_SIZE_FROM_PLUGIN = 100;

    /**
     * @dataProvider continueExecutionDataProvider
     *
     * @param array<string, mixed> $options
     *
     * @return void
     */
    public function testContinueExecution(int $totalPassedSeconds, int $maxThreshold, array $options, bool $expected): void
    {
        // Arrange
        $worker = $this->createWorker();

        // Act
        $shouldContinue = $worker->callContinueExecution($totalPassedSeconds, $maxThreshold, $options);

        // Assert
        $this->assertSame($expected, $shouldContinue);
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function continueExecutionDataProvider(): array
    {
        $stopWhenEmpty = [SharedQueueConfig::CONFIG_WORKER_STOP_WHEN_EMPTY => true];

        return [
            'below threshold continues' => [10, 59, [], true],
            'at threshold stops' => [59, 59, [], false],
            'above threshold stops' => [60, 59, [], false],
            'stop-when-empty overrides an exhausted threshold' => [600, 59, $stopWhenEmpty, true],
            'a zero threshold stops immediately' => [0, 0, [], false],
        ];
    }

    // -----------------------------------------------------------------------------------------
    // startProcesses(): the per-queue worker cap
    // -----------------------------------------------------------------------------------------

    public function testStartProcessesSpawnsUpToTheQueueCapMinusTheAlreadyBusyProcesses(): void
    {
        // Arrange
        $busyProcesses = 1;
        $expectedNewProcesses = static::MAX_WORKERS_PER_QUEUE - $busyProcesses;

        $processManagerMock = $this->createMock(ProcessManagerInterface::class);
        $processManagerMock->method('getBusyProcessNumber')->willReturn($busyProcesses);
        $processManagerMock
            ->expects($this->exactly($expectedNewProcesses))
            ->method('triggerQueueProcess')
            ->willReturn(new FakeProcess());

        $worker = $this->createWorker(
            processManager: $processManagerMock,
            queueClient: $this->createQueueClientMockWithMessage(),
        );

        // Act
        $queueProcesses = $worker->callStartProcesses(QueueWorkerFixtures::COMMAND, QueueWorkerFixtures::QUEUE_NAME);

        // Assert
        $this->assertSame($busyProcesses, $queueProcesses[TestableWorker::PROCESS_BUSY]);
        $this->assertSame($expectedNewProcesses, $queueProcesses[TestableWorker::PROCESS_NEW]);
        $this->assertCount($expectedNewProcesses, $queueProcesses[TestableWorker::PROCESSES_INSTANCES]);
    }

    public function testStartProcessesSpawnsNothingWhenTheQueueIsAlreadyAtItsCap(): void
    {
        // Arrange
        $processManagerMock = $this->createMock(ProcessManagerInterface::class);
        $processManagerMock->method('getBusyProcessNumber')->willReturn(static::MAX_WORKERS_PER_QUEUE);
        $processManagerMock->expects($this->never())->method('triggerQueueProcess');

        $worker = $this->createWorker(
            processManager: $processManagerMock,
            queueClient: $this->createQueueClientMockWithMessage(),
        );

        // Act
        $queueProcesses = $worker->callStartProcesses(QueueWorkerFixtures::COMMAND, QueueWorkerFixtures::QUEUE_NAME);

        // Assert
        $this->assertSame(0, $queueProcesses[TestableWorker::PROCESS_NEW]);
    }

    /**
     * The busy count can exceed the cap after a config change; the subtraction must not go negative.
     *
     * @return void
     */
    public function testStartProcessesClampsANegativeWorkerCountToZero(): void
    {
        // Arrange
        $processManagerMock = $this->createMock(ProcessManagerInterface::class);
        $processManagerMock->method('getBusyProcessNumber')->willReturn(static::MAX_WORKERS_PER_QUEUE + 5);
        $processManagerMock->expects($this->never())->method('triggerQueueProcess');

        $worker = $this->createWorker(
            processManager: $processManagerMock,
            queueClient: $this->createQueueClientMockWithMessage(),
        );

        // Act
        $queueProcesses = $worker->callStartProcesses(QueueWorkerFixtures::COMMAND, QueueWorkerFixtures::QUEUE_NAME);

        // Assert
        $this->assertSame(0, $queueProcesses[TestableWorker::PROCESS_NEW]);
    }

    /**
     * The classic worker peeks one message to decide whether the queue has work, then rejects it so the
     * spawned child process can consume it.
     *
     * @return void
     */
    public function testStartProcessesRejectsThePeekedMessageSoTheChildProcessCanConsumeIt(): void
    {
        // Arrange
        $queueReceiveMessageTransfer = $this->createQueueReceiveMessageTransferWithMessage();

        $queueClientMock = $this->createMock(QueueClientInterface::class);
        $queueClientMock->method('receiveMessage')->willReturn($queueReceiveMessageTransfer);
        $queueClientMock
            ->expects($this->once())
            ->method('reject')
            ->with($queueReceiveMessageTransfer);

        $processManagerMock = $this->createMock(ProcessManagerInterface::class);
        $processManagerMock->method('getBusyProcessNumber')->willReturn(0);
        $processManagerMock->method('triggerQueueProcess')->willReturn(new FakeProcess());

        $worker = $this->createWorker(processManager: $processManagerMock, queueClient: $queueClientMock);

        // Act
        $worker->callStartProcesses(QueueWorkerFixtures::COMMAND, QueueWorkerFixtures::QUEUE_NAME);

        // Assert
        // Handled by the `once()` expectation above.
    }

    public function testStartProcessesSpawnsNothingWhenThePeekFindsNoMessage(): void
    {
        // Arrange
        $queueClientMock = $this->createMock(QueueClientInterface::class);
        $queueClientMock->method('receiveMessage')->willReturn(new QueueReceiveMessageTransfer());
        $queueClientMock->expects($this->never())->method('reject');

        $processManagerMock = $this->createMock(ProcessManagerInterface::class);
        $processManagerMock->method('getBusyProcessNumber')->willReturn(0);
        $processManagerMock->expects($this->never())->method('triggerQueueProcess');

        $worker = $this->createWorker(processManager: $processManagerMock, queueClient: $queueClientMock);

        // Act
        $queueProcesses = $worker->callStartProcesses(QueueWorkerFixtures::COMMAND, QueueWorkerFixtures::QUEUE_NAME);

        // Assert
        $this->assertSame(0, $queueProcesses[TestableWorker::PROCESS_NEW]);
        $this->assertSame([], $queueProcesses[TestableWorker::PROCESSES_INSTANCES]);
    }

    /**
     * The bulk path already knows the queue has messages, so it must not peek at the broker again.
     *
     * @return void
     */
    public function testStartProcessesForKnownNonEmptyQueueSkipsTheMessagePeekEntirely(): void
    {
        // Arrange
        $queueClientMock = $this->createMock(QueueClientInterface::class);
        $queueClientMock->expects($this->never())->method('receiveMessage');
        $queueClientMock->expects($this->never())->method('reject');

        $processManagerMock = $this->createMock(ProcessManagerInterface::class);
        $processManagerMock->method('getBusyProcessNumber')->willReturn(0);
        $processManagerMock
            ->expects($this->exactly(static::MAX_WORKERS_PER_QUEUE))
            ->method('triggerQueueProcess')
            ->willReturn(new FakeProcess());

        $worker = $this->createWorker(processManager: $processManagerMock, queueClient: $queueClientMock);

        // Act
        $queueProcesses = $worker->callStartProcessesForKnownNonEmptyQueue(QueueWorkerFixtures::COMMAND, QueueWorkerFixtures::QUEUE_NAME);

        // Assert
        $this->assertSame(static::MAX_WORKERS_PER_QUEUE, $queueProcesses[TestableWorker::PROCESS_NEW]);
    }

    // -----------------------------------------------------------------------------------------
    // executeOperation(): bulk check vs per-queue check
    // -----------------------------------------------------------------------------------------

    public function testExecuteOperationFallsBackToThePerQueueCheckWhenBulkCheckIsDisabled(): void
    {
        // Arrange
        // A plugin that would serve the bulk path if it were ever consulted.
        $bulkPlugin = new FakeBulkQueueMessageCheckerPlugin([QueueWorkerFixtures::QUEUE_NAME => 999]);

        $queueClientMock = $this->createQueueClientMockWithMessage();

        $processManagerMock = $this->createMock(ProcessManagerInterface::class);
        $processManagerMock->method('getBusyProcessNumber')->willReturn(0);
        $processManagerMock->method('triggerQueueProcess')->willReturn(new FakeProcess());

        $worker = $this->createWorker(
            queueConfig: $this->createQueueConfigMock(['isQueueBulkMessageCheckEnabled' => false]),
            processManager: $processManagerMock,
            queueClient: $queueClientMock,
            queueMessageCheckerPlugins: [$bulkPlugin],
        );

        // Act
        $processes = $worker->callExecuteOperation(QueueWorkerFixtures::COMMAND);

        // Assert
        // The per-queue path peeks the broker via the queue client; the bulk path never would.
        $this->assertCount(static::MAX_WORKERS_PER_QUEUE, $processes);
    }

    public function testExecuteOperationWithBulkCheckSkipsQueuesReportingNoReadyMessages(): void
    {
        // Arrange
        $bulkPluginMock = $this->createBulkCheckerPluginMock([
            QueueWorkerFixtures::QUEUE_NAME => 0,
            QueueWorkerFixtures::OTHER_QUEUE_NAME => 25,
        ]);

        $processManagerMock = $this->createMock(ProcessManagerInterface::class);
        $processManagerMock->method('getBusyProcessNumber')->willReturn(0);
        $processManagerMock
            ->expects($this->exactly(static::MAX_WORKERS_PER_QUEUE))
            ->method('triggerQueueProcess')
            ->with($this->anything(), QueueWorkerFixtures::OTHER_QUEUE_NAME)
            ->willReturn(new FakeProcess());

        $worker = $this->createWorker(
            processManager: $processManagerMock,
            queueNames: [QueueWorkerFixtures::QUEUE_NAME, QueueWorkerFixtures::OTHER_QUEUE_NAME],
            queueMessageCheckerPlugins: [$bulkPluginMock],
        );

        // Act
        $processes = $worker->callExecuteOperationWithBulkCheck(QueueWorkerFixtures::COMMAND);

        // Assert
        $this->assertCount(static::MAX_WORKERS_PER_QUEUE, $processes);
    }

    public function testExecuteOperationWithBulkCheckIgnoresQueuesTheWorkerWasNotConfiguredFor(): void
    {
        // Arrange
        $bulkPluginMock = $this->createBulkCheckerPluginMock(['some-foreign-queue' => 500]);

        $processManagerMock = $this->createMock(ProcessManagerInterface::class);
        $processManagerMock->method('getBusyProcessNumber')->willReturn(0);
        $processManagerMock->expects($this->never())->method('triggerQueueProcess');

        $worker = $this->createWorker(
            processManager: $processManagerMock,
            queueMessageCheckerPlugins: [$bulkPluginMock],
        );

        // Act
        $processes = $worker->callExecuteOperationWithBulkCheck(QueueWorkerFixtures::COMMAND);

        // Assert
        $this->assertSame([], $processes);
    }

    public function testExecuteOperationWithBulkCheckReturnsNullWhenNoApplicablePluginExists(): void
    {
        // Arrange
        $worker = $this->createWorker(queueMessageCheckerPlugins: []);

        // Act
        $processes = $worker->callExecuteOperationWithBulkCheck(QueueWorkerFixtures::COMMAND);

        // Assert
        $this->assertNull(
            $processes,
            'Returning null is the signal to fall back to the per-queue check, and differs from an empty result.',
        );
    }

    public function testExecuteOperationStopsStartingProcessesOnceTheParallelLimitIsReached(): void
    {
        // Arrange
        $queueNames = ['queue-a', 'queue-b', 'queue-c', 'queue-d', 'queue-e'];

        $processManagerMock = $this->createMock(ProcessManagerInterface::class);
        $processManagerMock->method('getBusyProcessNumber')->willReturn(0);
        $processManagerMock->method('triggerQueueProcess')->willReturn(new FakeProcess());

        $worker = $this->createWorker(
            queueConfig: $this->createQueueConfigMock(['isQueueBulkMessageCheckEnabled' => false]),
            processManager: $processManagerMock,
            queueClient: $this->createQueueClientMockWithMessage(),
            queueNames: $queueNames,
        );

        // Act
        $processes = $worker->callExecuteOperation(QueueWorkerFixtures::COMMAND);

        // Assert
        // Each queue contributes MAX_WORKERS_PER_QUEUE (3); the loop breaks once the running total
        // reaches MAX_TOTAL_PROCESSES (4), which happens after the second queue.
        $this->assertCount(2 * static::MAX_WORKERS_PER_QUEUE, $processes);
        $this->assertGreaterThanOrEqual(static::MAX_TOTAL_PROCESSES, count($processes));
    }

    // -----------------------------------------------------------------------------------------
    // buildProcessCommand()
    // -----------------------------------------------------------------------------------------

    public function testBuildProcessCommandRedirectsStderrIntoStdout(): void
    {
        // Arrange
        $worker = $this->createWorker(queueConfig: $this->createQueueConfigMock(['getQueueWorkerLogStatus' => false]));

        // Act
        $processCommand = $worker->callBuildProcessCommand(QueueWorkerFixtures::COMMAND, QueueWorkerFixtures::QUEUE_NAME);

        // Assert
        $this->assertSame(
            sprintf('%s %s 2>&1', QueueWorkerFixtures::COMMAND, QueueWorkerFixtures::QUEUE_NAME),
            $processCommand,
        );
    }

    public function testBuildProcessCommandTeesIntoTheLogFileWhenWorkerLoggingIsEnabled(): void
    {
        // Arrange
        $worker = $this->createWorker(queueConfig: $this->createQueueConfigMock([
            'getQueueWorkerLogStatus' => true,
            'getQueueWorkerOutputFileName' => static::LOG_FILE_NAME,
        ]));

        // Act
        $processCommand = $worker->callBuildProcessCommand(QueueWorkerFixtures::COMMAND, QueueWorkerFixtures::QUEUE_NAME);

        // Assert
        $this->assertSame(
            sprintf('%s %s 2>&1 | tee -a %s', QueueWorkerFixtures::COMMAND, QueueWorkerFixtures::QUEUE_NAME, static::LOG_FILE_NAME),
            $processCommand,
        );
    }

    // -----------------------------------------------------------------------------------------
    // getQueueBatchSize()
    // -----------------------------------------------------------------------------------------

    public function testGetQueueBatchSizePrefersTheConfigChunkSizeMapOverThePluginValue(): void
    {
        // Arrange
        $worker = $this->createWorker(
            queueConfig: $this->createQueueConfigMock([
                'getQueueMessageChunkSizeMap' => [QueueWorkerFixtures::QUEUE_NAME => static::CHUNK_SIZE_FROM_CONFIG],
            ]),
            messageProcessorPlugins: [QueueWorkerFixtures::QUEUE_NAME => $this->createMessageProcessorPluginMock()],
        );

        // Act
        $batchSize = $worker->callGetQueueBatchSize(QueueWorkerFixtures::QUEUE_NAME);

        // Assert
        $this->assertSame(static::CHUNK_SIZE_FROM_CONFIG, $batchSize);
    }

    public function testGetQueueBatchSizeFallsBackToThePluginChunkSize(): void
    {
        // Arrange
        $worker = $this->createWorker(
            messageProcessorPlugins: [QueueWorkerFixtures::QUEUE_NAME => $this->createMessageProcessorPluginMock()],
        );

        // Act
        $batchSize = $worker->callGetQueueBatchSize(QueueWorkerFixtures::QUEUE_NAME);

        // Assert
        $this->assertSame(static::CHUNK_SIZE_FROM_PLUGIN, $batchSize);
    }

    public function testGetQueueBatchSizeIsNullWhenNeitherConfigNorPluginDefinesOne(): void
    {
        // Arrange
        $worker = $this->createWorker();

        // Act
        $batchSize = $worker->callGetQueueBatchSize(QueueWorkerFixtures::QUEUE_NAME);

        // Assert
        $this->assertNull($batchSize);
    }

    // -----------------------------------------------------------------------------------------
    // Adapter resolution
    // -----------------------------------------------------------------------------------------

    public function testGetAdapterNameReadsTheAdapterOfTheFirstConfiguredQueue(): void
    {
        // Arrange
        $worker = $this->createWorker();

        // Act
        $adapterName = $worker->getAdapterName();

        // Assert
        $this->assertSame(QueueWorkerFixtures::ADAPTER_NAME, $adapterName);
    }

    public function testGetQueueConfigurationFallsBackToTheDefaultAdapterBlockForAnUnlistedQueue(): void
    {
        // Arrange
        $worker = $this->createWorker();

        // Act
        $queueConfiguration = $worker->callGetQueueConfiguration('a-queue-nobody-configured');

        // Assert
        $this->assertSame(
            QueueWorkerFixtures::ADAPTER_NAME,
            $queueConfiguration[SharedQueueConfig::CONFIG_QUEUE_ADAPTER],
        );
    }

    // -----------------------------------------------------------------------------------------
    // Empty-queue detection
    // -----------------------------------------------------------------------------------------

    public function testIsEmptyQueueIsFalseWhileProcessesAreStillPending(): void
    {
        // Arrange
        $checkerPluginMock = $this->createMock(QueueMessageCheckerPluginInterface::class);
        $checkerPluginMock->expects($this->never())->method('areQueuesEmpty');

        $worker = $this->createWorker(queueMessageCheckerPlugins: [$checkerPluginMock]);

        // Act
        $isEmpty = $worker->callIsEmptyQueue(
            [new FakeProcess()],
            [SharedQueueConfig::CONFIG_WORKER_STOP_WHEN_EMPTY => true],
        );

        // Assert
        $this->assertFalse($isEmpty, 'Pending work short-circuits the broker check entirely.');
    }

    public function testIsEmptyQueueIsFalseWhenStopWhenEmptyIsNotRequested(): void
    {
        // Arrange
        $worker = $this->createWorker();

        // Act
        $isEmpty = $worker->callIsEmptyQueue([], []);

        // Assert
        $this->assertFalse($isEmpty);
    }

    public function testAreQueuesEmptyDelegatesToTheFirstApplicableCheckerPlugin(): void
    {
        // Arrange
        $notApplicablePluginMock = $this->createMock(QueueMessageCheckerPluginInterface::class);
        $notApplicablePluginMock->method('isApplicable')->willReturn(false);
        $notApplicablePluginMock->expects($this->never())->method('areQueuesEmpty');

        $applicablePluginMock = $this->createMock(QueueMessageCheckerPluginInterface::class);
        $applicablePluginMock->method('isApplicable')->willReturn(true);
        $applicablePluginMock
            ->expects($this->once())
            ->method('areQueuesEmpty')
            ->with([QueueWorkerFixtures::QUEUE_NAME])
            ->willReturn(false);

        $worker = $this->createWorker(
            queueMessageCheckerPlugins: [$notApplicablePluginMock, $applicablePluginMock],
        );

        // Act
        $areQueuesEmpty = $worker->callAreQueuesEmpty();

        // Assert
        $this->assertFalse($areQueuesEmpty);
    }

    /**
     * With no plugin able to answer, the worker assumes the queues are empty and is free to stop.
     *
     * @return void
     */
    public function testAreQueuesEmptyDefaultsToTrueWhenNoPluginIsApplicable(): void
    {
        // Arrange
        $worker = $this->createWorker(queueMessageCheckerPlugins: []);

        // Act
        $areQueuesEmpty = $worker->callAreQueuesEmpty();

        // Assert
        $this->assertTrue($areQueuesEmpty);
    }

    // -----------------------------------------------------------------------------------------
    // getPendingProcesses()
    // -----------------------------------------------------------------------------------------

    public function testGetPendingProcessesKeepsOnlyTheProcessesTheProcessManagerReportsAsRunning(): void
    {
        // Arrange
        $runningProcess = new FakeProcess(pid: 111);
        $finishedProcess = new FakeProcess(pid: 222);

        $processManagerMock = $this->createMock(ProcessManagerInterface::class);
        $processManagerMock
            ->method('isProcessRunning')
            ->willReturnCallback(fn (?int $pid): bool => $pid === 111);

        $worker = $this->createWorker(processManager: $processManagerMock);

        // Act
        $pendingProcesses = $worker->callGetPendingProcesses([$runningProcess, $finishedProcess]);

        // Assert
        $this->assertSame([$runningProcess], $pendingProcesses);
    }

    // -----------------------------------------------------------------------------------------
    // waitForPendingProcesses()
    // -----------------------------------------------------------------------------------------

    public function testWaitForPendingProcessesReturnsImmediatelyWhenThereIsNothingToWaitFor(): void
    {
        // Arrange
        $processManagerMock = $this->createMock(ProcessManagerInterface::class);
        $processManagerMock->expects($this->never())->method('isProcessRunning');
        $processManagerMock->expects($this->never())->method('flushAllWorkerProcesses');

        $worker = $this->createWorker(processManager: $processManagerMock);

        // Act
        $worker->callWaitForPendingProcesses([], QueueWorkerFixtures::COMMAND, 1, QueueWorkerFixtures::NO_SLEEP_MILLISECONDS);

        // Assert
        // Handled by the `never()` expectations above.
    }

    public function testWaitForPendingProcessesReturnsOnceEveryProcessHasFinished(): void
    {
        // Arrange
        $processManagerMock = $this->createMock(ProcessManagerInterface::class);
        $processManagerMock->method('isProcessRunning')->willReturn(false);
        $processManagerMock->expects($this->never())->method('flushAllWorkerProcesses');

        $worker = $this->createWorker(
            queueConfig: $this->createQueueConfigMock(['isQueueWorkerWaitLimitEnabled' => false]),
            processManager: $processManagerMock,
        );

        // Act
        $worker->callWaitForPendingProcesses([new FakeProcess()], QueueWorkerFixtures::COMMAND, 1, QueueWorkerFixtures::NO_SLEEP_MILLISECONDS);

        // Assert
        // Handled by the `never()` expectation above: no kill was needed.
    }

    public function testWaitForPendingProcessesKillsEverythingOnceTheRoundLimitIsExceeded(): void
    {
        // Arrange
        $maxWaitRounds = 2;

        $processManagerMock = $this->createMock(ProcessManagerInterface::class);
        $processManagerMock->method('isProcessRunning')->willReturn(true);
        $processManagerMock->expects($this->once())->method('flushAllWorkerProcesses');

        $worker = $this->createWorker(
            queueConfig: $this->createQueueConfigMock([
                'isQueueWorkerWaitLimitEnabled' => true,
                'getQueueWorkerMaxWaitingRounds' => $maxWaitRounds,
                'getQueueWorkerMaxWaitingSeconds' => 3600,
            ]),
            processManager: $processManagerMock,
        );

        // Act
        $worker->callWaitForPendingProcesses(
            [new FakeProcess()],
            QueueWorkerFixtures::COMMAND,
            $maxWaitRounds + 1,
            QueueWorkerFixtures::NO_SLEEP_MILLISECONDS,
        );

        // Assert
        // Handled by the `once()` expectation above.
    }

    /**
     * The wait-limit clock starts when the wait starts, and is not shared with any earlier wait.
     *
     * @return void
     */
    public function testEachWaitGetsItsOwnClockRatherThanInheritingAnEarlierOne(): void
    {
        // Arrange
        $maxwaitseconds = 1;
        $queueConfigOverrides = [
            'isQueueWorkerWaitLimitEnabled' => true,
            'getQueueWorkerMaxWaitingSeconds' => $maxwaitseconds,
            // High enough that the round check can never be what ends a wait.
            'getQueueWorkerMaxWaitingRounds' => 1000,
        ];

        $firstProcessManagerMock = $this->createMock(ProcessManagerInterface::class);
        $firstProcessManagerMock->method('isProcessRunning')->willReturn(false);

        $firstWorker = $this->createWorker(
            queueConfig: $this->createQueueConfigMock($queueConfigOverrides),
            processManager: $firstProcessManagerMock,
        );

        // Act
        $firstWorker->callWaitForPendingProcesses([new FakeProcess()], QueueWorkerFixtures::COMMAND, 1, QueueWorkerFixtures::NO_SLEEP_MILLISECONDS);

        usleep(($maxwaitseconds * 1000000) + 50000);

        // A brand-new worker, on its very first wait, with a full second of budget of its own.
        $secondProcessManagerMock = $this->createMock(ProcessManagerInterface::class);
        $secondProcessManagerMock->expects($this->never())->method('flushAllWorkerProcesses');
        $secondProcessManagerMock->method('isProcessRunning')->willReturn(false);

        $secondWorker = $this->createWorker(
            queueConfig: $this->createQueueConfigMock($queueConfigOverrides),
            processManager: $secondProcessManagerMock,
        );

        $secondWorker->callWaitForPendingProcesses([new FakeProcess()], QueueWorkerFixtures::COMMAND, 1, QueueWorkerFixtures::NO_SLEEP_MILLISECONDS);

        // Assert
        // The second worker polls its processes and returns normally. It never reaches the kill
        // path, which is what it would do if it had inherited the first worker's expired clock.
    }

    // -----------------------------------------------------------------------------------------
    // start()
    // -----------------------------------------------------------------------------------------

    public function testStartRegistersKillSignalHandlersAndFlushesZombiesOnTheFirstRoundOnly(): void
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

    public function testStartDoesNotRegisterKillSignalHandlersOnLaterRounds(): void
    {
        // Arrange
        $processManagerMock = $this->createMock(ProcessManagerInterface::class);
        $processManagerMock->expects($this->never())->method('flushZombieProcesses');

        $worker = $this->createWorker(processManager: $processManagerMock);
        $worker->setLoopIterations(0);

        // Act
        $worker->start(QueueWorkerFixtures::COMMAND, [], 2);

        // Assert
        $this->assertSame(0, $worker->getRegisterKillSignalHandlersCallCount());
    }

    /**
     * Pins the guard added for the premature-stop bug: when processes finished during this very iteration,
     * the worker must not conclude the queues are empty, because messages those processes published
     * downstream are not visible to the broker check yet.
     *
     * @return void
     */
    public function testStartDoesNotStopOnTheIterationWhereProcessesJustFinished(): void
    {
        // Arrange
        $options = [SharedQueueConfig::CONFIG_WORKER_STOP_WHEN_EMPTY => true];

        $checkerPluginMock = $this->createMock(QueueMessageCheckerPluginInterface::class);
        $checkerPluginMock->method('isApplicable')->willReturn(true);
        $checkerPluginMock->method('areQueuesEmpty')->willReturn(true);

        // Running on the first iteration, finished on the second.
        $isProcessRunningReturns = [true, false];
        $processManagerMock = $this->createMock(ProcessManagerInterface::class);
        $processManagerMock->method('getBusyProcessNumber')->willReturn(0);
        $processManagerMock->method('triggerQueueProcess')->willReturn(new FakeProcess());
        $processManagerMock
            ->method('isProcessRunning')
            ->willReturnCallback(function () use (&$isProcessRunningReturns): bool {
                return (bool)array_shift($isProcessRunningReturns);
            });
        $bulkPluginMock = $this->createBulkCheckerPluginMock([QueueWorkerFixtures::QUEUE_NAME => 10]);

        $worker = $this->createWorker(
            processManager: $processManagerMock,
            queueMessageCheckerPlugins: [$bulkPluginMock, $checkerPluginMock],
        );
        // Two iterations: process pending, then process just finished.
        $worker->setLoopIterations(2);

        // Act
        $worker->start(QueueWorkerFixtures::COMMAND, $options);

        // Assert
        $this->assertSame(
            2,
            $worker->getExecuteUsleepCallCount(),
            'Both iterations must complete: the just-finished iteration may not short-circuit the loop.',
        );
    }

    // -----------------------------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------------------------

    /**
     * @param array<\Spryker\Zed\QueueExtension\Dependency\Plugin\QueueMessageCheckerPluginInterface> $queueMessageCheckerPlugins
     * @param array<\Spryker\Zed\Queue\Dependency\Plugin\QueueMessageProcessorPluginInterface> $messageProcessorPlugins
     * @param array<string>|null $queueNames
     *
     * @return \SprykerTest\Zed\Queue\Helper\TestableWorker
     */
    protected function createWorker(
        ?QueueConfig $queueConfig = null,
        ?ProcessManagerInterface $processManager = null,
        ?QueueClientInterface $queueClient = null,
        ?array $queueNames = null,
        ?QueueConfigReaderInterface $queueConfigReader = null,
        array $queueMessageCheckerPlugins = [],
        array $messageProcessorPlugins = []
    ): TestableWorker {
        return new TestableWorker(
            $processManager ?? $this->createMock(ProcessManagerInterface::class),
            $queueConfig ?? $this->createQueueConfigMock(),
            $this->createMock(WorkerProgressBarInterface::class),
            $queueClient ?? $this->createMock(QueueClientInterface::class),
            $queueNames ?? [QueueWorkerFixtures::QUEUE_NAME],
            $this->createMock(SignalDispatcherInterface::class),
            $queueConfigReader ?? $this->createQueueConfigReaderMock(),
            $queueMessageCheckerPlugins,
            $messageProcessorPlugins,
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
            'getSignalsForGracefulWorkerShutdown' => [],
            'getQueueWorkerMaxThreshold' => 59,
            'getQueueWorkerInterval' => QueueWorkerFixtures::NO_SLEEP_MILLISECONDS,
            'getDelayWhenQueueIsNotEmptyMilliseconds' => QueueWorkerFixtures::NO_SLEEP_MILLISECONDS,
            'getQueueProcessTriggerInterval' => QueueWorkerFixtures::NO_SLEEP_MILLISECONDS,
            'getQueueWorkerMaxProcesses' => static::MAX_TOTAL_PROCESSES,
            'getQueueWorkerLogStatus' => false,
            'getQueueWorkerOutputFileName' => static::LOG_FILE_NAME,
            'getWorkerMessageCheckOption' => [],
            'getQueueMessageChunkSizeMap' => [],
            'isQueueBulkMessageCheckEnabled' => true,
            'isQueueWorkerWaitLimitEnabled' => false,
            'getQueueWorkerMaxWaitingSeconds' => 0,
            'getQueueWorkerMaxWaitingRounds' => 1,
            'getIsWorkerLoopEnabled' => false,
            'getQueueAdapterConfiguration' => [],
            'getDefaultQueueAdapterConfiguration' => [
                SharedQueueConfig::CONFIG_QUEUE_ADAPTER => QueueWorkerFixtures::ADAPTER_NAME,
                SharedQueueConfig::CONFIG_MAX_WORKER_NUMBER => static::MAX_WORKERS_PER_QUEUE,
            ],
        ];

        $queueConfigMock = $this->createMock(QueueConfig::class);

        foreach (array_merge($defaults, $overrides) as $method => $value) {
            $queueConfigMock->method($method)->willReturn($value);
        }

        return $queueConfigMock;
    }

    protected function createQueueConfigReaderMock(int $maxQueueWorker = self::MAX_WORKERS_PER_QUEUE): QueueConfigReaderInterface|MockObject
    {
        $mock = $this->createMock(QueueConfigReaderInterface::class);
        $mock->method('getMaxQueueWorkerByQueueName')->willReturn($maxQueueWorker);
        $mock->method('getQueueAdapter')->willReturn(QueueWorkerFixtures::ADAPTER_NAME);

        return $mock;
    }

    protected function createQueueClientMockWithMessage(): QueueClientInterface|MockObject
    {
        $mock = $this->createMock(QueueClientInterface::class);
        $mock->method('receiveMessage')->willReturn($this->createQueueReceiveMessageTransferWithMessage());

        return $mock;
    }

    protected function createQueueReceiveMessageTransferWithMessage(): QueueReceiveMessageTransfer
    {
        return (new QueueReceiveMessageTransfer())
            ->setQueueName(QueueWorkerFixtures::QUEUE_NAME)
            ->setQueueMessage((new QueueSendMessageTransfer())->setBody('{}'));
    }

    /**
     * @param array<string, int> $readyCountByQueueName
     *
     * @return \SprykerTest\Zed\Queue\Helper\FakeBulkQueueMessageCheckerPlugin
     */
    protected function createBulkCheckerPluginMock(array $readyCountByQueueName): FakeBulkQueueMessageCheckerPlugin
    {
        return new FakeBulkQueueMessageCheckerPlugin($readyCountByQueueName);
    }

    protected function createMessageProcessorPluginMock(): QueueMessageProcessorPluginInterface|MockObject
    {
        $mock = $this->createMock(QueueMessageProcessorPluginInterface::class);
        $mock->method('getChunkSize')->willReturn(static::CHUNK_SIZE_FROM_PLUGIN);

        return $mock;
    }
}
