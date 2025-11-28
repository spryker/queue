<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Strategy;

use Spryker\Zed\Queue\Business\Queue\QueueMetrics;

interface QueueProcessingStrategyInterface
{
    /**
     * @return \Spryker\Zed\Queue\Business\Queue\QueueMetrics|null
     */
    public function getNextQueue(): ?QueueMetrics;
}
