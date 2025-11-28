<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue;

use Spryker\Zed\Kernel\AbstractBundleDependencyProvider;
use Spryker\Zed\Kernel\Container;
use Spryker\Zed\Queue\Dependency\Service\QueueToUtilEncodingServiceBridge;
use Spryker\Zed\Store\Business\StoreFacadeInterface;

/**
 * @method \Spryker\Zed\Queue\QueueConfig getConfig()
 */
class QueueDependencyProvider extends AbstractBundleDependencyProvider
{
    /**
     * @var string
     */
    public const CLIENT_QUEUE = 'queue client';

    /**
     * @var string
     */
    public const QUEUE_MESSAGE_PROCESSOR_PLUGINS = 'queue message processor plugin';

    /**
     * @var string
     */
    public const SERVICE_UTIL_ENCODING = 'UTIL_ENCODING_SERVICE';

    /**
     * @var string
     */
    public const PLUGINS_QUEUE_MESSAGE_CHECKER = 'PLUGINS_QUEUE_MESSAGE_CHECKER';

    /**
     * @var string
     */
    public const FACADE_STORE = 'STORE_FACADE';

    /**
     * @var string
     */
    public const PLUGINS_QUEUE_METRICS_EXPANDER = 'PLUGINS_QUEUE_METRICS_EXPANDER';

    /**
     * @var string
     */
    public const PLUGINS_DYNAMIC_SETTINGS_EXPANDER = 'PLUGINS_DYNAMIC_SETTINGS_EXPANDER';

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     *
     * @return \Spryker\Zed\Kernel\Container
     */
    public function provideBusinessLayerDependencies(Container $container)
    {
        $container->set(static::CLIENT_QUEUE, function (Container $container) {
            return $container->getLocator()->queue()->client();
        });

        $container->set(static::QUEUE_MESSAGE_PROCESSOR_PLUGINS, function (Container $container) {
            return $this->getProcessorMessagePlugins($container);
        });

        $container->set(static::SERVICE_UTIL_ENCODING, function (Container $container) {
            return new QueueToUtilEncodingServiceBridge($container->getLocator()->utilEncoding()->service());
        });

        $container = $this->addQueueMessageCheckerPlugins($container);

        $this->addStoreFacade($container);
        $this->addQueueMetricsExpanderPlugins($container);
        $this->addDynamicSettingsExpanderPlugins($container);

        return $container;
    }

    /**
     * For processing the received messages from the queue, plugins can be
     * registered here by having queue name as a key. All plugins need to implement
     * Spryker\Zed\Queue\Dependency\Plugin\QueueMessageProcessorPluginInterface
     *
     *  e.g: 'mail' => new MailQueueMessageProcessorPlugin()
     *
     * @param \Spryker\Zed\Kernel\Container $container
     *
     * @return array<\Spryker\Zed\Queue\Dependency\Plugin\QueueMessageProcessorPluginInterface>
     */
    protected function getProcessorMessagePlugins(Container $container)
    {
        return [];
    }

    /**
     * @return array<\Spryker\Zed\QueueExtension\Dependency\Plugin\QueueMessageCheckerPluginInterface>
     */
    protected function getQueueMessageCheckerPlugins(): array
    {
        return [];
    }

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     *
     * @return \Spryker\Zed\Kernel\Container
     */
    protected function addQueueMessageCheckerPlugins(Container $container): Container
    {
        $container->set(static::PLUGINS_QUEUE_MESSAGE_CHECKER, function () {
            return $this->getQueueMessageCheckerPlugins();
        });

        return $container;
    }

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     *
     * @return \Spryker\Zed\Kernel\Container
     */
    public function addStoreFacade(Container $container): Container
    {
        $container->set(static::FACADE_STORE, function (Container $container): StoreFacadeInterface {
            return $container->getLocator()->store()->facade();
        });

        return $container;
    }

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     *
     * @return \Spryker\Zed\Kernel\Container
     */
    protected function addQueueMetricsExpanderPlugins(Container $container): Container
    {
        $container->set(static::PLUGINS_QUEUE_METRICS_EXPANDER, function (Container $container) {
            return $this->getQueueMetricsExpanderPlugins();
        });

        return $container;
    }

    /**
     * @param \Spryker\Zed\Kernel\Container $container
     *
     * @return \Spryker\Zed\Kernel\Container
     */
    protected function addDynamicSettingsExpanderPlugins(Container $container): Container
    {
        $container->set(static::PLUGINS_DYNAMIC_SETTINGS_EXPANDER, function (Container $container) {
            return $this->getDynamicSettingsExpanderPlugins();
        });

        return $container;
    }

    /**
     * @return array<\Spryker\Zed\QueueExtension\Dependency\Plugin\QueueMetricsReaderPluginInterface>
     */
    protected function getQueueMetricsExpanderPlugins(): array
    {
        return [];
    }

    /**
     * @return array<\Spryker\Zed\QueueExtension\Dependency\Plugin\DynamicSettingsUpdaterPluginInterface>
     */
    protected function getDynamicSettingsExpanderPlugins(): array
    {
        return [];
    }
}
