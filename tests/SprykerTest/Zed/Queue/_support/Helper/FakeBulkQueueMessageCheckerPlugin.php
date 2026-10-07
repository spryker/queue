<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Helper;

use ArrayObject;
use Generated\Shared\Transfer\QueueInformationCollectionTransfer;
use Generated\Shared\Transfer\QueueInformationTransfer;
use Spryker\Zed\QueueExtension\Dependency\Plugin\QueueBulkMessageCheckerPluginInterface;
use Spryker\Zed\QueueExtension\Dependency\Plugin\QueueMessageCheckerPluginInterface;

/**
 * A bulk queue-message checker.
 */
class FakeBulkQueueMessageCheckerPlugin implements QueueBulkMessageCheckerPluginInterface, QueueMessageCheckerPluginInterface
{
    /**
     * @param array<string, int> $readyCountByQueueName
     */
    public function __construct(
        protected array $readyCountByQueueName = [],
        protected bool $isApplicable = true,
        protected bool $areQueuesEmpty = true
    ) {
    }

    /**
     * @param array<string> $queueNames
     *
     * @return \Generated\Shared\Transfer\QueueInformationCollectionTransfer
     */
    public function getQueues(array $queueNames): QueueInformationCollectionTransfer
    {
        $queueInformationTransfers = new ArrayObject();

        foreach ($this->readyCountByQueueName as $queueName => $readyCount) {
            $queueInformationTransfers->append(
                (new QueueInformationTransfer())
                    ->setName($queueName)
                    ->setReadyCount($readyCount),
            );
        }

        return (new QueueInformationCollectionTransfer())->setQueues($queueInformationTransfers);
    }

    /**
     * @param array<string> $queueNames
     *
     * @return bool
     */
    public function areQueuesEmpty(array $queueNames): bool
    {
        return $this->areQueuesEmpty;
    }

    public function isApplicable(string $adapterName): bool
    {
        return $this->isApplicable;
    }
}
