<?php

declare(strict_types=1);

namespace Kode\Router\Cache;

use Kode\Router\CompiledRoutes;
use Kode\Router\Exception\RouteCacheException;

/**
 * 文件路由缓存
 *
 * 把编译后的路由表 var_export 成一个 `return [...];` 的纯 PHP 文件。
 * 这么做而不是用 serialize()，是为了让 **OPcache 直接把它编译进共享内存**：
 * 后续所有 worker 进程 include 时不需要解析、不需要反序列化，
 * 几乎是零成本地拿到整张路由表。
 *
 * 写入采用"临时文件 + rename"的原子替换，多进程并发写同一个键时，
 * 读者要么看到旧版本、要么看到新版本，不会读到写了一半的文件。
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class FileRouteCache implements RouteCacheInterface
{
    /** @var array<string, CompiledRoutes> 进程内一级缓存，避免同进程重复 include */
    private array $memo = [];

    /**
     * @param string $directory 缓存目录
     * @param bool $invalidateOpcache 写入后是否主动失效 OPcache（常驻进程建议开启）
     */
    public function __construct(
        private readonly string $directory,
        private readonly bool $invalidateOpcache = true
    ) {
    }

    /**
     * 读取缓存的路由表
     *
     * @param string $key 缓存键
     * @return CompiledRoutes|null 编译产物，未命中或文件损坏返回 null
     */
    public function get(string $key): ?CompiledRoutes
    {
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $file = $this->path($key);

        if (!is_file($file)) {
            return null;
        }

        /** @var mixed $data */
        $data = @include $file;

        if (!is_array($data)) {
            return null;
        }

        try {
            /** @var array<string, mixed> $data */
            return $this->memo[$key] = CompiledRoutes::fromArray($data);
        } catch (RouteCacheException) {
            // 缓存文件结构损坏（多半是版本升级导致），当作未命中处理
            return null;
        }
    }

    /**
     * 写入路由表
     *
     * @param string $key 缓存键
     * @param CompiledRoutes $routes 编译产物
     * @return bool 写入成功返回 true
     *
     * @throws RouteCacheException 缓存目录无法创建或不可写时抛出
     */
    public function set(string $key, CompiledRoutes $routes): bool
    {
        if (!$routes->isCacheable()) {
            return false;
        }

        $this->ensureDirectory();

        $file = $this->path($key);
        $code = "<?php\n\n"
            . "// kode/router 路由缓存，由 FileRouteCache 自动生成，请勿手工修改\n"
            . '// 生成时间: ' . date('Y-m-d H:i:s', $routes->getCompiledAt()) . "\n"
            . '// 路由指纹: ' . $routes->getFingerprint() . "\n\n"
            . 'return ' . var_export($routes->toArray(), true) . ";\n";

        $temp = $file . '.' . getmypid() . '.' . bin2hex(random_bytes(4)) . '.tmp';

        if (@file_put_contents($temp, $code, LOCK_EX) === false) {
            @unlink($temp);

            throw new RouteCacheException("路由缓存写入失败: {$temp}");
        }

        // 原子替换：rename 在同一文件系统内是原子操作
        if (!@rename($temp, $file)) {
            @unlink($temp);

            throw new RouteCacheException("路由缓存替换失败: {$file}");
        }

        @chmod($file, 0o644);

        if ($this->invalidateOpcache && function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }

        $this->memo[$key] = $routes;

        return true;
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

        // 含闭包的路由表写不进缓存，但不应该因此让应用起不来
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
        return isset($this->memo[$key]) || is_file($this->path($key));
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

        $file = $this->path($key);

        if (!is_file($file)) {
            return true;
        }

        if (function_exists('opcache_invalidate')) {
            @opcache_invalidate($file, true);
        }

        return @unlink($file);
    }

    /**
     * 清空全部路由缓存
     *
     * @return bool 清空成功返回 true
     */
    public function clear(): bool
    {
        $this->memo = [];

        if (!is_dir($this->directory)) {
            return true;
        }

        $files = glob($this->directory . DIRECTORY_SEPARATOR . '*.route-cache.php');

        if ($files === false) {
            return false;
        }

        $ok = true;

        foreach ($files as $file) {
            if (function_exists('opcache_invalidate')) {
                @opcache_invalidate($file, true);
            }

            $ok = @unlink($file) && $ok;
        }

        return $ok;
    }

    /**
     * 获取缓存目录
     *
     * @return string 缓存目录绝对路径
     */
    public function getDirectory(): string
    {
        return $this->directory;
    }

    /**
     * 获取缓存文件路径
     *
     * @param string $key 缓存键
     * @return string 文件绝对路径
     */
    public function path(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . self::sanitize($key) . '.route-cache.php';
    }

    /**
     * 确保缓存目录存在且可写
     *
     * @return void
     *
     * @throws RouteCacheException 目录无法创建或不可写时抛出
     */
    private function ensureDirectory(): void
    {
        if (is_dir($this->directory)) {
            if (!is_writable($this->directory)) {
                throw new RouteCacheException("路由缓存目录不可写: {$this->directory}");
            }

            return;
        }

        if (!@mkdir($this->directory, 0o755, true) && !is_dir($this->directory)) {
            throw new RouteCacheException("路由缓存目录创建失败: {$this->directory}");
        }
    }

    /**
     * 把缓存键规整成安全的文件名
     *
     * @param string $key 缓存键
     * @return string 安全的文件名片段
     */
    private static function sanitize(string $key): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]/', '_', $key) ?? 'default';

        return $safe === '' ? 'default' : substr($safe, 0, 128);
    }
}
