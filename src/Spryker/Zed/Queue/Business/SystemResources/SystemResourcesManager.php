<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\SystemResources;

use RuntimeException;
use Spryker\Zed\Queue\QueueConfig;

class SystemResourcesManager implements SystemResourcesManagerInterface
{
    /**
     * @param \Spryker\Zed\Queue\QueueConfig $queueConfig
     */
    public function __construct(
        protected QueueConfig $queueConfig,
        protected SystemFreeMemoryReaderInterface $systemFreeMemoryReader,
    ) {
    }

    /**
     * Executed multiple times in a loop within X minutes
     * We have a choice on what to do in case we failed to determine free memory (e.g. 0)
     *   A. consider it as a no go - like we have NO free memory, so no processes will run
     *   B. just ignore memory limit then, but alert in logs
     *
     * @param bool $shouldIgnore
     *
     * @throws \RuntimeException
     *
     * @return bool
     */
    public function enoughResources(bool $shouldIgnore = false): bool
    {
        $freeMemory = $this->systemFreeMemoryReader->getFreeMemory($this->queueConfig->memoryReadProcessTimeout());
        if ($freeMemory === 0 && !$shouldIgnore) {
            throw new RuntimeException('Could not detect free memory for queue worker and configured not to ignore that.');
        }

        return $freeMemory > $this->queueConfig->getFreeMemoryBuffer();
    }

    /**
     * @return int % of initial Worker consumption
     */
    public function getOwnPeakMemoryGrowth(): int
    {
        static $ownInitialMemoryConsumption = 0;

        if (!$ownInitialMemoryConsumption) {
            $ownInitialMemoryConsumption = memory_get_peak_usage(true);
        }

        $diffNow = memory_get_peak_usage(true) - $ownInitialMemoryConsumption;

        return $diffNow <= 0 ?
            0 :
            (int)round(100 * $diffNow / $ownInitialMemoryConsumption);
    }

    public function getFreeMemory(int $memoryReadProcessTimeout): int
    {
        return $this->systemFreeMemoryReader->getFreeMemory($memoryReadProcessTimeout);
    }
}
