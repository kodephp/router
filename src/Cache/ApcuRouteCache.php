<?php

declare(strict_types=1);

namespace Kode\Router\Cache;

use Kode\Router\CompiledRoutes;
use Kode\Router\Exception\RouteCacheException;

/**
 * APCu 路由缓存
 *
 * 把路由表放进 APCu 共享内存。相比文件缓存：
 * - 优点：无磁盘 IO，同一台机器上所有 PHP-FPM worker 共享同一份内存副本
 * - 缺点：进程池重启即失效，且只在单机内共享
 *
 * 适合 PHP-FPM 部署。Swoole / Workerman 这类"主进程 fork worker"的模型，
 * 更推荐在主进程编译一次再 fork，或者用 {@see FileRouteCache} 配合 OPcache。
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class ApcuRouteCache implements RouteCacheInterface
{
    /** @var array<string, CompiledRoutes> 进程内一级缓存 */
    private array $memo = [];

    /**
     * @param string $prefix 键前缀，多应用共用一台机器时用于隔离
     * @param int $ttl 过期秒数，0 表示永不过期
     */
    public function __construct(
        private readonly string $prefix = 'kode.router.',
        private readonly int $ttl = 0
    ) {
    }

    /**
     * APCu 是否可用
     *
     * @return bool 可用返回 true
     */
    public static function isSupported(): bool
    {
        return extension_loaded('apcu')
            && function_exists('apcu_enabled')
            && apcu_enabled();
    }

    /**
     * 读取缓存的路由表
     *
     * @param string $key 缓存键
     * @return CompiledRoutes|null 编译产物，未命中返回 null
     */
    public function get(string $key): ?CompiledRoutes
    {
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        if (!self::isSupported()) {
            return null;
        }

        $success = false;
        /** @var mixed $data */
        $data = apcu_fetch($this->prefix . $key, $success);

        if ($success !== true || !is_array($data)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $data */
            return $this->memo[$key] = CompiledRoutes::fromArray($data);
        } catch (RouteCacheException) {
            return null;
        }
    }

    /**
     * 写入路由表
     *
     * @param string $key 缓存键
     * @param CompiledRoutes $routes 编译产物
     * @return bool 写入成功返回 true
     */
    public function set(string $key, CompiledRoutes $routes): bool
    {
        if (!$routes->isCacheable() || !self::isSupported()) {
            return false;
        }

        $this->memo[$key] = $routes;

        return apcu_store($this->prefix . $key, $routes->toArray(), $this->ttl) === true;
    }

    /**
     * 命中则返回缓存，未命中则编译并写入
     *
     * @param string $key 缓存键
     * @param callable(): CompiledRoutes $compiler 编译回调
     * @return CompiledRoutes 编译产物
     */
    public function remember(string $key, callable $compiler): CompiledRoutes
    {
        $cached = $this->get($key);

        if ($cached !== null) {
            return $cached;
        }

        $routes = $compiler();

        if ($routes->isCacheable()) {
            $this->set($key, $routes);
        } else {
            $this->memo[$key] = $routes;
        }

        return $routes;
    }

    /**
     * 判断缓存是否存在
     *
     * @param string $key 缓存键
     * @return bool 存在返回 true
     */
    public function has(string $key): bool
    {
        if (isset($this->memo[$key])) {
            return true;
        }

        return self::isSupported() && apcu_exists($this->prefix . $key) === true;
    }

    /**
     * 删除指定缓存
     *
     * @param string $key 缓存键
     * @return bool 删除成功返回 true
     */
    public function forget(string $key): bool
    {
        unset($this->memo[$key]);

        if (!self::isSupported()) {
            return true;
        }

        apcu_delete($this->prefix . $key);

        return true;
    }

    /**
     * 清空全部路由缓存（仅清除本前缀下的键）
     *
     * @return bool 清空成功返回 true
     */
    public function clear(): bool
    {
        $this->memo = [];

        if (!self::isSupported()) {
            return true;
        }

        if (!class_exists('\APCUIterator')) {
            return apcu_clear_cache();
        }

        $iterator = new \APCUIterator('/^' . preg_quote($this->prefix, '/') . '/');
        apcu_delete($iterator);

        return true;
    }
}
