<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Helper;

/**
 * Fixture values shared by the queue worker tests.
 */
final class QueueWorkerFixtures
{
    /**
     * @var string
     */
    public const QUEUE_NAME = 'event';

    /**
     * @var string
     */
    public const OTHER_QUEUE_NAME = 'publish';

    /**
     * @var string
     */
    public const ADAPTER_NAME = 'rabbitmq';

    /**
     * @var string
     */
    public const COMMAND = '/app/vendor/bin/console queue:task:start';

    /**
     * Every delay the worker would sleep through is configured to zero, so the tests assert on the branch
     * that was taken rather than on elapsed wall-clock time.
     *
     * @var int
     */
    public const NO_SLEEP_MILLISECONDS = 0;
}
