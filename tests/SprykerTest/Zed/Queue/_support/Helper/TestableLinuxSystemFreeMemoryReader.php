<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Helper;

use Spryker\Zed\Queue\Business\SystemResources\LinuxSystemFreeMemoryReader;

/**
 * Test seam for {@link \Spryker\Zed\Queue\Business\SystemResources\LinuxSystemFreeMemoryReader}.
 */
class TestableLinuxSystemFreeMemoryReader extends LinuxSystemFreeMemoryReader
{
    public function __construct(protected string $memoryInfo = '')
    {
    }

    protected function readSystemMemoryInfo(?int $memoryReadProcessTimeout): string
    {
        return $this->memoryInfo;
    }
}
