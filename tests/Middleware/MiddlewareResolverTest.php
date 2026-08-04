<?php

declare(strict_types=1);

namespace Kode\Router\Tests\Middleware;

use InvalidArgumentException;
use Kode\Router\Middleware\MiddlewareResolver;
use Kode\Router\Tests\Fixtures\TagMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * 中间件解析器测试：各类写法都能解析成管道契约闭包。
 */
final class MiddlewareResolverTest extends TestCase
{
    public function testResolveClosure(): void
    {
        $cb = static fn (array $args, callable $next): mixed => $next($args);
        $resolved = (new MiddlewareResolver())->resolve($cb);

        $this->assertSame(['ok' => 1], $resolved(['ok' => 1], static fn (array $a): array => $a));
    }

    public function testResolveMiddlewareInterfaceInstance(): void
    {
        $resolved = (new MiddlewareResolver())->resolve(new TagMiddleware('A'));
        $result = $resolved(['tags' => []], static fn (array $a): array => $a);

        $this->assertSame(['tags' => ['A']], $result);
    }

    public function testResolveClassString(): void
    {
        $resolved = (new MiddlewareResolver())->resolve(TagMiddleware::class);
        $result = $resolved(['tags' => []], static fn (array $a): array => $a);

        $this->assertSame(['tags' => ['default']], $result);
    }

    public function testResolveArrayCallable(): void
    {
        // 非静态方法必须用实例 + 方法名的数组可调用形式（[Class, 'method'] 仅适用于静态方法）
        $resolved = (new MiddlewareResolver())->resolve([new TagMiddleware(), 'handle']);
        $result = $resolved(['tags' => []], static fn (array $a): array => $a);

        $this->assertSame(['tags' => ['default']], $result);
    }

    public function testResolveInvalidThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new MiddlewareResolver())->resolve(123);
    }
}
