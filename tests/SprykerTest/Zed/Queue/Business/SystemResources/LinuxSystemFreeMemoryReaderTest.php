<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Business\SystemResources;

use Codeception\Test\Unit;
use SprykerTest\Zed\Queue\Helper\TestableLinuxSystemFreeMemoryReader;

/**
 * Auto-generated group annotations
 *
 * @group SprykerTest
 * @group Zed
 * @group Queue
 * @group Business
 * @group SystemResources
 * @group LinuxSystemFreeMemoryReaderTest
 * Add your own group annotations below this line
 */
class LinuxSystemFreeMemoryReaderTest extends Unit
{
    /**
     * @var \SprykerTest\Zed\Queue\QueueBusinessTester
     */
    protected $tester;

    /**
     * @var int
     */
    protected const READ_TIMEOUT_SECONDS = 5;

    /**
     * The reader takes the larger of MemFree and MemAvailable.
     *
     * @return void
     */
    public function testAvailableMemoryIsPreferredWhenItExceedsFreeMemory(): void
    {
        // Arrange
        $reader = new TestableLinuxSystemFreeMemoryReader($this->buildMemInfo(
            memTotalKb: 16000000,
            memFreeKb: 1024000,
            memAvailableKb: 8192000,
        ));

        // Act
        $freeMemoryMb = $reader->getFreeMemory(static::READ_TIMEOUT_SECONDS);

        // Assert
        $this->assertSame((int)(8192000 / 1024), $freeMemoryMb);
    }

    public function testFreeMemoryIsUsedWhenItExceedsAvailableMemory(): void
    {
        // Arrange
        $reader = new TestableLinuxSystemFreeMemoryReader($this->buildMemInfo(
            memTotalKb: 16000000,
            memFreeKb: 9000000,
            memAvailableKb: 1000000,
        ));

        // Act
        $freeMemoryMb = $reader->getFreeMemory(static::READ_TIMEOUT_SECONDS);

        // Assert
        $this->assertSame((int)(9000000 / 1024), $freeMemoryMb);
    }

    public function testTheResultIsReportedInMegabytes(): void
    {
        // Arrange
        $oneGigabyteInKb = 1048576;
        $reader = new TestableLinuxSystemFreeMemoryReader($this->buildMemInfo(
            memTotalKb: 4194304,
            memFreeKb: $oneGigabyteInKb,
            memAvailableKb: $oneGigabyteInKb,
        ));

        // Act
        $freeMemoryMb = $reader->getFreeMemory(static::READ_TIMEOUT_SECONDS);

        // Assert
        $this->assertSame(1024, $freeMemoryMb);
    }

    /**
     * Pins a sharp edge rather than a desired behaviour.
     *
     * The parser assumes three "Mem*" lines and indexes `$matches[2]` unconditionally. A kernel or a
     * restricted /proc mount that does not expose MemAvailable (it predates Linux 3.14) still yields
     * a usable MemFree, but every read emits "Undefined array key 2" and "Trying to access array
     * offset on null". The resource-aware worker reads memory on each loop iteration, so this is two
     * warnings per iteration for the life of the worker.
     *
     * @group queue-worker-defect
     *
     * @return void
     */
    public function testMissingAvailableMemoryStillReadsFreeMemoryButWarnsOnEveryCall(): void
    {
        // Arrange
        $memFreeKb = 1024000;
        $reader = new TestableLinuxSystemFreeMemoryReader(
            sprintf("MemTotal:       %8d kB\nMemFree:        %8d kB\n", 16000000, $memFreeKb),
        );

        $warnings = [];
        set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
            $warnings[] = $errstr;

            return true;
        });

        // Act
        try {
            $freeMemoryMb = $reader->getFreeMemory(static::READ_TIMEOUT_SECONDS);
        } finally {
            restore_error_handler();
        }

        // Assert
        $this->assertSame((int)($memFreeKb / 1024), $freeMemoryMb, 'MemFree is still honoured.');
        $this->assertNotSame(
            [],
            $warnings,
            'Reading a meminfo without MemAvailable is not silent; it warns on every single call.',
        );
    }

    /**
     * @dataProvider unparseableMemoryInfoDataProvider
     *
     * @param string $memoryInfo
     *
     * @return void
     */
    public function testUnparseableMemoryInfoReadsAsZero(string $memoryInfo): void
    {
        // Arrange
        $reader = new TestableLinuxSystemFreeMemoryReader($memoryInfo);

        // Act
        $freeMemoryMb = $reader->getFreeMemory(static::READ_TIMEOUT_SECONDS);

        // Assert
        $this->assertSame(
            0,
            $freeMemoryMb,
            'Zero means "could not determine", which SystemResourcesManager turns into an exception '
            . 'or a logged warning depending on configuration.',
        );
    }

    /**
     * @return array<string, array<string>>
     */
    public function unparseableMemoryInfoDataProvider(): array
    {
        return [
            'empty content' => [''],
            'not meminfo at all' => ["total used free\nMem: 16G 8G 8G\n"],
            'keys without numbers' => ["MemTotal:\nMemFree:\nMemAvailable:\n"],
            'a plain error message' => ['cat: /proc/meminfo: No such file or directory'],
        ];
    }

    /**
     * Documents the container-versus-host semantics that the method name hides: inside a container
     * `/proc/meminfo` is the HOST's, so a container capped at 2 GB on a 64 GB host reads as tens of
     * gigabytes free.
     *
     * @return void
     */
    public function testReadsTheHostsMemoryNotTheContainersLimitSoTheGateCanBeWrongInAContainer(): void
    {
        // Arrange
        // What a 2 GB container sees on a 64 GB host: the host's numbers, not its own cgroup limit.
        $reader = new TestableLinuxSystemFreeMemoryReader($this->buildMemInfo(
            memTotalKb: 67108864,
            memFreeKb: 33554432,
            memAvailableKb: 33554432,
        ));

        // Act
        $freeMemoryMb = $reader->getFreeMemory(static::READ_TIMEOUT_SECONDS);

        // Assert
        $this->assertSame(
            32768,
            $freeMemoryMb,
            'Reports 32 GB free inside a 2 GB container. Nothing here consults the cgroup limit.',
        );
    }

    protected function buildMemInfo(int $memTotalKb, int $memFreeKb, int $memAvailableKb): string
    {
        return implode("\n", [
            sprintf('MemTotal:       %8d kB', $memTotalKb),
            sprintf('MemFree:        %8d kB', $memFreeKb),
            sprintf('MemAvailable:   %8d kB', $memAvailableKb),
            'Buffers:          123456 kB',
            'Cached:          1234567 kB',
        ]) . "\n";
    }
}
