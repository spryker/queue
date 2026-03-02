<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Worker;

class QueueMessageFormatter implements QueueMessageFormatterInterface
{
    public function formatQueueStatusMessage(
        int $rowId,
        string $queueName,
        int $busyProcessNumber,
        int $newProcessNumber,
        ?int $batchSize,
        ?float $elapsedTime,
        string $memoryInfo
    ): string {
        $newProcessNumberFormatted = $newProcessNumber > 0
            ? sprintf('<fg=green;options=bold>%d</>', $newProcessNumber)
            : (string)$newProcessNumber;

        $busyProcessNumberFormatted = $busyProcessNumber > 0
            ? sprintf('<fg=red;options=bold>%d</>', $busyProcessNumber)
            : (string)$busyProcessNumber;

        $rowIdFormatted = sprintf('%02d)', $rowId);
        $queueNameFormatted = sprintf('[%s:%s]', $this->getStoreRegion(), $queueName);

        $additionalInfo = $this->buildAdditionalInfo($batchSize, $elapsedTime);

        return sprintf(
            '%s %s New: %s Busy: %s%s%s',
            $rowIdFormatted,
            $queueNameFormatted,
            $newProcessNumberFormatted,
            $busyProcessNumberFormatted,
            $additionalInfo,
            $memoryInfo,
        );
    }

    protected function buildAdditionalInfo(?int $batchSize, ?float $elapsedTime): string
    {
        $additionalInfo = '';

        if ($batchSize !== null) {
            $additionalInfo .= sprintf(' Batch: %d', $batchSize);
        }

        if ($elapsedTime !== null) {
            $additionalInfo .= sprintf(' Elapsed: %s', $this->formatElapsedTime($elapsedTime));
        }

        return $additionalInfo;
    }

    protected function getStoreRegion(): ?string
    {
        if (defined('APPLICATION_REGION')) {
            return APPLICATION_REGION;
        }

        if (defined('APPLICATION_STORE')) {
            return APPLICATION_STORE;
        }

        return null;
    }

    protected function formatElapsedTime(float $elapsedTime): string
    {
        $minutes = floor($elapsedTime / 60);
        $seconds = floor($elapsedTime - ($minutes * 60));

        return sprintf('%02d:%02d', $minutes, $seconds);
    }
}
