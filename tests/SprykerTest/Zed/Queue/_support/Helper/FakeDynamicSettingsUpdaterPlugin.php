<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Helper;

use Generated\Shared\Transfer\QueueDynamicSettingsTransfer;
use Spryker\Zed\QueueExtension\Dependency\Plugin\DynamicSettingsUpdaterPluginInterface;

/**
 * Forces the dynamic settings to fixed values on every scan.
 */
class FakeDynamicSettingsUpdaterPlugin implements DynamicSettingsUpdaterPluginInterface
{
    /**
     * @var int
     */
    protected int $updateCallCount = 0;

    public function __construct(
        protected int $mode,
        protected int $bigQueueBatches,
        protected int $limitPerQueue
    ) {
    }

    /**
     * Number of times the strategy applied these settings, which is once per full rescan.
     *
     * @return int
     */
    public function getUpdateCallCount(): int
    {
        return $this->updateCallCount;
    }

    public function update(QueueDynamicSettingsTransfer $queueDynamicSettingsTransfer): QueueDynamicSettingsTransfer
    {
        $this->updateCallCount++;

        return $queueDynamicSettingsTransfer
            ->setMode($this->mode)
            ->setBigQueueBatches($this->bigQueueBatches)
            ->setLimitPerQueue($this->limitPerQueue);
    }
}
