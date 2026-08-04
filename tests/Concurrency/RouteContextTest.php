<?php

namespace Kode\Router\Tests\Concurrency;

use Kode\Router\Concurrency\RouteContext;
use Kode\Router\MatchResult;
use PHPUnit\Framework\TestCase;

class RouteContextTest extends TestCase
{
    protected function setUp(): void
    {
        RouteContext::flush();
    }

    protected function tearDown(): void
    {
        RouteContext::flush();
    }

    public function testSetAndCurrent(): void
    {
        $result = MatchResult::found('h', ['id' => '1'], [], 'r', '/p', 1);
        RouteContext::set($result);

        $this->assertTrue(RouteContext::has());
        $this->assertSame($result, RouteContext::current());
    }

    public function testClearRemovesContext(): void
    {
        RouteContext::set(MatchResult::found('h'));
        RouteContext::clear();

        $this->assertFalse(RouteContext::has());
        $this->assertNull(RouteContext::current());
    }

    public function testParamAndNameAccessors(): void
    {
        RouteContext::set(MatchResult::found('h', ['id' => '99'], [], 'order.show', '/order/{id}', 3));

        $this->assertSame('order.show', RouteContext::name());
        $this->assertSame('99', RouteContext::param('id'));
        $this->assertSame('fallback', RouteContext::param('missing', 'fallback'));
    }

    public function testRunRestoresPrevious(): void
    {
        $outer = MatchResult::found('outer', [], [], 'outer', '/outer', 1);
        $inner = MatchResult::found('inner', [], [], 'inner', '/inner', 2);

        RouteContext::set($outer);

        $seen = RouteContext::run($inner, static function () {
            return RouteContext::name();
        });

        $this->assertSame('inner', $seen);
        // 回调结束后应恢复为 outer
        $this->assertSame('outer', RouteContext::name());
    }

    public function testRunClearsWhenNoPrevious(): void
    {
        $inner = MatchResult::found('inner', [], [], 'inner', '/inner', 2);

        RouteContext::run($inner, static function (): void {
            // no-op
        });

        $this->assertFalse(RouteContext::has());
    }

    public function testCountReflectsStore(): void
    {
        $this->assertSame(0, RouteContext::count());
        RouteContext::set(MatchResult::found('a'));
        $this->assertSame(1, RouteContext::count());
        RouteContext::flush();
        $this->assertSame(0, RouteContext::count());
    }
}
