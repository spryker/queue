<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Business;

use Codeception\Test\Unit;
use ReflectionClass;
use ReflectionMethod;
use Spryker\Zed\Queue\Business\QueueBusinessFactory;

/**
 * Guards the factory's wiring decisions, which are otherwise easy to change silently: which worker a
 * queue:worker:start run gets, and which processing strategy that worker is handed.
 *
 * These read the factory's own source rather than building the container, because constructing the
 * real factory pulls in the full Zed dependency graph and a database. The wiring is a structural
 * fact about the file, so reading it is a fair way to pin it.
 *
 * Auto-generated group annotations
 *
 * @group SprykerTest
 * @group Zed
 * @group Queue
 * @group Business
 * @group QueueBusinessFactoryTest
 * Add your own group annotations below this line
 */
class QueueBusinessFactoryTest extends Unit
{
    /**
     * @var \SprykerTest\Zed\Queue\QueueBusinessTester
     */
    protected $tester;

    public function testTheWorkerChoiceIsDrivenByTheResourceAwareWorkerFlag(): void
    {
        // Act
        $createWorkerSource = $this->getMethodSource('createWorker');

        // Assert
        $this->assertStringContainsString('isResourceAwareQueueWorkerEnabled()', $createWorkerSource);
        $this->assertStringContainsString('createResourceAwareQueueWorker($output)', $createWorkerSource);
        $this->assertStringContainsString('createDefaultChannelWorker($output)', $createWorkerSource);
    }

    public function testTheResourceAwareWorkerIsGivenTheDynamicOrderStrategy(): void
    {
        // Act
        $source = $this->getMethodSource('createResourceAwareQueueWorker');

        // Assert
        $this->assertStringContainsString(
            'createDynamicOrderStrategy($output)',
            $source,
            'Changing which strategy the resource-aware worker runs must be a deliberate, visible act.',
        );
    }

    public function testTheDynamicOrderStrategyIsBuiltOnTheQueueScanner(): void
    {
        // Act
        $source = $this->getMethodSource('createDynamicOrderStrategy');

        // Assert
        $this->assertStringContainsString('new DynamicOrderQueueProcessingStrategy', $source);
        $this->assertStringContainsString('createQueueScanner($output)', $source);
        $this->assertStringContainsString('getDynamicSettingsExpanderPlugins()', $source);
    }

    public function testTheSystemResourcesManagerReadsMemoryThroughTheLinuxReader(): void
    {
        // Act
        $source = $this->getMethodSource('createSystemFreeMemoryReader');

        // Assert
        $this->assertStringContainsString('new LinuxSystemFreeMemoryReader', $source);
    }

    public function testBothWorkerFactoryMethodsExistAndReturnAWorker(): void
    {
        // Arrange
        $reflectionClass = new ReflectionClass(QueueBusinessFactory::class);

        // Act & Assert
        foreach (['createWorker', 'createDefaultChannelWorker', 'createResourceAwareQueueWorker'] as $methodName) {
            $this->assertTrue($reflectionClass->hasMethod($methodName), $methodName . ' is missing');
            $this->assertSame(
                'Spryker\Zed\Queue\Business\Worker\WorkerInterface',
                (string)$reflectionClass->getMethod($methodName)->getReturnType(),
                $methodName . ' must keep returning a WorkerInterface',
            );
        }
    }

    protected function getMethodSource(string $methodName): string
    {
        $reflectionMethod = new ReflectionMethod(QueueBusinessFactory::class, $methodName);
        $fileName = (string)$reflectionMethod->getFileName();
        $lines = (array)file($fileName);

        return implode('', array_slice(
            $lines,
            $reflectionMethod->getStartLine() - 1,
            $reflectionMethod->getEndLine() - $reflectionMethod->getStartLine() + 1,
        ));
    }
}
