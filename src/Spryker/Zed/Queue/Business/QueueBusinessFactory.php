<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business;

use Spryker\Zed\Kernel\Business\AbstractBusinessFactory;
use Spryker\Zed\Queue\Business\Checker\TaskMemoryUsageChecker;
use Spryker\Zed\Queue\Business\Checker\TaskMemoryUsageCheckerInterface;
use Spryker\Zed\Queue\Business\Process\ProcessManager;
use Spryker\Zed\Queue\Business\QueueDumper\QueueDumper;
use Spryker\Zed\Queue\Business\QueueDumper\QueueDumperInterface;
use Spryker\Zed\Queue\Business\Reader\QueueConfigReader;
use Spryker\Zed\Queue\Business\Reader\QueueConfigReaderInterface;
use Spryker\Zed\Queue\Business\SignalHandler\QueueWorkerSignalDispatcher;
use Spryker\Zed\Queue\Business\SignalHandler\SignalDispatcherInterface;
use Spryker\Zed\Queue\Business\Task\TaskDebugHelper;
use Spryker\Zed\Queue\Business\Task\TaskDebugHelperInterface;
use Spryker\Zed\Queue\Business\Task\TaskManager;
use Spryker\Zed\Queue\Business\Worker\Worker;
use Spryker\Zed\Queue\Business\Worker\WorkerDebugHelper;
use Spryker\Zed\Queue\Business\Worker\WorkerDebugHelperInterface;
use Spryker\Zed\Queue\Business\Worker\WorkerProgressBar;
use Spryker\Zed\Queue\Dependency\Service\QueueToUtilEncodingServiceInterface;
use Spryker\Zed\Queue\QueueDependencyProvider;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @method \Spryker\Zed\Queue\QueueConfig getConfig()
 * @method \Spryker\Zed\Queue\Persistence\QueueQueryContainerInterface getQueryContainer()
 */
class QueueBusinessFactory extends AbstractBusinessFactory
{
    /**
     * @var string
     */
    protected static $serverUniqueId;

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface|null $output
     *
     * @return \Spryker\Zed\Queue\Business\Task\TaskManager
     */
    public function createTask(?OutputInterface $output = null)
    {
        return new TaskManager(
            $this->getQueueClient(),
            $this->getConfig(),
            $this->createTaskMemoryUsageChecker(),
            $this->getProcessorMessagePlugins(),
            $this->createTaskDebugHelper($output),
        );
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return \Spryker\Zed\Queue\Business\Worker\Worker
     */
    public function createWorker(OutputInterface $output)
    {
        return new Worker(
            $this->createProcessManager(),
            $this->getConfig(),
            $this->createWorkerProgressbar($output),
            $this->createWorkerDebugHelper($output),
            $this->getQueueClient(),
            $this->getQueueNames(),
            $this->createQueueWorkerSignalDispatcher(),
            $this->createQueueConfigReader(),
            $this->getQueueMessageCheckerPlugins(),
        );
    }

    /**
     * @return \Spryker\Zed\Queue\Business\Process\ProcessManagerInterface
     */
    public function createProcessManager()
    {
        return new ProcessManager(
            $this->getQueryContainer(),
            $this->getServerUniqueId(),
        );
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface|null $output
     *
     * @return \Spryker\Zed\Queue\Business\Task\TaskDebugHelperInterface
     */
    public function createTaskDebugHelper(?OutputInterface $output = null): TaskDebugHelperInterface
    {
        return new TaskDebugHelper($output);
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return \Spryker\Zed\Queue\Business\Worker\WorkerDebugHelperInterface
     */
    public function createWorkerDebugHelper(OutputInterface $output): WorkerDebugHelperInterface
    {
        return new WorkerDebugHelper($output);
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return \Spryker\Zed\Queue\Business\Worker\WorkerProgressBarInterface
     */
    public function createWorkerProgressbar(OutputInterface $output)
    {
        return new WorkerProgressBar($output);
    }

    /**
     * @return string
     */
    public function getServerUniqueId()
    {
        if (static::$serverUniqueId === null) {
            static::$serverUniqueId = $this->getConfig()->getQueueServerId();
        }

        return static::$serverUniqueId;
    }

    /**
     * @return array<string>
     */
    public function getQueueNames()
    {
        return array_keys($this->getProcessorMessagePlugins());
    }

    /**
     * @return \Spryker\Client\Queue\QueueClientInterface
     */
    public function getQueueClient()
    {
        return $this->getProvidedDependency(QueueDependencyProvider::CLIENT_QUEUE);
    }

    /**
     * @return array<\Spryker\Zed\Queue\Dependency\Plugin\QueueMessageProcessorPluginInterface>
     */
    public function getProcessorMessagePlugins()
    {
        return $this->getProvidedDependency(QueueDependencyProvider::QUEUE_MESSAGE_PROCESSOR_PLUGINS);
    }

    /**
     * @return array<\Spryker\Zed\QueueExtension\Dependency\Plugin\QueueMessageCheckerPluginInterface>
     */
    public function getQueueMessageCheckerPlugins(): array
    {
        return $this->getProvidedDependency(QueueDependencyProvider::PLUGINS_QUEUE_MESSAGE_CHECKER);
    }

    /**
     * @return \Spryker\Zed\Queue\Business\QueueDumper\QueueDumperInterface
     */
    public function createQueueDumper(): QueueDumperInterface
    {
        return new QueueDumper(
            $this->getQueueClient(),
            $this->getConfig(),
            $this->getUtilEncodingService(),
            $this->getProcessorMessagePlugins(),
        );
    }

    /**
     * @return \Spryker\Zed\Queue\Dependency\Service\QueueToUtilEncodingServiceInterface
     */
    public function getUtilEncodingService(): QueueToUtilEncodingServiceInterface
    {
        return $this->getProvidedDependency(QueueDependencyProvider::SERVICE_UTIL_ENCODING);
    }

    /**
     * @return \Spryker\Zed\Queue\Business\SignalHandler\SignalDispatcherInterface
     */
    public function createQueueWorkerSignalDispatcher(): SignalDispatcherInterface
    {
        return new QueueWorkerSignalDispatcher(
            $this->createProcessManager(),
            $this->getConfig(),
            $this->getQueueNames(),
        );
    }

    /**
     * @return \Spryker\Zed\Queue\Business\Checker\TaskMemoryUsageCheckerInterface
     */
    public function createTaskMemoryUsageChecker(): TaskMemoryUsageCheckerInterface
    {
        return new TaskMemoryUsageChecker(
            $this->getConfig(),
            $this->createQueueConfigReader(),
        );
    }

    /**
     * @return \Spryker\Zed\Queue\Business\Reader\QueueConfigReaderInterface
     */
    public function createQueueConfigReader(): QueueConfigReaderInterface
    {
        return new QueueConfigReader(
            $this->getConfig(),
        );
    }
}
