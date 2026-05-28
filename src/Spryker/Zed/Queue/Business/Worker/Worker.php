<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Worker;

use Spryker\Client\Queue\QueueClientInterface;
use Spryker\Shared\Queue\QueueConfig as SharedQueueConfig;
use Spryker\Zed\Queue\Business\Process\ProcessManagerInterface;
use Spryker\Zed\Queue\Business\Reader\QueueConfigReaderInterface;
use Spryker\Zed\Queue\Business\SignalHandler\SignalDispatcherInterface;
use Spryker\Zed\Queue\QueueConfig;
use Spryker\Zed\QueueExtension\Dependency\Plugin\QueueBulkMessageCheckerPluginInterface;

/**
 * @method \Spryker\Zed\Queue\Business\QueueBusinessFactory getFactory()
 */
class Worker implements WorkerInterface
{
    /**
     * @var int
     */
    public const SECOND_TO_MILLISECONDS = 1000;

    /**
     * @var string
     */
    public const PROCESS_BUSY = 'busy';

    /**
     * @var string
     */
    public const PROCESS_NEW = 'new';

    /**
     * @var string
     */
    public const PROCESSES_INSTANCES = 'processes';

    /**
     * @var int
     */
    public const RETRY_INTERVAL_SECONDS = 5;

    /**
     * @var string
     */
    protected const QUEUE_DATA_KEY_QUEUE = 'queue';

    /**
     * @var string
     */
    protected const QUEUE_DATA_KEY_PROCESSES = 'processes';

    /**
     * @var string
     */
    protected const QUEUE_DATA_KEY_BATCH_SIZE = 'batchSize';

    /**
     * @var string
     */
    protected const QUEUE_DATA_KEY_RUNNING_PIDS = 'runningPids';

    // false = not yet resolved; null = resolved, no applicable plugin found
    protected QueueBulkMessageCheckerPluginInterface|false|null $cachedBulkCheckerPlugin = false;

    /**
     * @param \Spryker\Zed\Queue\Business\Process\ProcessManagerInterface $processManager
     * @param \Spryker\Zed\Queue\QueueConfig $queueConfig
     * @param \Spryker\Zed\Queue\Business\Worker\WorkerProgressBarInterface $workerProgressBar
     * @param \Spryker\Client\Queue\QueueClientInterface $queueClient
     * @param array<string> $queueNames
     * @param \Spryker\Zed\Queue\Business\SignalHandler\SignalDispatcherInterface $signalDispatcher
     * @param \Spryker\Zed\Queue\Business\Reader\QueueConfigReaderInterface $queueConfigReader
     * @param array<\Spryker\Zed\QueueExtension\Dependency\Plugin\QueueMessageCheckerPluginInterface> $queueMessageCheckerPlugins
     * @param array<\Spryker\Zed\Queue\Dependency\Plugin\QueueMessageProcessorPluginInterface> $messageProcessorPlugins
     */
    public function __construct(
        protected ProcessManagerInterface $processManager,
        protected QueueConfig $queueConfig,
        protected WorkerProgressBarInterface $workerProgressBar,
        protected QueueClientInterface $queueClient,
        protected array $queueNames,
        protected SignalDispatcherInterface $signalDispatcher,
        protected QueueConfigReaderInterface $queueConfigReader,
        protected array $queueMessageCheckerPlugins,
        protected array $messageProcessorPlugins
    ) {
        $this->signalDispatcher->dispatch($this->queueConfig->getSignalsForGracefulWorkerShutdown());
    }

    /**
     * @param string $command
     * @param array<string, mixed> $options
     * @param int $round
     * @param array<\Symfony\Component\Process\Process> $processes
     *
     * @return void
     */
    public function start(string $command, array $options = [], int $round = 1, array $processes = []): void
    {
        $loopPassedSeconds = 0;
        $totalPassedSeconds = 0;
        $pendingProcesses = [];
        $previousPendingProcesses = [];
        $roundStartTime = $this->getFreshMicroTime();
        $startTime = $this->getFreshMicroTime();
        $maxThreshold = (int)$this->queueConfig->getQueueWorkerMaxThreshold();
        $delayIntervalMilliseconds = (int)$this->queueConfig->getQueueWorkerInterval();

        $this->workerProgressBar->start($maxThreshold, $round);

        if ($round === 1) {
            $this->registerKillSignalHandlers();
            $this->processManager->flushZombieProcesses();
        }

        while ($this->continueExecution($totalPassedSeconds, $maxThreshold, $options)) {
            $elapsedTime = $this->getFreshMicroTime() - $roundStartTime;
            $shouldUpdateDisplay = $loopPassedSeconds >= 1;

            $processes = array_merge($this->executeOperation($command, $elapsedTime, $shouldUpdateDisplay), $processes);
            $pendingProcesses = $this->getPendingProcesses($processes);

            // When processes just finished, skip the stop check for this iteration.
            // executeOperation ran before those processes exited, so any messages they
            // published to downstream queues will only be visible on the next iteration.
            $justFinished = (bool)$previousPendingProcesses && !$pendingProcesses;
            $previousPendingProcesses = $pendingProcesses;

            if (!$justFinished && $this->isEmptyQueue($pendingProcesses, $options)) {
                $this->workerProgressBar->finish();
                $this->processManager->flushIdleProcesses();

                return;
            }

            $this->monitorRunningProcesses($pendingProcesses);

            if ($loopPassedSeconds >= 1) {
                $totalPassedSeconds++;
                $startTime = $this->getFreshMicroTime();
            }
            $this->executeUsleep($delayIntervalMilliseconds, $processes);
            $loopPassedSeconds = $this->getFreshMicroTime() - $startTime;
        }

        $this->workerProgressBar->finish();
        $this->processManager->flushIdleProcesses();
        $this->waitForPendingProcesses($pendingProcesses, $command, $round, $delayIntervalMilliseconds, $options);
    }

    /**
     * Monitor running processes: trigger output callbacks
     *
     * @param array<\Symfony\Component\Process\Process> $processes
     *
     * @return void
     */
    protected function monitorRunningProcesses(array $processes): void
    {
        foreach ($processes as $process) {
            if (!$process->isRunning()) {
                continue;
            }

            $process->getIncrementalOutput();
        }
    }

    /**
     * @param int $totalPassedSeconds
     * @param int $maxThreshold
     * @param array<string, mixed> $options
     *
     * @return bool
     */
    protected function continueExecution(int $totalPassedSeconds, int $maxThreshold, array $options): bool
    {
        return $totalPassedSeconds < $maxThreshold || $this->isWorkerStopsWhenEmptyQueueEnabled($options);
    }

    /**
     * @param array<\Symfony\Component\Process\Process> $processes
     * @param string $command
     * @param int $round
     * @param int $delayIntervalSeconds
     * @param array<string, mixed> $options
     *
     * @return void
     */
    protected function waitForPendingProcesses(
        array $processes,
        string $command,
        int $round,
        int $delayIntervalSeconds,
        array $options = []
    ): void {
        if (!$processes) {
            return;
        }

        while (true) {
            if ($this->queueConfig->isQueueWorkerWaitLimitEnabled()) {
                static $waitTimeStart = 0;
                $waitTimeStart = $waitTimeStart ?: microtime(true);
                $maxWaitSeconds = $this->queueConfig->getQueueWorkerMaxWaitingSeconds();
                $maxWaitRounds = $this->queueConfig->getQueueWorkerMaxWaitingRounds();

                if ($round > $maxWaitRounds || (microtime(true) - $waitTimeStart >= $maxWaitSeconds)) {
                    // pending processes will be killed automatically
                    $this->processManager->flushAllWorkerProcesses();

                    return;
                }
            }

            usleep($delayIntervalSeconds * static::SECOND_TO_MILLISECONDS);
            $pendingProcesses = $this->getPendingProcesses($processes);

            if (!$pendingProcesses) {
                return;
            }

            if ($this->isWorkerLoopEnabled($options)) {
                $this->workerProgressBar->reset();
                $this->start($command, $options, ++$round, $pendingProcesses);
            }

            sleep(static::RETRY_INTERVAL_SECONDS);
            $processes = $pendingProcesses;
        }
    }

    /**
     * @param array<\Symfony\Component\Process\Process> $processes
     *
     * @return array<\Symfony\Component\Process\Process>
     */
    protected function getPendingProcesses(array $processes): array
    {
        $pendingProcesses = [];
        foreach ($processes as $process) {
            if ($this->processManager->isProcessRunning($process->getPid())) {
                $pendingProcesses[] = $process;
            }
        }

        return $pendingProcesses;
    }

    /**
     * @param string $command
     * @param float|int $elapsedSeconds
     * @param bool $shouldUpdateDisplay
     *
     * @return array<\Symfony\Component\Process\Process>
     */
    protected function executeOperation(string $command, int|float $elapsedSeconds = 0, bool $shouldUpdateDisplay = false): array
    {
        if ($this->queueConfig->isQueueBulkMessageCheckEnabled()) {
            $processes = $this->executeOperationWithBulkCheck($command, $elapsedSeconds, $shouldUpdateDisplay);

            if ($processes !== null) {
                return $processes;
            }
        }

        return $this->executeOperationWithEachQueueCheck($command, $elapsedSeconds, $shouldUpdateDisplay);
    }

    /**
     * @deprecated introduced for BC reason. Use {@link executeOperationWithBulkCheck} instead
     *
     * @param string $command
     * @param float|int $elapsedSeconds
     * @param bool $shouldUpdateDisplay
     *
     * @return array<\Symfony\Component\Process\Process>
     */
    protected function executeOperationWithEachQueueCheck(string $command, int|float $elapsedSeconds = 0, bool $shouldUpdateDisplay = false): array
    {
        $processes = [];
        $queueData = [];

        $amountOfParallelExecutors = $this->queueConfig->getQueueWorkerMaxProcesses();
        foreach ($this->queueNames as $queue) {
            $processCommand = $this->buildProcessCommand($command, $queue);
            $queueProcesses = $this->startProcesses($processCommand, $queue);

            $processes = array_merge($processes, $queueProcesses[static::PROCESSES_INSTANCES]);

            if ($this->hasActiveProcesses($queueProcesses)) {
                $queueData[] = $this->collectQueueData($queue, $queueProcesses);
            }

            if (count($processes) >= $amountOfParallelExecutors && $amountOfParallelExecutors > 0) {
                break;
            }
        }

        if ($shouldUpdateDisplay) {
            $this->displayQueueStatus($queueData, $elapsedSeconds);
        }

        return $processes;
    }

    /**
     * @param string $command
     * @param float|int $elapsedSeconds
     * @param bool $shouldUpdateDisplay
     *
     * @return array<\Symfony\Component\Process\Process>|null
     */
    protected function executeOperationWithBulkCheck(string $command, int|float $elapsedSeconds = 0, bool $shouldUpdateDisplay = false): ?array
    {
        $bulkPlugin = $this->findApplicableBulkCheckerPlugin();

        if ($bulkPlugin === null) {
            return null;
        }

        $queueCollection = $bulkPlugin->getQueues($this->queueNames);

        if (count($queueCollection->getQueues()) === 0) {
            return null;
        }

        $processes = [];
        $queueData = [];
        $amountOfParallelExecutors = $this->queueConfig->getQueueWorkerMaxProcesses();

        foreach ($queueCollection->getQueues() as $queueTransfer) {
            $queueName = $queueTransfer->getNameOrFail();

            if (($queueTransfer->getReadyCount() ?? 0) === 0) {
                continue;
            }
            if (!in_array($queueTransfer->getName(), $this->queueNames)) {
                continue;
            }
            $processCommand = $this->buildProcessCommand($command, $queueName);
            $queueProcesses = $this->startProcessesForKnownNonEmptyQueue($processCommand, $queueName);

            $processes = array_merge($processes, $queueProcesses[static::PROCESSES_INSTANCES]);

            if ($this->hasActiveProcesses($queueProcesses)) {
                $queueData[] = $this->collectQueueData($queueName, $queueProcesses);
            }

            if (count($processes) >= $amountOfParallelExecutors && $amountOfParallelExecutors > 0) {
                break;
            }
        }

        if ($shouldUpdateDisplay) {
            $this->displayQueueStatus($queueData, $elapsedSeconds);
        }

        return $processes;
    }

    protected function findApplicableBulkCheckerPlugin(): ?QueueBulkMessageCheckerPluginInterface
    {
        if ($this->cachedBulkCheckerPlugin !== false) {
            return $this->cachedBulkCheckerPlugin;
        }

        $adapterName = $this->getAdapterName();
        $this->cachedBulkCheckerPlugin = null;

        foreach ($this->queueMessageCheckerPlugins as $plugin) {
            if (!$plugin instanceof QueueBulkMessageCheckerPluginInterface) {
                continue;
            }

            if (!$plugin->isApplicable($adapterName)) {
                continue;
            }

            $this->cachedBulkCheckerPlugin = $plugin;

            break;
        }

        return $this->cachedBulkCheckerPlugin;
    }

    /**
     * @return array<string, mixed>
     */
    protected function startProcessesForKnownNonEmptyQueue(string $command, string $queue): array
    {
        $busyProcessNumber = $this->processManager->getBusyProcessNumber($queue);
        $numberOfWorkers = max(0, $this->queueConfigReader->getMaxQueueWorkerByQueueName($queue) - $busyProcessNumber);

        $processes = [];
        for ($i = 0; $i < $numberOfWorkers; $i++) {
            if ($i > 0) {
                usleep((int)$this->queueConfig->getQueueProcessTriggerInterval());
            }
            $processes[] = $this->processManager->triggerQueueProcess($command, $queue);
        }

        return [
            static::PROCESS_BUSY => $busyProcessNumber,
            static::PROCESS_NEW => $numberOfWorkers,
            static::PROCESSES_INSTANCES => $processes,
        ];
    }

    protected function buildProcessCommand(string $command, string $queue): string
    {
        $processCommand = sprintf('%s %s 2>&1', $command, $queue);

        if ($this->queueConfig->getQueueWorkerLogStatus()) {
            return sprintf('%s | tee -a %s', $processCommand, $this->getQueueWorkerOutputFileNameBasedOnType());
        }

        return $processCommand;
    }

    /**
     * @param array<string, mixed> $queueProcesses
     *
     * @return bool
     */
    protected function hasActiveProcesses(array $queueProcesses): bool
    {
        return $queueProcesses[static::PROCESS_NEW] > 0 || $queueProcesses[static::PROCESS_BUSY] > 0;
    }

    /**
     * @param string $queue
     * @param array<string, mixed> $queueProcesses
     *
     * @return array<string, mixed>
     */
    protected function collectQueueData(string $queue, array $queueProcesses): array
    {
        return [
            static::QUEUE_DATA_KEY_QUEUE => $queue,
            static::QUEUE_DATA_KEY_PROCESSES => $queueProcesses,
            static::QUEUE_DATA_KEY_BATCH_SIZE => $this->getQueueBatchSize($queue),
            static::QUEUE_DATA_KEY_RUNNING_PIDS => $this->processManager->getRunningProcessPids($queue),
        ];
    }

    /**
     * @param array<array<string, mixed>> $queueData
     * @param float $elapsedSeconds
     *
     * @return void
     */
    protected function displayQueueStatus(array $queueData, float $elapsedSeconds): void
    {
        $this->workerProgressBar->clear();

        $index = 0;
        foreach ($queueData as $data) {
            if ($data[static::QUEUE_DATA_KEY_RUNNING_PIDS] === []) {
                continue;
            }

            foreach ($data[static::QUEUE_DATA_KEY_RUNNING_PIDS] as $pid) {
                $this->workerProgressBar->writeConsoleMessage(
                    ++$index,
                    $data[static::QUEUE_DATA_KEY_QUEUE],
                    $pid,
                    $data[static::QUEUE_DATA_KEY_PROCESSES][static::PROCESS_BUSY],
                    $data[static::QUEUE_DATA_KEY_PROCESSES][static::PROCESS_NEW],
                    $data[static::QUEUE_DATA_KEY_BATCH_SIZE],
                    $elapsedSeconds,
                );
            }
        }

        $this->displayProcessErrors();
        $this->displayProgressBar((int)$elapsedSeconds);
    }

    protected function displayProcessErrors(): void
    {
        $errors = $this->processManager->flushErrorBuffer();

        if ($errors !== []) {
            $this->workerProgressBar->writeErrors($errors);
        }
    }

    protected function displayProgressBar(int $elapsedSeconds): void
    {
        $this->workerProgressBar->setProgress($elapsedSeconds);
        $this->workerProgressBar->display();
    }

    protected function getQueueWorkerOutputFileNameBasedOnType(): string
    {
        /** @var string $outputFileName */
        $outputFileName = $this->queueConfig->getQueueWorkerOutputFileName();

        // @phpstan-ignore if.alwaysFalse (defensive programming for type safety)
        if (is_resource($outputFileName)) {
            // @phpstan-ignore offsetAccess.notFound (conditional check ensures offset exists)
            return stream_get_meta_data($outputFileName)['uri'];
        }

        return $outputFileName;
    }

    /**
     * @param string $command
     * @param string $queue
     *
     * @return array<string, mixed>
     */
    protected function startProcesses(string $command, string $queue): array
    {
        $busyProcessNumber = $this->processManager->getBusyProcessNumber($queue);
        $numberOfWorkers = max(0, $this->queueConfigReader->getMaxQueueWorkerByQueueName($queue) - $busyProcessNumber);

        $processes = [];
        $message = $this->queueClient->receiveMessage($queue, $this->queueConfig->getWorkerMessageCheckOption() ?: []);
        if ($message->getQueueMessage() !== null) {
            $this->queueClient->reject($message);
            for ($i = 0; $i < $numberOfWorkers; $i++) {
                if ($i > 0) {
                    usleep((int)$this->queueConfig->getQueueProcessTriggerInterval());
                }
                $processes[] = $this->processManager->triggerQueueProcess($command, $queue);
            }
        } else {
            $numberOfWorkers = 0;
        }

        return [
            static::PROCESS_BUSY => $busyProcessNumber,
            static::PROCESS_NEW => $numberOfWorkers,
            static::PROCESSES_INSTANCES => $processes,
        ];
    }

    /**
     * @param string $queueName
     *
     * @return array<string, mixed>
     */
    protected function getQueueAdapterDefaultConfiguration(string $queueName): array
    {
        $adapterConfiguration = $this->queueConfig->getDefaultQueueAdapterConfiguration();

        if ($adapterConfiguration) {
            return [
                $queueName => $adapterConfiguration,
            ];
        }

        return [];
    }

    protected function getFreshMicroTime(): float
    {
        return microtime(true);
    }

    /**
     * @param array<\Symfony\Component\Process\Process> $pendingProcesses
     * @param array<string, mixed> $options
     *
     * @return bool
     */
    protected function isEmptyQueue(array $pendingProcesses, array $options): bool
    {
        if (!$this->isWorkerStopsWhenEmptyQueueEnabled($options) || $pendingProcesses) {
            return false;
        }

        return $this->areQueuesEmpty();
    }

    /**
     * @param string $queueName
     *
     * @return array<string, mixed>
     */
    protected function getQueueConfiguration(string $queueName): array
    {
        $adapterConfiguration = $this->queueConfig->getQueueAdapterConfiguration();

        if (!$adapterConfiguration || !array_key_exists($queueName, $adapterConfiguration)) {
            $adapterConfiguration = $this->getQueueAdapterDefaultConfiguration($queueName);
        }

        return $adapterConfiguration[$queueName];
    }

    protected function areQueuesEmpty(): bool
    {
        $adapterName = $this->getAdapterName();
        foreach ($this->queueMessageCheckerPlugins as $queueMessageCheckerPlugin) {
            if ($queueMessageCheckerPlugin->isApplicable($adapterName)) {
                return $queueMessageCheckerPlugin->areQueuesEmpty($this->queueNames);
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return bool
     */
    protected function isWorkerLoopEnabled(array $options): bool
    {
        return $this->queueConfig->getIsWorkerLoopEnabled() || $this->isWorkerStopsWhenEmptyQueueEnabled($options);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return bool
     */
    protected function isWorkerStopsWhenEmptyQueueEnabled(array $options): bool
    {
        return isset($options[SharedQueueConfig::CONFIG_WORKER_STOP_WHEN_EMPTY]) && $options[SharedQueueConfig::CONFIG_WORKER_STOP_WHEN_EMPTY];
    }

    /**
     * Get batch size for queue (from config override or plugin)
     *
     * @param string $queueName
     *
     * @return int|null
     */
    protected function getQueueBatchSize(string $queueName): ?int
    {
        $queueMessageChunkSizeMap = $this->queueConfig->getQueueMessageChunkSizeMap();
        if (isset($queueMessageChunkSizeMap[$queueName])) {
            return $queueMessageChunkSizeMap[$queueName];
        }

        if (isset($this->messageProcessorPlugins[$queueName])) {
            return $this->messageProcessorPlugins[$queueName]->getChunkSize();
        }

        return null;
    }

    protected function registerKillSignalHandlers(): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }

        pcntl_async_signals(true);

        $handler = function (): void {
            $this->processManager->flushAllWorkerProcesses();
            exit(0);
        };

        pcntl_signal(SIGTERM, $handler);
        pcntl_signal(SIGINT, $handler);
        pcntl_signal(SIGHUP, $handler);
    }

    public function executeUsleep(int $delayIntervalMilliseconds, array $processes): void
    {
        if (count($processes) > 0) {
            $delayIntervalMilliseconds = $this->queueConfig->getDelayWhenQueueIsNotEmptyMilliseconds();
        }

        usleep($delayIntervalMilliseconds * static::SECOND_TO_MILLISECONDS);
    }

    public function getAdapterName(): string
    {
        return $this->getQueueConfiguration($this->queueNames[0])[SharedQueueConfig::CONFIG_QUEUE_ADAPTER];
    }
}
