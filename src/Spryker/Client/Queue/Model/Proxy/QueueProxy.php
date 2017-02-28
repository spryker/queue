<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Client\Queue\Model\Proxy;

use Generated\Shared\Transfer\QueueMessageTransfer;
use Generated\Shared\Transfer\QueueOptionTransfer;
use Spryker\Client\Queue\Exception\MissingQueueAdapterException;
use Spryker\Client\Queue\Model\Adapter\AdapterInterface;

class QueueProxy implements QueueProxyInterface
{

    /**
     * @var \Spryker\Client\Queue\Model\Adapter\AdapterInterface[]
     */
    protected $queueAdapters;

    /**
     * @var string
     */
    protected $defaultQueueAdapterName;

    /**
     * @var array
     */
    protected $queueAdapterNameMapping;

    /**
     * @param \Spryker\Client\Queue\Model\Adapter\AdapterInterface[] $queueAdapters
     * @param string $defaultQueueAdapterName
     * @param array $queueAdapterNameMapping
     */
    public function __construct(array $queueAdapters, $defaultQueueAdapterName, array $queueAdapterNameMapping)
    {
        $this->queueAdapters = $queueAdapters;
        $this->defaultQueueAdapterName = $defaultQueueAdapterName;
        $this->queueAdapterNameMapping = $queueAdapterNameMapping;
    }

    /**
     * @param \Generated\Shared\Transfer\QueueOptionTransfer $queueOptionTransfer
     *
     * @return QueueOptionTransfer
     */
    public function createQueue(QueueOptionTransfer $queueOptionTransfer)
    {
        $queueAdapter = $this->getQueueAdapter($queueOptionTransfer->getQueueName());

        return $queueAdapter->createQueue($queueOptionTransfer);
    }

    /**
     * @param \Generated\Shared\Transfer\QueueMessageTransfer $queueMessageTransfer
     *
     * @return void
     */
    public function sendMessage(QueueMessageTransfer $queueMessageTransfer)
    {
        $queueAdapter = $this->getQueueAdapter($queueMessageTransfer->getQueueName());

        $queueAdapter->sendMessage($queueMessageTransfer);
    }

    /**
     * @param string $queueName
     * @param \Generated\Shared\Transfer\QueueMessageTransfer[] $queueMessageTransfers
     *
     * @return void
     */
    public function sendMessages($queueName, array $queueMessageTransfers)
    {
        $queueAdapter = $this->getQueueAdapter($queueName);

        $queueAdapter->sendMessages($queueName, $queueMessageTransfers);
    }

    /**
     * @param QueueOptionTransfer $queueOptionTransfer
     *
     * @return QueueMessageTransfer[]
     */
    public function receiveMessages(QueueOptionTransfer $queueOptionTransfer)
    {
        $queueAdapter = $this->getQueueAdapter($queueOptionTransfer->getQueueName());

       return $queueAdapter->receiveMessages($queueOptionTransfer);
    }

    /**
     * @param QueueOptionTransfer $queueOptionTransfer
     *
     * @return QueueMessageTransfer
     */
    public function receiveMessage(QueueOptionTransfer $queueOptionTransfer)
    {
        $queueAdapter = $this->getQueueAdapter($queueOptionTransfer->getQueueName());

        return $queueAdapter->receiveMessage($queueOptionTransfer);
    }

    /**
     * @param QueueMessageTransfer $queueMessageTransfer
     *
     * @return QueueMessageTransfer
     */
    public function handleErrorMessage(QueueMessageTransfer $queueMessageTransfer)
    {
        $queueAdapter = $this->getQueueAdapter($queueMessageTransfer->getQueueName());

        return $queueAdapter->handleErrorMessage($queueMessageTransfer);
    }

    /**
     * @param \Generated\Shared\Transfer\QueueMessageTransfer $queueMessageTransfer
     *
     * @return bool
     */
    public function acknowledge(QueueMessageTransfer $queueMessageTransfer)
    {
        $queueAdapter = $this->getQueueAdapter($queueMessageTransfer->getQueueName());

        return $queueAdapter->acknowledge($queueMessageTransfer);
    }

    /**
     * @param string $queueName
     *
     * @return bool
     */
    public function purgeQueue($queueName)
    {
        $queueAdapter = $this->getQueueAdapter($queueName);

        return $queueAdapter->purgeQueue($queueName);
    }

    /**
     * @param string $queueName
     *
     * @return bool
     */
    public function deleteQueue($queueName)
    {
        $queueAdapter = $this->getQueueAdapter($queueName);

        return $queueAdapter->deleteQueue($queueName);
    }

    /**
     * @param string $queueName
     *
     * @return AdapterInterface
     */
    protected function getQueueAdapter($queueName)
    {
        $defaultQueueAdapter = $this->getDefaultQueueAdapter();
        if (!array_key_exists($queueName, $this->queueAdapterNameMapping)) {
            return $defaultQueueAdapter;
        }

        $queueAdapterName = $this->queueAdapterNameMapping[$queueName];
        if (!array_key_exists($queueAdapterName, $this->queueAdapters)) {
            return $defaultQueueAdapter;
        }

        return $this->queueAdapters[$queueAdapterName];
    }

    /**
     * @throws MissingQueueAdapterException
     *
     * @return AdapterInterface
     */
    protected function getDefaultQueueAdapter()
    {
        if (!array_key_exists($this->defaultQueueAdapterName, $this->queueAdapters)) {
            throw new MissingQueueAdapterException(sprintf('There is no such a adapter with this name: %s', $this->defaultQueueAdapterName));
        }

        return $this->queueAdapters[$this->defaultQueueAdapterName];
    }
}
