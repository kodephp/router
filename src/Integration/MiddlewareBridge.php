<?php

declare(strict_types=1);

namespace Kode\Router\Integration;

use Kode\Router\Dispatcher;
use Kode\Router\Exception\RouterException;
use Kode\Router\MatchResult;

/**
 * kode/middleware 桥接器
 *
 * 把本包的 {@see MatchResult} 转换成 `Kode\Middleware\Routing\RouteResult`，
 * 从而让 kode/router 直接插进 kode/middleware 的三段式管道：
 *
 * ```php
 * $pipe->router(MiddlewareBridge::matcher($dispatcher));
 * ```
 *
 * 采用软依赖：未安装 kode/middleware 时本类的静态检查方法照常可用，
 * 只有真正调用转换方法才会抛异常。因此 composer 里 kode/middleware
 * 只出现在 suggest 中，不构成硬依赖，也不会造成两个包互相依赖。
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class MiddlewareBridge
{
    /** @var string kode/middleware 的路由结果类名 */
    private const ROUTE_RESULT = '\Kode\Middleware\Routing\RouteResult';

    /**
     * 私有构造，纯静态工具类
     */
    private function __construct()
    {
    }

    /**
     * kode/middleware 是否可用
     *
     * @return bool 可用返回 true
     */
    public static function isAvailable(): bool
    {
        return class_exists(self::ROUTE_RESULT);
    }

    /**
     * 把匹配结果转换成 kode/middleware 的 RouteResult
     *
     * @param MatchResult $result 本包的匹配结果
     * @return object Kode\Middleware\Routing\RouteResult 实例
     *
     * @throws RouterException 未安装 kode/middleware 时抛出
     */
    public static function toRouteResult(MatchResult $result): object
    {
        self::assertAvailable();

        /** @var class-string $class */
        $class = self::ROUTE_RESULT;

        if ($result->isNotFound()) {
            /** @var callable $notFound */
            $notFound = [$class, 'notFound'];

            /** @var object $created */
            $created = $notFound();

            return $created;
        }

        if ($result->isMethodNotAllowed()) {
            /** @var callable $methodNotAllowed */
            $methodNotAllowed = [$class, 'methodNotAllowed'];

            /** @var object $created */
            $created = $methodNotAllowed($result->getAllowedMethods());

            return $created;
        }

        /** @var callable $matched */
        $matched = [$class, 'matched'];

        /** @var object $created */
        $created = $matched(
            $result->getHandler(),
            $result->getParams(),
            $result->getMiddleware(),
            $result->getName()
        );

        return $created;
    }

    /**
     * 生成可直接传给 `Pipe::router()` 的匹配器闭包
     *
     * 闭包签名为 fn(ServerRequestInterface $request): RouteResult。
     * 返回的闭包只读持有分发器，因此可以安全地跨协程复用。
     *
     * @param Dispatcher $dispatcher 分发器
     * @return \Closure 匹配器闭包
     *
     * @throws RouterException 未安装 kode/middleware 时抛出
     */
    public static function matcher(Dispatcher $dispatcher): \Closure
    {
        self::assertAvailable();

        return static function (object $request) use ($dispatcher): object {
            /** @var \Psr\Http\Message\ServerRequestInterface $request */
            $method = $request->getMethod();
            $path = $request->getUri()->getPath();

            return self::toRouteResult($dispatcher->match($method, $path));
        };
    }

    /**
     * 断言 kode/middleware 已安装
     *
     * @return void
     *
     * @throws RouterException 未安装时抛出
     */
    private static function assertAvailable(): void
    {
        if (!self::isAvailable()) {
            throw new RouterException(
                '桥接 kode/middleware 需要先安装该包：composer require kode/middleware'
            );
        }
    }
}
