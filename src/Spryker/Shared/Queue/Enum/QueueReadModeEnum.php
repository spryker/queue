<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Shared\Queue\Enum;

enum QueueReadModeEnum: int
{
    /**
     * Default mode, based on the order of queues in \Pyz\Zed\Queue\QueueDependencyProvider::getProcessorMessagePlugins
     */
    case MODE_READ_ORDER = 0;

    case MODE_PREFER_PUB = 1;

    case MODE_PREFER_SYNC = 2;

    case MODE_PREFER_BIG = 4;

    case MODE_PREFER_SMALL = 8;

    case MODE_PREFER_DEFAULT_STORE = 16;

    case MODE_PREFER_FAST = 32;

    case MODE_PREFER_SLOW = 64;

    case MODE_ONLY_PREFERRED = 128;
}
