<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Task;

interface TaskDebugHelperInterface
{
    /**
     * @param array<\Generated\Shared\Transfer\QueueReceiveMessageTransfer> $messages
     * @param string $queueName
     *
     * @return void
     */
    public function startMessages(array $messages, string $queueName): void;

    /**
     * @param array<\Generated\Shared\Transfer\QueueReceiveMessageTransfer> $messages
     * @param string $queueName
     *
     * @return void
     */
    public function finishMessages(array $messages, string $queueName): void;
}
