<?php

namespace SprykerFeature\Zed\StockSalesConnector\Communication\Plugin;

use Generated\Shared\Transfer\StockStockProductTransfer;
use SprykerEngine\Zed\Kernel\Communication\AbstractPlugin;
use SprykerFeature\Zed\Sales\Dependency\Plugin\ManagerStockPluginInterface;
use SprykerFeature\Zed\Stock\Persistence\Propel\SpyStockProduct;
use SprykerFeature\Zed\StockSalesConnector\Business\StockSalesConnectorDependencyContainer;

/**
 * @method StockSalesConnectorDependencyContainer getDependencyContainer()
 */
class UpdateStockPlugin extends AbstractPlugin implements ManagerStockPluginInterface
{
    // TODO not sure this Connector/Plugin will be needed after refactor sales Bundle!

    /**
     * @param string $sku
     * @param string $stockType
     * @param int $incrementBy
     */
    public function incrementStockProduct($sku, $stockType, $incrementBy = 1)
    {
        $this->getDependencyContainer()->getStockFacade()->incrementStockProduct($sku, $stockType, $incrementBy);
    }

    /**
     * @param string $sku
     * @param string $stockType
     * @param int $decrementBy
     */
    public function decrementStockProduct($sku, $stockType, $decrementBy = 1)
    {
        $this->getDependencyContainer()->getStockFacade()->decrementStockProduct($sku, $stockType, $decrementBy);
    }

    /**
     * @param StockProduct $transferStockProduct
     * @return SpyStockProduct
     */
    public function updateStockProduct(StockProduct $transferStockProduct)
    {
        return $this->getDependencyContainer()->getStockFacade()->updateStockProduct($transferStockProduct);
    }

}