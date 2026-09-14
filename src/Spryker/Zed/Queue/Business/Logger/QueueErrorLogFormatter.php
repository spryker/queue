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
     * Omits the parameter type to stay compatible with both monolog/monolog ^1.25.0 || ^2.0.0
     * (array $record) and ^3.0.0 (Monolog\LogRecord $record, which also supports array access).
     *
     * @param \Monolog\LogRecord|array<string, mixed> $record
     */
    public function format($record): string
    {
        return $record['message'] ?? '';
    }

    /**
     * @param array<array<string, mixed>|\Monolog\LogRecord> $records
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
