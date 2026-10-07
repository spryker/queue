<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Business\Worker;

use Codeception\Test\Unit;
use Spryker\Zed\Queue\Business\Worker\QueueMessageFormatter;

/**
 * Auto-generated group annotations
 *
 * @group SprykerTest
 * @group Zed
 * @group Queue
 * @group Business
 * @group Worker
 * @group QueueMessageFormatterTest
 * Add your own group annotations below this line
 */
class QueueMessageFormatterTest extends Unit
{
    /**
     * @var \SprykerTest\Zed\Queue\QueueBusinessTester
     */
    protected $tester;

    /**
     * @var string
     */
    protected const QUEUE_NAME = 'event';

    public function testTheRowNumberIsZeroPaddedAndTheQueueIsBracketed(): void
    {
        // Act
        $message = (new QueueMessageFormatter())->formatQueueStatusMessage(
            3,
            static::QUEUE_NAME,
            0,
            0,
            null,
            null,
            '',
        );

        // Assert
        $this->assertStringStartsWith('03)', $message);
        $this->assertStringContainsString(static::QUEUE_NAME . ']', $message);
    }

    /**
     * Colour is what makes a busy worker readable at a glance, so the highlighting is part of the contract
     * rather than incidental.
     *
     * @return void
     */
    public function testNewAndBusyCountsAreHighlightedOnlyWhenNonZero(): void
    {
        // Act
        $withWork = (new QueueMessageFormatter())
            ->formatQueueStatusMessage(1, static::QUEUE_NAME, 2, 3, null, null, '');
        $idle = (new QueueMessageFormatter())
            ->formatQueueStatusMessage(1, static::QUEUE_NAME, 0, 0, null, null, '');

        // Assert
        $this->assertStringContainsString('<fg=green;options=bold>3</>', $withWork);
        $this->assertStringContainsString('<fg=red;options=bold>2</>', $withWork);
        $this->assertStringContainsString('New: 0', $idle);
        $this->assertStringNotContainsString('fg=', $idle);
    }

    public function testTheBatchSizeIsOmittedWhenTheQueueHasNoneConfigured(): void
    {
        // Act
        $withBatch = (new QueueMessageFormatter())
            ->formatQueueStatusMessage(1, static::QUEUE_NAME, 0, 1, 450, null, '');
        $withoutBatch = (new QueueMessageFormatter())
            ->formatQueueStatusMessage(1, static::QUEUE_NAME, 0, 1, null, null, '');

        // Assert
        $this->assertStringContainsString('Batch: 450', $withBatch);
        $this->assertStringNotContainsString('Batch:', $withoutBatch);
    }

    /**
     * @dataProvider elapsedTimeDataProvider
     *
     * @param float $elapsedSeconds
     * @param string $expected
     *
     * @return void
     */
    public function testElapsedTimeIsFormattedAsMinutesAndSeconds(float $elapsedSeconds, string $expected): void
    {
        // Act
        $message = (new QueueMessageFormatter())
            ->formatQueueStatusMessage(1, static::QUEUE_NAME, 0, 1, null, $elapsedSeconds, '');

        // Assert
        $this->assertStringContainsString('Elapsed: ' . $expected, $message);
    }

    /**
     * @return array<string, array<mixed>>
     */
    public function elapsedTimeDataProvider(): array
    {
        return [
            'zero' => [0.0, '00:00'],
            'under a minute' => [45.9, '00:45'],
            'exactly one minute' => [60.0, '01:00'],
            'minutes and seconds' => [125.4, '02:05'],
            'past the worker threshold' => [3599.0, '59:59'],
            'over an hour keeps counting in minutes' => [3660.0, '61:00'],
        ];
    }

    public function testTheElapsedSectionIsOmittedEntirelyWhenNoTimeIsGiven(): void
    {
        // Act
        $message = (new QueueMessageFormatter())
            ->formatQueueStatusMessage(1, static::QUEUE_NAME, 0, 1, null, null, '');

        // Assert
        $this->assertStringNotContainsString('Elapsed:', $message);
    }

    public function testTheMemoryInfoIsAppendedVerbatim(): void
    {
        // Act
        $message = (new QueueMessageFormatter())
            ->formatQueueStatusMessage(1, static::QUEUE_NAME, 0, 1, null, null, ' Memory: 42 MB');

        // Assert
        $this->assertStringEndsWith(' Memory: 42 MB', $message);
    }
}
