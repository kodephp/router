<?php

declare(strict_types=1);

namespace Kode\Router\Middleware;

use Kode\Router\MiddlewareInterface;

/**
 * 中间件解析器
 *
 * 把各种写法的中间件统一解析成 kode/router 的管道契约：
 * `callable(array $args, callable $next): mixed`。
 *
 * 支持的形式：
 * - {@see MiddlewareInterface} 实例（最推荐，跨进程可序列化）
 * - 实现了 {@see MiddlewareInterface} 的类名字符串（运行时实例化）
 * - 任意 callable：`fn(array $args, callable $next) => ...`、类静态方法、`[Class, 'method']`
 * - 实现了 `__invoke(array $args, callable $next)` 的可调用对象
 *
 * 解析结果是一个闭包，因此解析本身无副作用、可缓存复用。
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class MiddlewareResolver
{
    /**
     * 解析中间件为管道契约闭包
     *
     * @param mixed $middleware 中间件声明
     * @return callable(array<int, mixed>|array<string, mixed> $args, callable $next): mixed 管道契约闭包
     *
     * @throws \InvalidArgumentException 无法解析时抛出
     */
    public function resolve(mixed $middleware): callable
    {
        if ($middleware instanceof MiddlewareInterface) {
            return static fn (array $args, callable $next): mixed => $middleware->handle($args, $next);
        }

        if (is_callable($middleware)) {
            /** @var callable $middleware */
            return $middleware;
        }

        if (is_string($middleware) && class_exists($middleware)) {
            /** @var object $instance */
            $instance = new $middleware();

            if ($instance instanceof MiddlewareInterface) {
                return static fn (array $args, callable $next): mixed => $instance->handle($args, $next);
            }

            if (is_callable($instance)) {
                return $instance;
            }

            throw new \InvalidArgumentException(
                "中间件类 {$middleware} 未实现 Kode\\Router\\MiddlewareInterface，也没有 __invoke()"
            );
        }

        throw new \InvalidArgumentException('无法解析的中间件: ' . get_debug_type($middleware));
    }
}
