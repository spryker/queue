<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Worker;

interface QueueMessageFormatterInterface
{
    public function formatQueueStatusMessage(
        int $rowId,
        string $queueName,
        int $busyProcessNumber,
        int $newProcessNumber,
        ?int $batchSize,
        ?float $elapsedTime,
        string $memoryInfo
    ): string;
}
