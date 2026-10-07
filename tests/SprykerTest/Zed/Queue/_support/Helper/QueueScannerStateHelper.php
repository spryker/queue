<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Helper;

use LogicException;
use ReflectionClass;
use Spryker\Zed\Queue\Business\Scanner\QueueScanner;

/**
 * Resets the process-wide caches {@link \Spryker\Zed\Queue\Business\Scanner\QueueScanner} keeps in static
 * properties: the store-name list, the dynamic-store flag and the sync-queue name map.
 */
final class QueueScannerStateHelper
{
    /**
     * @var array<string, mixed>
     */
    protected const RESET_VALUES = [
        'storeNames' => null,
        'isDynamicStoreEnabled' => null,
        'isSyncQueueMap' => [],
    ];

    /**
     * @throws \LogicException
     *
     * @return void
     */
    public static function reset(): void
    {
        $reflectionClass = new ReflectionClass(QueueScanner::class);

        foreach (static::RESET_VALUES as $propertyName => $value) {
            if (!$reflectionClass->hasProperty($propertyName)) {
                throw new LogicException(sprintf(
                    'QueueScanner::$%s no longer exists. Update QueueScannerStateHelper::RESET_VALUES, '
                    . 'otherwise these tests quietly become order-dependent again, which is the exact '
                    . 'failure this helper exists to prevent.',
                    $propertyName,
                ));
            }

            $reflectionClass->getProperty($propertyName)->setValue(null, $value);
        }
    }
}
