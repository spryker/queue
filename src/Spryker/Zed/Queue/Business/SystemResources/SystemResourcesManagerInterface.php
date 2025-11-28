<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\SystemResources;

interface SystemResourcesManagerInterface
{
    /**
     * @param bool $shouldIgnore
     *
     * @return bool
     */
    public function enoughResources(bool $shouldIgnore = false): bool;

    /**
     * @return int
     */
    public function getOwnPeakMemoryGrowth(): int;

    public function getFreeMemory(int $memoryReadProcessTimeout): int;
}
