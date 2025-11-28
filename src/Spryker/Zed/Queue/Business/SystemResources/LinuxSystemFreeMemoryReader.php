<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\SystemResources;

use Symfony\Component\Process\Process;
use Throwable;

class LinuxSystemFreeMemoryReader implements SystemFreeMemoryReaderInterface
{
    public function getFreeMemory(?int $memoryReadProcessTimeout): int
    {
        $memory = $this->readSystemMemoryInfo($memoryReadProcessTimeout);
        if (!preg_match_all('/(Mem\w+[l|e]):\s+(\d+)/msi', $memory, $matches, PREG_SET_ORDER)) {
            return 0;
        }

        $free = round((int)$matches[1][2]) / 1024;
        $available = round((int)$matches[2][2]) / 1024;

        return (int)max($free, $available);
    }

    protected function readSystemMemoryInfo(?int $memoryReadProcessTimeout): string
    {
        $memory = file_get_contents('/proc/meminfo') ?: '';
        if ($memory) {
            return $memory;
        }

        $memoryReader = new Process(['cat', '/proc/meminfo'], null, null, null, $memoryReadProcessTimeout);
        try {
            $memoryReader->run();
            $output = $memoryReader->getOutput();
        } catch (Throwable $exception) {
            $output = '';
        }

        return $output;
    }
}
