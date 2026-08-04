<?php

declare(strict_types=1);

namespace Kode\Router\Tests\Middleware;

use Kode\Router\Middleware\Pipeline;
use PHPUnit\Framework\TestCase;

/**
 * 中间件管道行为测试：洋葱模型的执行顺序、参数透传、短路。
 */
final class PipelineTest extends TestCase
{
    public function testRunPreservesOnionOrder(): void
    {
        $log = [];

        $m1 = function (array $args, callable $next) use (&$log): mixed {
            $log[] = 'm1-before';
            $result = $next($args);
            $log[] = 'm1-after';

            return $result;
        };

        $m2 = function (array $args, callable $next) use (&$log): mixed {
            $log[] = 'm2-before';
            $result = $next($args);
            $log[] = 'm2-after';

            return $result;
        };

        $core = function (array $args) use (&$log): array {
            $log[] = 'core';

            return $args;
        };

        $result = Pipeline::run(['x' => 1], $core, $m1, $m2);

        $this->assertSame(['m1-before', 'm2-before', 'core', 'm2-after', 'm1-after'], $log);
        $this->assertSame(['x' => 1], $result);
    }

    public function testMiddlewareCanMutateArgs(): void
    {
        $m = function (array $args, callable $next): mixed {
            $args['added'] = true;

            return $next($args);
        };

        $out = Pipeline::through(['a' => 1], $m);

        $this->assertSame(['a' => 1, 'added' => true], $out);
    }

    public function testShortCircuitSkipsCoreAndOuterAfter(): void
    {
        $log = [];

        $blocker = function (array $args, callable $next) use (&$log): string {
            $log[] = 'blocked';

            return 'blocked';
        };

        $outer = function (array $args, callable $next) use (&$log): mixed {
            $log[] = 'outer-before';
            $result = $next($args);
            $log[] = 'outer-after';

            return $result;
        };

        $coreCalled = false;
        $core = function (array $args) use (&$coreCalled): array {
            $coreCalled = true;

            return $args;
        };

        $result = Pipeline::run([], $core, $outer, $blocker);

        $this->assertSame('blocked', $result);
        $this->assertFalse($coreCalled);
        $this->assertSame(['outer-before', 'blocked', 'outer-after'], $log);
    }

    public function testEmptyPipelineRunsCoreOnly(): void
    {
        $core = static fn (array $args): array => $args;

        $this->assertSame(['k' => 'v'], Pipeline::run(['k' => 'v'], $core));
    }
}
