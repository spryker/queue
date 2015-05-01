<?php

namespace SprykerFeature\Shared\Calculation\Dependency\Transfer;

use SprykerFeature\Shared\Library\TransferObject\TransferInterface;

interface PriceItemInterface extends TransferInterface
{

    /**
     * @return int
     */
    public function getGrossPrice();

    /**
     * @param int $grossPrice
     *
     * @return $this
     */
    public function setGrossPrice($grossPrice);

    /**
     * @return int
     */
    public function getPriceToPay();

    /**
     * @param int $priceToPay
     *
     * @return $this
     */
    public function setPriceToPay($priceToPay);
}