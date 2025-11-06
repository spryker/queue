<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Worker;

interface ProcessMemoryTrackerInterface
{
    /**
     * @param int $pid
     *
     * @return string
     */
    public function getMemoryInfoForPid(int $pid): string;

    /**
     * @return void
     */
    public function reset(): void;
}
