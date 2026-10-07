<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Business\Strategy;

use ArrayIterator;
use Codeception\Test\Unit;
use Error;
use Spryker\Zed\Queue\Business\Queue\QueueMetrics;
use Spryker\Zed\Queue\Business\Strategy\ConditionBasedIterator;

/**
 * Auto-generated group annotations
 *
 * @group SprykerTest
 * @group Zed
 * @group Queue
 * @group Business
 * @group Strategy
 * @group ConditionBasedIteratorTest
 * Add your own group annotations below this line
 */
class ConditionBasedIteratorTest extends Unit
{
    /**
     * @var \SprykerTest\Zed\Queue\QueueBusinessTester
     */
    protected $tester;

    public function testWithoutAConditionEveryNextAdvancesTheUnderlyingIterator(): void
    {
        // Arrange
        $iterator = new ConditionBasedIterator($this->createInnerIterator(['a', 'b', 'c']));

        // Act
        $seen = $this->drain($iterator);

        // Assert
        $this->assertSame(['a', 'b', 'c'], $seen);
    }

    /**
     * This is what lets the dynamic strategy hand out the same queue several times in a row, once per batch
     * of messages waiting on it.
     *
     * @return void
     */
    public function testATrueConditionRepeatsTheSameEntryInsteadOfAdvancing(): void
    {
        // Arrange
        $repeatsPerEntry = 2;
        $iterator = new ConditionBasedIterator(
            $this->createInnerIterator(['a', 'b']),
            fn (?QueueMetrics $queuemetrics, int $currentIndex): bool => $currentIndex < $repeatsPerEntry,
        );

        // Act
        $seen = $this->drain($iterator);

        // Assert
        $this->assertSame(
            ['a', 'a', 'a', 'b', 'b', 'b'],
            $seen,
            'Each entry is returned once plus once per repeat the condition allows.',
        );
    }

    public function testTheRepeatIndexRestartsAtZeroForEachNewEntry(): void
    {
        // Arrange
        $observedIndexes = [];
        $iterator = new ConditionBasedIterator(
            $this->createInnerIterator(['a', 'b']),
            function (?QueueMetrics $queuemetrics, int $currentIndex) use (&$observedIndexes): bool {
                $observedIndexes[] = $currentIndex;

                return $currentIndex < 1;
            },
        );

        // Act
        $this->drain($iterator);

        // Assert
        $this->assertSame(
            [0, 1, 0, 1],
            $observedIndexes,
            'The index counts repeats of the current entry and resets when the iterator moves on.',
        );
    }

    public function testAConditionThatIsNeverTrueBehavesLikeNoConditionAtAll(): void
    {
        // Arrange
        $iterator = new ConditionBasedIterator(
            $this->createInnerIterator(['a', 'b']),
            fn (?QueueMetrics $queuemetrics, int $currentIndex): bool => false,
        );

        // Act
        $seen = $this->drain($iterator);

        // Assert
        $this->assertSame(['a', 'b'], $seen);
    }

    public function testTheConditionIsBoundToTheIteratorItself(): void
    {
        // Arrange
        $boundTo = null;
        $iterator = new ConditionBasedIterator(
            $this->createInnerIterator(['a']),
            function (?QueueMetrics $queuemetrics, int $currentIndex) use (&$boundTo): bool {
                $boundTo = $this;

                return false;
            },
        );

        // Act
        $iterator->current();
        $iterator->next();

        // Assert
        $this->assertSame(
            $iterator,
            $boundTo,
            'The condition is invoked with Closure::call($this), so it sees the iterator as $this.',
        );
    }

    public function testRewindRestartsBothTheUnderlyingIteratorAndTheRepeatIndex(): void
    {
        // Arrange
        $iterator = new ConditionBasedIterator(
            $this->createInnerIterator(['a', 'b']),
            fn (?QueueMetrics $queuemetrics, int $currentIndex): bool => $currentIndex < 1,
        );
        $iterator->current();
        $iterator->next();

        // Act
        $iterator->rewind();

        // Assert
        $this->assertTrue($iterator->valid());
        $this->assertSame('a', $iterator->current()->getQueueName());
        $this->assertSame(0, $iterator->key());
    }

    public function testValidAndKeyDelegateToTheUnderlyingIterator(): void
    {
        // Arrange
        $iterator = new ConditionBasedIterator($this->createInnerIterator(['a']));

        // Act & Assert
        $this->assertTrue($iterator->valid());
        $this->assertSame(0, $iterator->key());

        $iterator->current();
        $iterator->next();

        $this->assertFalse($iterator->valid());
    }

    public function testAnExhaustedIteratorReportsItselfInvalid(): void
    {
        // Arrange
        $iterator = new ConditionBasedIterator($this->createInnerIterator([]));

        // Act & Assert
        $this->assertFalse(
            $iterator->valid(),
            'The strategies rely on this: an invalid iterator is what triggers the next full scan.',
        );
    }

    /**
     * Pins a sharp edge rather than a desired behaviour.
     *
     * `$currentTransfer` is a typed property with no default, and only `current()` ever assigns it, so
     * calling `next()` on a freshly constructed iterator reads an uninitialised property and throws.
     * Both strategies happen to call `current()` immediately before `next()`, which is the only reason
     * this is not reachable in production today.
     *
     * @group queue-worker-defect
     *
     * @return void
     */
    public function testCallingNextBeforecurrentThrowsBecauseTheHeldEntryIsUninitialised(): void
    {
        // Arrange
        $iterator = new ConditionBasedIterator(
            $this->createInnerIterator(['a']),
            fn (?QueueMetrics $queuemetrics, int $currentIndex): bool => false,
        );

        // Assert
        $this->expectException(Error::class);
        $this->expectExceptionMessageMatches('/must not be accessed before initialization/');

        // Act
        $iterator->next();
    }

    /**
     * @param array<string> $queueNames
     *
     * @return \ArrayIterator<int|string, \Spryker\Zed\Queue\Business\Queue\QueueMetrics>
     */
    protected function createInnerIterator(array $queueNames): ArrayIterator
    {
        $queuemetrics = array_map(
            fn (string $queueName): QueueMetrics => (new QueueMetrics())->setQueueName($queueName),
            $queueNames,
        );

        return new ArrayIterator($queuemetrics);
    }

    /**
     * Walks the iterator the way the strategies do: read the current entry, then advance.
     *
     * @param \Spryker\Zed\Queue\Business\Strategy\ConditionBasedIterator $iterator
     * @param int $maxIterations
     *
     * @return array<string>
     */
    protected function drain(ConditionBasedIterator $iterator, int $maxIterations = 50): array
    {
        $seen = [];

        while ($iterator->valid() && count($seen) < $maxIterations) {
            $seen[] = $iterator->current()->getQueueName();
            $iterator->next();
        }

        return $seen;
    }
}
