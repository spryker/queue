<?php

namespace SprykerFeature\Zed\Discount\Business\Model;

use SprykerFeature\Shared\Discount\Dependency\Transfer\DiscountableContainerInterface;
use SprykerFeature\Zed\Discount\Business\DiscountSettingsInterface;

interface CalculatorInterface
{

    /**
     * @param array $discounts
     * @param DiscountableContainerInterface $container
     * @param DiscountSettingsInterface $settings
     * @param DistributorInterface $distributor
     */
    public function calculate(
        array $discounts,
        DiscountableContainerInterface $container,
        DiscountSettingsInterface $settings,
        DistributorInterface $distributor
    );
}