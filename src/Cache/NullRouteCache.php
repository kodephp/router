<?php

declare(strict_types=1);

namespace Kode\Router\Cache;

use Kode\Router\CompiledRoutes;

/**
 * 空实现路由缓存
 *
 * 默认实现：什么也不缓存，每次都重新编译。
 *
 * 这是最安全的默认值——开发环境改了路由立刻生效，
 * 用闭包写路由也不会报错。生产环境请显式换成
 * {@see FileRouteCache} 或 {@see ApcuRouteCache}。
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class NullRouteCache implements RouteCacheInterface
{
    /**
     * 读取缓存的路由表，恒为未命中
     *
     * @param string $key 缓存键
     * @return CompiledRoutes|null 恒返回 null
     */
    public function get(string $key): ?CompiledRoutes
    {
        return null;
    }

    /**
     * 写入路由表，恒不写入
     *
     * @param string $key 缓存键
     * @param CompiledRoutes $routes 编译产物
     * @return bool 恒返回 false
     */
    public function set(string $key, CompiledRoutes $routes): bool
    {
        return false;
    }

    /**
     * 直接执行编译回调
     *
     * @param string $key 缓存键
     * @param callable(): CompiledRoutes $compiler 编译回调
     * @return CompiledRoutes 编译产物
     */
    public function remember(string $key, callable $compiler): CompiledRoutes
    {
        return $compiler();
    }

    /**
     * 判断缓存是否存在，恒为不存在
     *
     * @param string $key 缓存键
     * @return bool 恒返回 false
     */
    public function has(string $key): bool
    {
        return false;
    }

    /**
     * 删除指定缓存，空操作
     *
     * @param string $key 缓存键
     * @return bool 恒返回 true
     */
    public function forget(string $key): bool
    {
        return true;
    }

    /**
     * 清空缓存，空操作
     *
     * @return bool 恒返回 true
     */
    public function clear(): bool
    {
        return true;
    }
}
