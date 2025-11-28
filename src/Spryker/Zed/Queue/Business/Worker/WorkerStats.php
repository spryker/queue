<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Worker;

class WorkerStats
{
    /**
     * @var array<string, int>
     */
    protected array $queueTasksQuantity = [];

    /**
     * @var array<string, int>
     */
    protected array $locationTasksQuantity = [];

    /**
     * @var array<string, int>
     */
    protected array $errorsQuantity = [];

    /**
     * @var array<string, int>
     */
    protected array $cyclesQuantity = [];

    /**
     * @var array<string, int>
     */
    protected array $procQuantity = [];

    /**
     * @var array<string, mixed>
     */
    protected array $metrics = [];

    /**
     * @param string $name
     * @param string|float|int $value
     *
     * @return $this
     */
    public function addMetric(string $name, $value)
    {
        $this->metrics[$name] = $value;

        return $this;
    }

    /**
     * @return $this
     */
    public function addCycle()
    {
        $this->cyclesQuantity['cycles'] = ($this->cyclesQuantity['cycles'] ?? 0) + 1;

        return $this;
    }

    /**
     * @return $this
     */
    public function addSkipCycle()
    {
        $this->cyclesQuantity['skip-cycle'] = ($this->cyclesQuantity['skip-cycle'] ?? 0) + 1;

        return $this;
    }

    /**
     * @return $this
     */
    public function addEmptyCycle()
    {
        $this->cyclesQuantity['empty'] = ($this->cyclesQuantity['empty'] ?? 0) + 1;

        return $this;
    }

    /**
     * @return $this
     */
    public function addNoSlotCycle()
    {
        $this->cyclesQuantity['no_slot'] = ($this->cyclesQuantity['no_slot'] ?? 0) + 1;

        return $this;
    }

    /**
     * @return $this
     */
    public function addNoMemoryCycle()
    {
        $this->cyclesQuantity['no_mem'] = ($this->cyclesQuantity['no_mem'] ?? 0) + 1;

        return $this;
    }

    /**
     * @return $this
     */
    public function addCooldownCycle()
    {
        $this->cyclesQuantity['cooldown'] = ($this->cyclesQuantity['cooldown'] ?? 0) + 1;

        return $this;
    }

    /**
     * @param string $locationName
     *
     * @return $this
     */
    public function addLocationQuantity(string $locationName)
    {
        $this->locationTasksQuantity[$locationName] = ($this->locationTasksQuantity[$locationName] ?? 0) + 1;

        return $this;
    }

    /**
     * @param string $queueQty
     *
     * @return $this
     */
    public function addQueueQuantity(string $queueQty)
    {
        $this->queueTasksQuantity[$queueQty] = ($this->queueTasksQuantity[$queueQty] ?? 0) + 1;

        return $this;
    }

    /**
     * @param string $errorName
     *
     * @return $this
     */
    public function addErrorQuantity(string $errorName)
    {
        $this->errorsQuantity[$errorName] = ($this->errorsQuantity[$errorName] ?? 0) + 1;

        return $this;
    }

    /**
     * @param string $name
     * @param int|null $newValue
     *
     * @return $this
     */
    public function addProcQuantity(string $name, ?int $newValue = null)
    {
        $this->procQuantity[$name] = $newValue ?? ($this->procQuantity[$name] ?? 0) + 1;

        return $this;
    }

    /**
     * @return int
     */
    public function getSuccessRate(): int
    {
        $failed = $this->procQuantity['failed'] ?? 0;
        $new = $this->procQuantity['new'] ?? 1;

        return (int)floor((($new - $failed) / $new) * 100);
    }

    /**
     * @return array
     */
    public function getCycleEfficiency(): array
    {
        $cycles = $this->cyclesQuantity['cycles'] ?? 1;
        $skipped = $this->cyclesQuantity['skip-cycle'] ?? 0;

        $data = [
            'efficiency' => sprintf('%.2f%%', 100 * ($cycles - $skipped) / $cycles),
        ];

        foreach ($this->cyclesQuantity as $key => $value) {
            if ($key === 'cycles') {
                continue;
            }

            $data[$key] = sprintf('%.2f%%', 100 * $value / $cycles);
        }

        return $data;
    }

    /**
     * @return array
     */
    public function getStats(): array
    {
        return [
            'queues' => $this->queueTasksQuantity,
            'locations' => $this->locationTasksQuantity,
            'errors' => $this->errorsQuantity,
            'cycles' => $this->cyclesQuantity,
            'proc' => $this->procQuantity,
            'metrics' => $this->metrics,
        ];
    }
}
