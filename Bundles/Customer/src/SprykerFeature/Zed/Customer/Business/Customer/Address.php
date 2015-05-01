<?php

namespace SprykerFeature\Zed\Customer\Business\Customer;

use SprykerFeature\Shared\Customer\Transfer\Address as AddressTransfer;
use SprykerFeature\Shared\Customer\Transfer\Customer as CustomerTransfer;
use Propel\Runtime\Exception\PropelException;
use SprykerFeature\Zed\Customer\Business\Exception\CustomerNotFoundException;
use SprykerFeature\Zed\Customer\Business\Exception\AddressNotFoundException;
use SprykerFeature\Zed\Customer\Business\Exception\CountryNotFoundException;
use SprykerFeature\Zed\Customer\Persistence\Propel\SpyCustomer;
use SprykerFeature\Zed\Customer\Persistence\Propel\SpyCustomerAddress;
use SprykerFeature\Zed\Customer\Persistence\CustomerQueryContainer;
use Generated\Zed\Ide\AutoCompletion;
use SprykerEngine\Zed\Kernel\Persistence\QueryContainer\QueryContainerInterface;
use SprykerEngine\Shared\Kernel\LocatorLocatorInterface;
use Propel\Runtime\Collection\ObjectCollection;
use SprykerFeature\Shared\Customer\Transfer\AddressCollection as AddressTransferCollection;

class Address
{
    /** @var CustomerQueryContainer */
    protected $queryContainer;

    /** @var AutoCompletion */
    protected $locator;

    /**
     * @param QueryContainerInterface $queryContainer
     * @param LocatorLocatorInterface $locator
     */
    public function __construct(QueryContainerInterface $queryContainer, LocatorLocatorInterface $locator)
    {
        $this->locator = $locator;
        $this->queryContainer = $queryContainer;
    }

    /**
     * @param AddressTransfer $addressTransfer
     *
     * @return AddressTransfer
     * @throws CustomerNotFoundException
     * @throws PropelException
     */
    public function newAddress(AddressTransfer $addressTransfer)
    {
        $customer = $this->getCustomerFromAddressTransfer($addressTransfer);

        $entity = new SpyCustomerAddress();
        $entity->fromArray($addressTransfer->toArray());
        $entity->setFkCountry($this->getCustomerCountryId());
        $entity->setCustomer($customer);
        $entity->save();

        if ($customer->getDefaultShippingAddress() === null) {
            $customer->setDefaultShippingAddress($entity->getIdCustomerAddress());
        }

        if ($customer->getDefaultBillingAddress() === null) {
            $customer->setDefaultBillingAddress($entity->getIdCustomerAddress());
        }

        $customer->save();

        $addressTransfer = $this->entityToTransfer($entity);

        return $addressTransfer;
    }

    /**
     * @param AddressTransfer $addressTransfer
     *
     * @return AddressTransfer
     * @throws AddressNotFoundException
     */
    public function getAddress(AddressTransfer $addressTransfer)
    {
        $entity = $this->queryContainer
            ->queryAddress(
                $addressTransfer->getIdCustomerAddress()
            )
            ->findOne();

        if (!$entity) {
            throw new AddressNotFoundException;
        }

        return $this->entityToTransfer($entity);
    }

    /**
     * @param AddressTransfer $addressTransfer
     *
     * @return AddressTransfer
     * @throws AddressNotFoundException
     * @throws CustomerNotFoundException
     * @throws PropelException
     */
    public function updateAddress(AddressTransfer $addressTransfer)
    {
        $customer = $this->getCustomerFromAddressTransfer($addressTransfer);

        $entity = $this->queryContainer
            ->queryAddressForCustomer(
                $addressTransfer->getIdCustomerAddress(),
                $customer->getEmail()
            )
            ->findOne();

        if (!$entity) {
            throw new AddressNotFoundException;
        }

        $entity->fromArray($addressTransfer->toArray());
        $entity->setCustomer($customer);
        $country = $entity->getCountry();
        if (!$country) {
            $country = $this->getCustomerCountryId();
        }
        $entity->setCountry($country);
        $entity->save();

        return $this->entityToTransfer($entity);
    }

    /**
     * @param AddressTransfer $addressTransfer
     *
     * @return bool
     * @throws AddressNotFoundException
     * @throws CustomerNotFoundException
     * @throws PropelException
     */
    public function setDefaultShippingAddress(AddressTransfer $addressTransfer)
    {
        $customer = $this->getCustomerFromAddressTransfer($addressTransfer);

        $entity = $this->queryContainer
            ->queryAddressForCustomer(
                $addressTransfer->getIdCustomerAddress(),
                $customer->getEmail()
            )
            ->findOne();

        if (!$entity) {
            throw new AddressNotFoundException;
        }

        $customer->setDefaultShippingAddress($addressTransfer->getIdCustomerAddress());
        $customer->save();

        return true;
    }

    /**
     * @param AddressTransfer $addressTransfer
     *
     * @return bool
     * @throws AddressNotFoundException
     * @throws CustomerNotFoundException
     * @throws PropelException
     */
    public function setDefaultBillingAddress(AddressTransfer $addressTransfer)
    {
        $customer = $this->getCustomerFromAddressTransfer($addressTransfer);

        $entity = $this->queryContainer
            ->queryAddressForCustomer(
                $addressTransfer->getIdCustomerAddress(),
                $customer->getEmail()
            )
            ->findOne();

        if (!$entity) {
            throw new AddressNotFoundException;
        }

        $customer->setDefaultBillingAddress($addressTransfer->getIdCustomerAddress());
        $customer->save();

        return true;
    }

    /**
     * @param AddressTransfer $addressTransfer
     *
     * @return string
     */
    public function getFormattedAddressString(AddressTransfer $addressTransfer)
    {
        return implode("\n", $this->getFormattedAddressArray($addressTransfer));
    }

    /**
     * @param AddressTransfer $addressTransfer
     *
     * @return array
     */
    public function getFormattedAddressArray(AddressTransfer $addressTransfer)
    {
        $address = [];

        if (count($addressTransfer->getCompany()) > 0) {
            $address[] = $addressTransfer->getCompany();
        }

        $address[] = sprintf(
            '%s %s',
            $addressTransfer->getSalutation(),
            $addressTransfer->getName()
        );

        if (count($addressTransfer->getAddress1()) > 0) {
            $address[] = $addressTransfer->getAddress1();
        }
        if (count($addressTransfer->getAddress2()) > 0) {
            $address[] = $addressTransfer->getAddress2();
        }
        if (count($addressTransfer->getAddress3()) > 0) {
            $address[] = $addressTransfer->getAddress3();
        }

        $address[] = sprintf(
            '%s %s',
            $addressTransfer->getZipCode(),
            $addressTransfer->getCity()
        );

        return $address;
    }

    /**
     * @param SpyCustomerAddress $entity
     *
     * @return AddressTransfer
     */
    protected function entityToTransfer(SpyCustomerAddress $entity)
    {
        $data = $entity->toArray();
        unset($data["deleted_at"]);
        unset($data["created_at"]);
        unset($data["updated_at"]);
        $addressTransfer = new \Generated\Shared\Transfer\CustomerAddressTransfer();
        $addressTransfer->fromArray($data);

        return $addressTransfer;
    }

    /**
     * @param ObjectCollection $entities
     *
     * @return AddressTransferCollection
     * @throws AddressNotFoundException
     */
    protected function entityCollectionToTransferCollection(ObjectCollection $entities)
    {
        $addresses = [];
        foreach ($entities->getData() as $entity) {
            $addresses[] = $this->entityToTransfer($entity);
        }
        $addressTransferCollection = new \Generated\Shared\Transfer\CustomerAddressTransfer();
        $addressTransferCollection->fromArray($addresses);

        return $addressTransferCollection;
    }

    /**
     * @param AddressTransfer $addressTransfer
     *
     * @return SpyCustomer
     * @throws CustomerNotFoundException
     */
    protected function getCustomerFromAddressTransfer(AddressTransfer $addressTransfer)
    {
        if ($addressTransfer->getEmail()) {
            $customer = $this->queryContainer
                ->queryCustomerByEmail($addressTransfer->getEmail())
                ->findOne();
        } elseif ($addressTransfer->getFkCustomer()) {
            $customer = $this->queryContainer
                ->queryCustomerById($addressTransfer->getFkCustomer())
                ->findOne();
        }

        if (!isset($customer) || $customer == null) {
            throw new CustomerNotFoundException;
        }

        return $customer;
    }

    /**
     * @param CustomerTransfer $customerTransfer
     *
     * @return SpyCustomer
     * @throws CustomerNotFoundException
     */
    protected function getCustomerFromCustomerTransfer(CustomerTransfer $customerTransfer)
    {
        if ($customerTransfer->getEmail()) {
            $customer = $this->queryContainer
                ->queryCustomerByEmail($customerTransfer->getEmail())
                ->findOne();
        } elseif ($customerTransfer->getIdCustomer()) {
            $customer = $this->queryContainer
                ->queryCustomerById($customerTransfer->getIdCustomer())
                ->findOne();
        }

        if (!isset($customer) || $customer == null) {
            throw new CustomerNotFoundException;
        }

        return $customer;
    }

    /**
     * @return int
     * @throws CountryNotFoundException
     */
    protected function getCustomerCountryId()
    {
        $isoCode = explode(
            "_",
            $this->locator
                ->locale()
                ->facade()
                ->getCurrentLocale()
                ->getLocaleName()
        )[1];

        $id_country = $this->locator
            ->country()
            ->facade()
            ->getIdCountryByIso2Code($isoCode);

        if ($id_country == null) {
            throw new CountryNotFoundException;
        }

        return $id_country;
    }

    /**
     * @param CustomerTransfer $customerTransfer
     *
     * @return AddressTransfer
     * @throws AddressNotFoundException
     */
    public function getDefaultShippingAddress(CustomerTransfer $customerTransfer)
    {
        $customer = $this->getCustomerFromCustomerTransfer($customerTransfer);
        $id_address = $customer->getDefaultShippingAddress();
        $address = $this->queryContainer->queryAddress($id_address)->findOne();
        if ($address === null) {
            throw new AddressNotFoundException;
        }

        return $this->entityToTransfer($address);
    }

    /**
     * @param CustomerTransfer $customerTransfer
     *
     * @return AddressTransfer
     * @throws AddressNotFoundException
     */
    public function getDefaultBillingAddress(CustomerTransfer $customerTransfer)
    {
        $customer = $this->getCustomerFromCustomerTransfer($customerTransfer);
        $id_address = $customer->getDefaultBillingAddress();
        $address = $this->queryContainer->queryAddress($id_address)->findOne();
        if ($address === null) {
            throw new AddressNotFoundException;
        }

        return $this->entityToTransfer($address);
    }
}