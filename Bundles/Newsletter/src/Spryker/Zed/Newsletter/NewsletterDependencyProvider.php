<?php

/**
 * (c) Spryker Systems GmbH copyright protected
 */

namespace Spryker\Zed\Newsletter;

use Spryker\Zed\Kernel\AbstractBundleDependencyProvider;
use Spryker\Zed\Kernel\Container;

class NewsletterDependencyProvider extends AbstractBundleDependencyProvider
{

    const DOUBLE_OPT_IN_SENDER_PLUGINS = 'double opt in sender plugins';
    const FACADE_MAIL = 'mail facade';
    const FACADE_GLOSSARY = 'glossary facade';

    /**
     * @param Container $container
     *
     * @return Container
     */
    public function provideBusinessLayerDependencies(Container $container)
    {
        $container = parent::provideBusinessLayerDependencies($container);

        $container[self::DOUBLE_OPT_IN_SENDER_PLUGINS] = function (Container $container) {
            return $this->getDoubleOptInSenderPlugins($container);
        };

        return $container;
    }

    /**
     * @param Container $container
     *
     * @return Container
     */
    public function provideCommunicationLayerDependencies(Container $container)
    {
        $container[self::FACADE_MAIL] = function (Container $container) {
            return $container->getLocator()->mail()->facade();
        };
        $container[self::FACADE_GLOSSARY] = function (Container $container) {
            return $container->getLocator()->glossary()->facade();
        };

        return $container;
    }

    /**
     * @param Container $container
     *
     * @return array
     */
    protected function getDoubleOptInSenderPlugins(Container $container)
    {
        return [];
    }

}