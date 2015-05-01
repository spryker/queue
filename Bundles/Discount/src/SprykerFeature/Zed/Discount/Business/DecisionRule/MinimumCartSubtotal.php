<?php

namespace SprykerFeature\Zed\Discount\Business\DecisionRule;

use SprykerFeature\Shared\Discount\Dependency\Transfer\DiscountableContainerInterface;
use SprykerFeature\Zed\Discount\Persistence\Propel\SpyDiscountDecisionRule as DecisionRule;
use SprykerEngine\Zed\Kernel\Business\ModelResult;

class MinimumCartSubtotal
{
    const REASON_MINIMUM_CART_SUBTOTAL_NOT_REACHED = 'Minimum cart subtotal not reached';

    /**
     * @param DiscountableContainerInterface $order
     * @param DecisionRule $decisionRule
     * @return $this|ModelResult
     */
    public function isMinimumCartSubtotalReached(DiscountableContainerInterface $order, DecisionRule $decisionRule)
    {
        $result = new ModelResult();

        if ($order->getTotals()->getSubtotalWithoutItemExpenses() >= $decisionRule->getValue()) {
            return $result;
        }

        return $result->addError(self::REASON_MINIMUM_CART_SUBTOTAL_NOT_REACHED);
    }
}