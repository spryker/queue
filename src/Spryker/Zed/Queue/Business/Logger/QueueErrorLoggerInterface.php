<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Logger;

interface QueueErrorLoggerInterface
{
    /**
     * @param string $queueName
     * @param array<\Generated\Shared\Transfer\QueueReceiveMessageTransfer> $messages
     *
     * @return void
     */
    public function logFailedMessages(string $queueName, array $messages): void;
}
