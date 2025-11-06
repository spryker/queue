<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Worker;

class ProcessMemoryTracker implements ProcessMemoryTrackerInterface
{
    /**
     * @var array<int, int>
     */
    protected array $pidMemoryUsage = [];

    /**
     * @param int $pid
     *
     * @return string
     */
    public function getMemoryInfoForPid(int $pid): string
    {
        $currentMemory = $this->getProcessMemoryUsage($pid);

        if ($currentMemory === 0) {
            return $this->formatFinishedProcessMemory($pid);
        }

        return $this->formatActiveProcessMemory($pid, $currentMemory);
    }

    /**
     * @return void
     */
    public function reset(): void
    {
        $this->pidMemoryUsage = [];
    }

    /**
     * Get memory usage for process and its direct children (not siblings)
     *
     * @param int $pid
     *
     * @return int
     */
    protected function getProcessMemoryUsage(int $pid): int
    {
        // Get memory for the process itself
        $processMemory = $this->getSingleProcessMemoryUsage($pid);

        if ($processMemory === 0) {
            return 0;
        }

        // Get memory for direct children only (not siblings or parent)
        $childrenMemory = $this->getChildrenProcessMemoryUsage($pid);

        return $processMemory + $childrenMemory;
    }

    /**
     * @param int $pid
     *
     * @return int
     */
    protected function getChildrenProcessMemoryUsage(int $pid): int
    {
        $command = sprintf('ps -o pid=,ppid=,rss= | awk \'$2==%d {sum+=$3} END {print sum}\'', $pid);
        $output = shell_exec($command);

        $trimmedOutput = trim((string)$output);

        if ($trimmedOutput === '' || !is_numeric($trimmedOutput)) {
            return 0;
        }

        $memoryKb = (int)$trimmedOutput;

        return $memoryKb * 1024;
    }

    /**
     * @param int $pid
     *
     * @return int
     */
    protected function getSingleProcessMemoryUsage(int $pid): int
    {
        $command = sprintf('ps -o rss= -p %d 2>&1', $pid);
        $output = shell_exec($command);

        $trimmedOutput = trim((string)$output);

        if ($trimmedOutput === '' || strpos($trimmedOutput, 'No such process') !== false) {
            return 0;
        }

        $memoryKb = (int)$trimmedOutput;

        if ($memoryKb === 0) {
            return 0;
        }

        return $memoryKb * 1024;
    }

    /**
     * @param int $pid
     *
     * @return string
     */
    protected function formatFinishedProcessMemory(int $pid): string
    {
        if (!isset($this->pidMemoryUsage[$pid])) {
            return ' Memory: starting...';
        }

        $lastMemoryMb = $this->convertBytesToMb($this->pidMemoryUsage[$pid]);

        return sprintf(' Memory: %s MB (finished)', $lastMemoryMb);
    }

    /**
     * @param int $pid
     * @param int $currentMemory
     *
     * @return string
     */
    protected function formatActiveProcessMemory(int $pid, int $currentMemory): string
    {
        $isFirstMeasurement = !isset($this->pidMemoryUsage[$pid]);
        $lastMemory = $this->pidMemoryUsage[$pid] ?? 0;
        $memoryDiff = $currentMemory - $lastMemory;

        $this->pidMemoryUsage[$pid] = $currentMemory;

        $currentMemoryMb = $this->convertBytesToMb($currentMemory);

        if ($isFirstMeasurement) {
            return sprintf(' Memory: %s MB', $currentMemoryMb);
        }

        $memoryDiffMb = $this->convertBytesToMb($memoryDiff);
        $diffSign = $memoryDiff >= 0 ? '+' : '';

        return sprintf(' Memory: %s MB (%s%s MB)', $currentMemoryMb, $diffSign, $memoryDiffMb);
    }

    /**
     * @param int $bytes
     *
     * @return float
     */
    protected function convertBytesToMb(int $bytes): float
    {
        return round($bytes / 1024 / 1024, 2);
    }
}
