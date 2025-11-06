<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Worker;

interface WorkerProgressBarInterface
{
    /**
     * @param int $steps
     * @param int $round
     *
     * @return void
     */
    public function start($steps, $round);

    /**
     * @param int $step
     *
     * @return void
     */
    public function advance($step = 1);

    /**
     * @return void
     */
    public function clear(): void;

    /**
     * @return void
     */
    public function display(): void;

    /**
     * @param int $progress
     *
     * @return void
     */
    public function setProgress(int $progress): void;

    /**
     * @return void
     */
    public function finish(): void;

    /**
     * @param int $rowId
     * @param string $queueName
     * @param int $pid
     * @param int $busyProcessNumber
     * @param int $newProcessNumber
     * @param int|null $batchSize
     * @param float|null $elapsedTime
     *
     * @return void
     */
    public function writeConsoleMessage(
        $rowId,
        $queueName,
        $pid,
        $busyProcessNumber,
        $newProcessNumber,
        $batchSize = null,
        $elapsedTime = null
    );

    /**
     * @param array<string> $errors
     *
     * @return void
     */
    public function writeErrors(array $errors): void;

    /**
     * @return void
     */
    public function reset();
}
