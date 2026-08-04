<?php

namespace Kode\Router\Tests\Concurrency;

use Kode\Router\Concurrency\Runtime;
use PHPUnit\Framework\TestCase;

class RuntimeTest extends TestCase
{
    public function testDetectEqualsHostWhenNotInCoroutine(): void
    {
        $this->assertSame(Runtime::host(), Runtime::detect());
    }

    public function testHostIsKnownConstant(): void
    {
        $known = [
            Runtime::SWOOLE, Runtime::SWOW, Runtime::FIBER, Runtime::THREAD,
            Runtime::PROCESS, Runtime::FPM, Runtime::CLI,
        ];
        $this->assertContains(Runtime::host(), $known);
    }

    public function testNotInCoroutine(): void
    {
        $this->assertFalse(Runtime::inCoroutine());
    }

    public function testSupportsFibers(): void
    {
        $this->assertSame(PHP_VERSION_ID >= 80100 && class_exists(\Fiber::class), Runtime::supportsFibers());
    }

    public function testSupportsThreadsMatchesExtension(): void
    {
        $this->assertSame(extension_loaded('parallel'), Runtime::supportsThreads());
    }

    public function testSupportsProcessesMatchesExtension(): void
    {
        $this->assertSame(extension_loaded('pcntl') && function_exists('pcntl_fork'), Runtime::supportsProcesses());
    }

    public function testExecutionIdIsStableAndPrefixed(): void
    {
        $a = Runtime::executionId();
        $b = Runtime::executionId();

        $this->assertSame($a, $b);
        $this->assertStringStartsWith('process:', $a);
    }

    public function testProcessIdMatches(): void
    {
        $this->assertSame(getmypid(), Runtime::processId());
    }

    public function testNodeIdIsString(): void
    {
        $this->assertIsString(Runtime::nodeId());
    }

    public function testResetIsCallable(): void
    {
        Runtime::reset();
        $this->assertTrue(true);
    }

    public function testIsLongRunningConsistentWithHost(): void
    {
        $host = Runtime::host();
        $longRunning = in_array($host, [Runtime::SWOOLE, Runtime::SWOW, Runtime::THREAD, Runtime::PROCESS], true);
        $this->assertSame($longRunning, Runtime::isLongRunning());
    }
}
