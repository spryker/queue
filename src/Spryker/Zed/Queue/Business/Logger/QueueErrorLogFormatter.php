<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Logger;

use Monolog\Formatter\FormatterInterface;

class QueueErrorLogFormatter implements FormatterInterface
{
    /**
     * @param array $record
     *
     * @return string
     */
    public function format(array $record): string
    {
        return $record['message'] ?? '';
    }

    /**
     * @param array<array> $records
     *
     * @return array<string>
     */
    public function formatBatch(array $records): array
    {
        $formatted = [];

        foreach ($records as $record) {
            $formatted[] = $this->format($record);
        }

        return $formatted;
    }
}
