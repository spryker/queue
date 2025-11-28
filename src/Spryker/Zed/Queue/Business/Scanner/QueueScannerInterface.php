<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Scanner;

use ArrayObject;

interface QueueScannerInterface
{
    /**
     * @param array $storeNames
     * @param int $emptyScanCooldownSeconds
     *
     * @return \ArrayObject<int, \Spryker\Zed\Queue\Business\Queue\QueueMetrics>
     */
    public function scanQueues(array $storeNames = [], int $emptyScanCooldownSeconds = 5): ArrayObject;
}
