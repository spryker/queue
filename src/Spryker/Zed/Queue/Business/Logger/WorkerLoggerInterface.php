<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Logger;

interface WorkerLoggerInterface
{
    public function logNotOftenThan(string $timerName, string|callable $message, string $level = 'debug', int $intervalSec = 1): void;

    public function info(string $message): void;

    public function error(string $message): void;

    public function debug(string $message): void;
}
