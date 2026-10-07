<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Helper;

use Spryker\Zed\Queue\Business\Worker\ProcessMemoryTracker;

/**
 * Test seam for {@link \Spryker\Zed\Queue\Business\Worker\ProcessMemoryTracker}.
 */
class TestableProcessMemoryTracker extends ProcessMemoryTracker
{
    /**
     * to 0, stands for a process that has finished.
     *
     * @param array<int, int> $memoryByPid Bytes in use, keyed by pid. A pid that is absent, or set
     */
    public function __construct(protected array $memoryByPid = [])
    {
    }

    public function setMemoryForPid(int $pid, int $bytes): void
    {
        $this->memoryByPid[$pid] = $bytes;
    }

    protected function getProcessMemoryUsage(int $pid): int
    {
        return $this->memoryByPid[$pid] ?? 0;
    }
}
