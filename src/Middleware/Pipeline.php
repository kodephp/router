<?php

declare(strict_types=1);

namespace Kode\Router\Middleware;

use Kode\Router\MiddlewareInterface;

/**
 * 中间件管道
 *
 * 把一组中间件和"核心处理器"编排成洋葱模型：请求参数从最外层中间件
 * 进入，逐层向内传递，最终抵达核心处理器，返回值再原路返回。
 *
 * 设计要点：
 * - 输入是 `array $args`（通常是路由路径参数），所有中间件共享同一份参数；
 *   任意一层都可以修改参数后传给下一层。
 * - 管道本身完全无状态、不可变：构建一次后可以被任意协程、线程、进程
 *   并发调用而无需加锁，天然适配 kode 生态的多进程 / 多线程 / 协程场景。
 * - 管道契约与 kode/middleware 一致（`handle(array $args, callable $next)`），
 *   因此同一批中间件既能走本管道，也能直接交给 kode/middleware 的 Pipe 执行。
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class Pipeline
{
    /**
     * 执行管道
     *
     * 中间件按传入顺序"从外到内"包裹核心：$middlewares[0] 是最外层，
     * 最后一个中间件紧贴核心处理器。
     *
     * @param array<string, mixed> $args 进入管道的初始参数（通常是路由参数）
     * @param callable $core 核心处理器，签名 fn(array $args): mixed
     * @param MiddlewareInterface|callable ...$middlewares 中间件列表（从外到内）
     * @return mixed 核心处理器（或最内层中间件）的返回值
     */
    public static function run(array $args, callable $core, MiddlewareInterface|callable ...$middlewares): mixed
    {
        $resolver = new MiddlewareResolver();
        $next = $core;

        for ($i = count($middlewares) - 1; $i >= 0; $i--) {
            $stage = $resolver->resolve($middlewares[$i]);

            $next = static fn (array $currentArgs): mixed => $stage($currentArgs, $next);
        }

        return $next($args);
    }

    /**
     * 便捷方法：仅运行中间件，不包裹任何核心处理器
     *
     * 适用于只想走一遍中间件副作用（如鉴权、日志）的场景。
     * 核心是一个透传闭包，返回进入时的参数。
     *
     * @param array<string, mixed> $args 初始参数
     * @param MiddlewareInterface|callable ...$middlewares 中间件列表
     * @return array<string, mixed> 经各层中间件处理后的参数
     */
    public static function through(array $args, MiddlewareInterface|callable ...$middlewares): array
    {
        /** @var array<string, mixed> $result */
        $result = self::run(
            $args,
            static fn (array $currentArgs): array => $currentArgs,
            ...$middlewares
        );

        return $result;
    }
}
