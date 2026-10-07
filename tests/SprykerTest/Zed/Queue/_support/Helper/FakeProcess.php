<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

namespace SprykerTest\Zed\Queue\Helper;

use Symfony\Component\Process\Process;

/**
 * A Symfony Process that never spawns anything.
 */
class FakeProcess extends Process
{
    /**
     * @var int
     */
    public const DEFAULT_PID = 4242;

    /**
     * Symfony's Process constructor calls setInput(), which throws if isRunning() reports true.
     *
     * @var bool
     */
    protected bool $isConstructed = false;

    protected bool $fakeIsRunning = true;

    protected ?int $fakePid = self::DEFAULT_PID;

    protected ?int $fakeExitCode = null;

    protected string $fakeOutput = '';

    protected string $fakeErrorOutput = '';

    protected string $fakeCommandLine = 'fake-queue-process';

    /**
     * @var int
     */
    protected int $startCallCount = 0;

    /**
     * @var int
     */
    protected int $incrementalOutputCallCount = 0;

    /**
     * Number of isRunning() calls after which the process reports itself as finished.
     *
     * @var int|null
     */
    protected ?int $finishAfterIsRunningCalls = null;

    /**
     * @var int
     */
    protected int $isRunningCallCount = 0;

    public function __construct(
        bool $isRunning = true,
        ?int $pid = self::DEFAULT_PID,
        ?int $exitCode = null,
        string $commandLine = 'fake-queue-process'
    ) {
        // Assigned before parent::__construct() on purpose: Symfony's Process constructor calls
        // isRunning() internally, which would otherwise read these typed properties uninitialised.
        $this->fakeIsRunning = $isRunning;
        $this->fakePid = $pid;
        $this->fakeExitCode = $exitCode;
        $this->fakeCommandLine = $commandLine;

        parent::__construct(['true']);

        $this->isConstructed = true;
    }

    /**
     * Makes the process report "running" for the given number of isRunning() calls, then finish with the
     * supplied exit code.
     *
     * @return $this
     */
    public function finishAfter(int $isRunningCalls, int $exitCode = 0)
    {
        $this->finishAfterIsRunningCalls = $isRunningCalls;
        $this->fakeExitCode = $exitCode;

        return $this;
    }

    /**
     * @return $this
     */
    public function setFakeOutput(string $output, string $errorOutput = '')
    {
        $this->fakeOutput = $output;
        $this->fakeErrorOutput = $errorOutput;

        return $this;
    }

    public function getStartCallCount(): int
    {
        return $this->startCallCount;
    }

    public function getIncrementalOutputCallCount(): int
    {
        return $this->incrementalOutputCallCount;
    }

    /**
     * @param callable|null $callback
     * @param array<string, mixed> $env
     *
     * @return void
     */
    public function start(?callable $callback = null, array $env = []): void
    {
        $this->startCallCount++;

        if ($callback !== null && $this->fakeOutput !== '') {
            $callback(Process::OUT, $this->fakeOutput);
        }
    }

    /**
     * No OS process was ever spawned, so there is nothing to terminate.
     */
    public function __destruct()
    {
    }

    public function stop(float $timeout = 10.0, ?int $signal = null): ?int
    {
        $this->fakeIsRunning = false;

        return $this->fakeExitCode;
    }

    public function isRunning(): bool
    {
        if (!$this->isConstructed) {
            return false;
        }

        $this->isRunningCallCount++;

        if ($this->finishAfterIsRunningCalls !== null && $this->isRunningCallCount > $this->finishAfterIsRunningCalls) {
            return false;
        }

        return $this->fakeIsRunning;
    }

    public function getPid(): ?int
    {
        return $this->fakePid;
    }

    public function getExitCode(): ?int
    {
        return $this->fakeExitCode;
    }

    public function getExitCodeText(): ?string
    {
        if ($this->fakeExitCode === null) {
            return null;
        }

        return $this->fakeExitCode === 0 ? 'OK' : 'Exit code ' . $this->fakeExitCode;
    }

    public function getOutput(): string
    {
        return $this->fakeOutput;
    }

    public function getIncrementalOutput(): string
    {
        $this->incrementalOutputCallCount++;

        return $this->fakeOutput;
    }

    public function getErrorOutput(): string
    {
        return $this->fakeErrorOutput;
    }

    public function getCommandLine(): string
    {
        return $this->fakeCommandLine;
    }
}
