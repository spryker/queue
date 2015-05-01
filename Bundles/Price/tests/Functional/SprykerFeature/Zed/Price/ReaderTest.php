<?php

namespace Functional\SprykerFeature\Zed\Price;

use Codeception\TestCase\Test;
use SprykerEngine\Zed\Kernel\Business\Factory;
use SprykerEngine\Zed\Kernel\Locator;
use SprykerFeature\Zed\Price\Business\PriceFacade;
use Generated\Zed\Ide\AutoCompletion;
use SprykerFeature\Zed\Price\Persistence\Propel\SpyPriceProductQuery;
use SprykerFeature\Zed\Price\Persistence\Propel\SpyPriceTypeQuery;
use SprykerFeature\Zed\Product\Persistence\Propel\SpyAbstractProductQuery;
use SprykerFeature\Zed\Product\Persistence\Propel\SpyProductQuery;

/**
 * @group PriceTest
 */
class ReaderTest extends Test
{

    const DUMMY_PRICE_TYPE_1 = 'TYPE1';
    const DUMMY_PRICE_TYPE_2 = 'TYPE2';
    const DUMMY_SKU_ABSTRACT_PRODUCT = 'ABSTRACT';
    const DUMMY_SKU_CONCRETE_PRODUCT = 'CONCRETE';
    const DUMMY_PRICE_1 = 99;
    const DUMMY_PRICE_2 = 100;
    /**
     * @var PriceFacade
     */
    private $priceFacade;
    /**
     * @var AutoCompletion $locator
     */
    protected $locator;

    public function setUp()
    {
        parent::setUp();

        $this->locator = Locator::getInstance();
        $this->priceFacade = new PriceFacade(new Factory('Price'), $this->locator);
        $this->setTestData();
    }

    public function testGetAllTypesValues()
    {
        $priceType = SpyPriceTypeQuery::create()->filterByName(self::DUMMY_PRICE_TYPE_2)->findOneOrCreate();
        $priceType->setName(self::DUMMY_PRICE_TYPE_2)->save();
        $priceType = SpyPriceTypeQuery::create()->filterByName(self::DUMMY_PRICE_TYPE_1)->findOneOrCreate();
        $priceType->setName(self::DUMMY_PRICE_TYPE_1)->save();

        $priceTypes = $this->priceFacade->getPriceTypeValues();

        $isTypeInResult_1 = false;
        $isTypeInResult_2 = false;
        foreach ($priceTypes as $priceType) {
            if ($priceType == self::DUMMY_PRICE_TYPE_1) {
                $isTypeInResult_1 = true;
            } elseif ($priceType == self::DUMMY_PRICE_TYPE_2) {
                $isTypeInResult_2 = true;
            }
        }
        $this->assertTrue($isTypeInResult_1);
        $this->assertTrue($isTypeInResult_2);
    }

    public function testHasValidPriceTrue()
    {
        $hasValidPrice = $this->priceFacade->hasValidPrice(self::DUMMY_SKU_ABSTRACT_PRODUCT, self::DUMMY_PRICE_TYPE_1);
        $this->assertTrue($hasValidPrice);
    }

    public function testHasValidPriceFalse()
    {
        $hasValidPrice = $this->priceFacade->hasValidPrice(self::DUMMY_SKU_CONCRETE_PRODUCT, self::DUMMY_PRICE_TYPE_2);
        $this->assertTrue($hasValidPrice);
    }

    public function testGetPriceForAbstractProduct()
    {
        $price = $this->priceFacade->getPriceBySku(self::DUMMY_SKU_ABSTRACT_PRODUCT, self::DUMMY_PRICE_TYPE_1);
        $this->assertEquals(100, $price);
    }

    public function testGetPrice()
    {
        $price = $this->priceFacade->getPriceBySku(self::DUMMY_SKU_ABSTRACT_PRODUCT, self::DUMMY_PRICE_TYPE_1);
        $this->assertEquals(100, $price);
    }

    public function testGetPriceForConcreteProduct()
    {
        $price = $this->priceFacade->getPriceBySku(self::DUMMY_SKU_CONCRETE_PRODUCT, self::DUMMY_PRICE_TYPE_2);
        $this->assertEquals(999, $price);
    }

    protected function deletePriceEntitiesAbstract($requestProduct)
    {
        SpyPriceProductQuery::create()->filterBySpyAbstractProduct($requestProduct)->delete();
    }

    protected function deletePriceEntitiesConcrete($requestProduct)
    {
        SpyPriceProductQuery::create()->filterByProduct($requestProduct)->delete();
    }

    protected function insertPriceEntity($requestProduct, $requestPriceType)
    {
        $this->locator->price()->entitySpyPriceProduct()
            ->setPrice(100)
            ->setSpyAbstractProduct($requestProduct)
            ->setPriceType($requestPriceType)
            ->save();
    }

    protected function setTestData()
    {
        $priceType1 = SpyPriceTypeQuery::create()->filterByName(self::DUMMY_PRICE_TYPE_1)->findOneOrCreate();
        $priceType1->setName(self::DUMMY_PRICE_TYPE_1)->save();

        $priceType2 = SpyPriceTypeQuery::create()->filterByName(self::DUMMY_PRICE_TYPE_2)->findOneOrCreate();
        $priceType2->setName(self::DUMMY_PRICE_TYPE_2)->save();


        $abstractProduct = SpyAbstractProductQuery::create()
            ->filterBySku(self::DUMMY_SKU_ABSTRACT_PRODUCT)
            ->findOneOrCreate()
        ;
        $abstractProduct->setSku(self::DUMMY_SKU_ABSTRACT_PRODUCT)->save();

        $concreteProduct = SpyProductQuery::create()->filterBySku(self::DUMMY_SKU_CONCRETE_PRODUCT)->findOneOrCreate();
        $this->deletePriceEntitiesConcrete($concreteProduct);
        $concreteProduct->setSku(self::DUMMY_SKU_CONCRETE_PRODUCT)->setSpyAbstractProduct($abstractProduct)->save();

        $this->deletePriceEntitiesAbstract($abstractProduct);
        $this->locator->price()->entitySpyPriceProduct()
            ->setSpyAbstractProduct($abstractProduct)
            ->setPriceType($priceType1)
            ->setPrice(100)
            ->save();

        $this->locator->price()->entitySpyPriceProduct()
            ->setProduct($concreteProduct)
            ->setPriceType($priceType2)
            ->setPrice(999)

            ->save();
    }

}