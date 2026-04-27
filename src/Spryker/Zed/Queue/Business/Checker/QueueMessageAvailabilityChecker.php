<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace Spryker\Zed\Queue\Business\Checker;

use Spryker\Zed\Queue\Business\Reader\QueueConfigReaderInterface;

class QueueMessageAvailabilityChecker implements QueueMessageAvailabilityCheckerInterface
{
    /**
     * @param array<\Spryker\Zed\QueueExtension\Dependency\Plugin\QueueMessageCheckerPluginInterface> $queueMessageCheckerPlugins
     * @param array<string> $queueNames
     */
    public function __construct(
        protected readonly array $queueMessageCheckerPlugins,
        protected readonly QueueConfigReaderInterface $queueConfigReader,
        protected readonly array $queueNames,
    ) {
    }

    public function areQueuesEmpty(): bool
    {
        if (!$this->queueNames) {
            return true;
        }

        $queueNames = $this->queueNames;
        $adapterName = $this->queueConfigReader->getQueueAdapter(reset($queueNames)) ?? '';

        foreach ($this->queueMessageCheckerPlugins as $queueMessageCheckerPlugin) {
            if ($queueMessageCheckerPlugin->isApplicable($adapterName)) {
                return $queueMessageCheckerPlugin->areQueuesEmpty($this->queueNames);
            }
        }

        return true;
    }
}
