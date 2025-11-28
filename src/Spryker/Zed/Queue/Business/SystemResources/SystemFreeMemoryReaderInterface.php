<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\SystemResources;

interface SystemFreeMemoryReaderInterface
{
    public function getFreeMemory(?int $memoryReadProcessTimeout): int;
}
