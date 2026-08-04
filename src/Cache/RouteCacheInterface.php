<?php

declare(strict_types=1);

namespace Kode\Router\Cache;

use Kode\Router\CompiledRoutes;

/**
 * 路由缓存契约
 *
 * 存在的意义只有一个：**让路由表只编译一次**。
 *
 * 多进程模型（PHP-FPM、Swoole worker、Workerman）下，每个进程都要拿到
 * 完整路由表。如果每个进程都重新执行一遍成百上千条 `$router->get(...)`，
 * 启动开销会被乘以进程数。缓存把编译结果落到磁盘或共享内存，
 * 后续进程直接读取，注册代码根本不必执行。
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
interface RouteCacheInterface
{
    /**
     * 读取缓存的路由表
     *
     * @param string $key 缓存键
     * @return CompiledRoutes|null 编译产物，未命中返回 null
     */
    public function get(string $key): ?CompiledRoutes;

    /**
     * 写入路由表
     *
     * 实现应当保证写入是原子的：并发的多个进程同时写同一个键，
     * 不能让任何一个进程读到写了一半的数据。
     *
     * @param string $key 缓存键
     * @param CompiledRoutes $routes 编译产物
     * @return bool 写入成功返回 true；路由表含闭包等不可序列化处理器时返回 false
     */
    public function set(string $key, CompiledRoutes $routes): bool;

    /**
     * 命中则返回缓存，未命中则执行编译并写入
     *
     * 这是推荐的调用入口，把"查 → 编译 → 写"三步合成一步。
     *
     * @param string $key 缓存键
     * @param callable(): CompiledRoutes $compiler 编译回调，仅在未命中时执行
     * @return CompiledRoutes 编译产物
     */
    public function remember(string $key, callable $compiler): CompiledRoutes;

    /**
     * 判断缓存是否存在
     *
     * @param string $key 缓存键
     * @return bool 存在返回 true
     */
    public function has(string $key): bool;

    /**
     * 删除指定缓存
     *
     * @param string $key 缓存键
     * @return bool 删除成功返回 true
     */
    public function forget(string $key): bool;

    /**
     * 清空全部路由缓存
     *
     * @return bool 清空成功返回 true
     */
    public function clear(): bool;
}
