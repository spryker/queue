<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Worker;

use Generated\Shared\Transfer\QueueReceiveMessageTransfer;
use Symfony\Component\Process\Process;

interface WorkerDebugHelperInterface
{
    /**
     * @param string $queue
     *
     * @return void
     */
    public function writeQueueProcessStarted(string $queue): void;

    /**
     * @param Process $process
     *
     * @return void
     */
    public function logProcessTermination(Process $process): void;

}
