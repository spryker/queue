<?php

namespace SprykerFeature\Zed\Discount\Communication\Plugin\Collector;

use SprykerFeature\Zed\Discount\Dependency\Plugin\DiscountCollectorPluginInterface;
use SprykerFeature\Shared\Discount\Dependency\Transfer\DiscountableContainerInterface;
use SprykerEngine\Zed\Kernel\Communication\AbstractPlugin;
use SprykerFeature\Zed\Discount\Communication\DiscountDependencyContainer;

/**
 * @method DiscountDependencyContainer getDependencyContainer()
 */
class Item extends AbstractPlugin implements
    DiscountCollectorPluginInterface
{
    /**
     * @param DiscountableContainerInterface $container
     * @return array
     */
    public function collect(DiscountableContainerInterface $container)
    {
        return $this->getDependencyContainer()->getDiscountFacade()->getDiscountableItems($container);
    }
}