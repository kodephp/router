<?php

declare(strict_types=1);

namespace Kode\Router;

use FastRoute\DataGenerator\GroupCountBased as DataGenerator;
use FastRoute\Dispatcher as FastRouteDispatcher;
use FastRoute\Dispatcher\GroupCountBased as FastRouteGroupCountBased;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std as StdRouteParser;
use Kode\Router\Exception\RouteCacheException;

/**
 * 编译后的路由表
 *
 * 整个组件最关键的设计：交给 FastRoute 的 handler 不是真正的处理器，
 * 而是一个 int 路由 ID。这带来两个决定性好处：
 *
 * 1. FastRoute 的调度数据变成纯标量数组，可以 var_export 成 PHP 文件被
 *    OPcache 缓存，多进程模型下每个 worker 直接 include 即可，
 *    不必重复执行成百上千次路由注册。
 * 2. 调度数据可以安全地跨进程、跨线程传递（序列化 / 共享内存），
 *    不会因为闭包而炸掉。
 *
 * 本对象一旦创建即完全只读，可以被任意数量的协程、线程共享而无需加锁。
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class CompiledRoutes
{
    /** @var FastRouteDispatcher|null 惰性构建的 FastRoute 调度器 */
    private ?FastRouteDispatcher $dispatcher = null;

    /**
     * @param array<int, mixed> $dispatchData FastRoute 调度数据，handler 位置存放的是 int 路由 ID
     * @param array<int, array<string, mixed>> $routes 路由元数据：路由 ID => 元数据
     * @param array<string, int> $named 命名路由表：名称 => 路由 ID
     * @param string $fingerprint 路由表指纹
     * @param int $compiledAt 编译时间戳
     */
    public function __construct(
        private readonly array $dispatchData,
        private readonly array $routes,
        private readonly array $named,
        private readonly string $fingerprint,
        private readonly int $compiledAt
    ) {
    }

    /**
     * 从路由集合编译
     *
     * @param RouteCollection $collection 路由集合
     * @return self 编译产物
     */
    public static function fromCollection(RouteCollection $collection): self
    {
        $collector = new RouteCollector(new StdRouteParser(), new DataGenerator());

        $routes = [];

        foreach ($collection->all() as $id => $route) {
            // 关键：注册给 FastRoute 的 handler 是 int 路由 ID，而非真实处理器
            $collector->addRoute($route->getMethods(), $route->getCompiledPath(), $id);
            $routes[$id] = $route->toArray($id);
        }

        return new self(
            $collector->getData(),
            $routes,
            $collection->names(),
            $collection->fingerprint(),
            time()
        );
    }

    /**
     * 获取 FastRoute 调度数据
     *
     * @return array<int, mixed> 调度数据
     */
    public function getDispatchData(): array
    {
        return $this->dispatchData;
    }

    /**
     * 获取全部路由元数据
     *
     * @return array<int, array<string, mixed>> 路由 ID => 元数据
     */
    public function getRoutes(): array
    {
        return $this->routes;
    }

    /**
     * 按 ID 获取路由元数据
     *
     * @param int $id 路由 ID
     * @return array<string, mixed>|null 元数据，不存在返回 null
     */
    public function getRoute(int $id): ?array
    {
        return $this->routes[$id] ?? null;
    }

    /**
     * 按名称获取路由元数据
     *
     * @param string $name 路由名称
     * @return array<string, mixed>|null 元数据，不存在返回 null
     */
    public function getRouteByName(string $name): ?array
    {
        $id = $this->named[$name] ?? null;

        return $id === null ? null : ($this->routes[$id] ?? null);
    }

    /**
     * 获取命名路由表
     *
     * @return array<string, int> 名称 => 路由 ID
     */
    public function getNamed(): array
    {
        return $this->named;
    }

    /**
     * 获取路由表指纹
     *
     * @return string sha256 指纹
     */
    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    /**
     * 获取编译时间戳
     *
     * @return int Unix 时间戳
     */
    public function getCompiledAt(): int
    {
        return $this->compiledAt;
    }

    /**
     * 路由数量
     *
     * @return int 路由条数
     */
    public function count(): int
    {
        return count($this->routes);
    }

    /**
     * 获取 FastRoute 调度器（惰性构建并复用）
     *
     * @return FastRouteDispatcher 调度器
     */
    public function getDispatcher(): FastRouteDispatcher
    {
        /** @var array{0: array<string, array<string, mixed>>, 1: array<string, array<int, mixed>>} $data */
        $data = $this->dispatchData;

        return $this->dispatcher ??= new FastRouteGroupCountBased($data);
    }

    /**
     * 是否可以写入静态缓存
     *
     * 只要有任意一条路由的处理器或中间件是闭包 / 对象实例，整张表就无法
     * var_export 成 PHP 文件，只能退回到运行时注册。
     *
     * @return bool 可缓存返回 true
     */
    public function isCacheable(): bool
    {
        foreach ($this->routes as $meta) {
            if (!self::valueIsCacheable($meta['handler'] ?? null)) {
                return false;
            }

            /** @var array<int, mixed> $middleware */
            $middleware = $meta['middleware'] ?? [];
            foreach ($middleware as $item) {
                if (!self::valueIsCacheable($item)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * 列出所有无法缓存的路由，便于给出可执行的排查提示
     *
     * @return list<string> 形如 "GET /users/{id}" 的路由描述列表
     */
    public function getUncacheableRoutes(): array
    {
        $result = [];

        foreach ($this->routes as $meta) {
            $cacheable = self::valueIsCacheable($meta['handler'] ?? null);

            if ($cacheable) {
                /** @var array<int, mixed> $middleware */
                $middleware = $meta['middleware'] ?? [];
                foreach ($middleware as $item) {
                    if (!self::valueIsCacheable($item)) {
                        $cacheable = false;
                        break;
                    }
                }
            }

            if (!$cacheable) {
                /** @var list<string> $methods */
                $methods = $meta['methods'] ?? [];
                $result[] = implode('|', $methods) . ' ' . (string) ($meta['path'] ?? '');
            }
        }

        return $result;
    }

    /**
     * 导出为纯数组，用于写入缓存或跨进程传递
     *
     * @return array<string, mixed> 数组表示
     *
     * @throws RouteCacheException 路由表含闭包等不可序列化处理器时抛出
     */
    public function toArray(): array
    {
        if (!$this->isCacheable()) {
            $bad = implode('、', array_slice($this->getUncacheableRoutes(), 0, 5));

            throw new RouteCacheException(
                '路由表含有闭包或对象处理器，无法序列化缓存。'
                . '请把这些路由改成 "Class@method" 形式的字符串处理器：' . $bad
            );
        }

        return [
            'version' => 1,
            'fingerprint' => $this->fingerprint,
            'compiled_at' => $this->compiledAt,
            'dispatch_data' => $this->dispatchData,
            'routes' => $this->routes,
            'named' => $this->named,
        ];
    }

    /**
     * 从数组还原
     *
     * @param array<string, mixed> $data 由 {@see self::toArray()} 产出的数组
     * @return self 编译产物
     *
     * @throws RouteCacheException 数据结构不合法时抛出
     */
    public static function fromArray(array $data): self
    {
        foreach (['dispatch_data', 'routes', 'named', 'fingerprint'] as $key) {
            if (!array_key_exists($key, $data)) {
                throw new RouteCacheException("路由缓存数据缺少字段: {$key}");
            }
        }

        if (!is_array($data['dispatch_data']) || !is_array($data['routes']) || !is_array($data['named'])) {
            throw new RouteCacheException('路由缓存数据结构损坏');
        }

        /** @var array<int, mixed> $dispatchData */
        $dispatchData = $data['dispatch_data'];
        /** @var array<int, array<string, mixed>> $routes */
        $routes = $data['routes'];
        /** @var array<string, int> $named */
        $named = $data['named'];

        return new self(
            $dispatchData,
            $routes,
            $named,
            (string) $data['fingerprint'],
            (int) ($data['compiled_at'] ?? time())
        );
    }

    /**
     * 判断单个值是否可静态缓存
     *
     * @param mixed $value 处理器或中间件
     * @return bool 可缓存返回 true
     */
    private static function valueIsCacheable(mixed $value): bool
    {
        if ($value instanceof \Closure || is_object($value)) {
            return false;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (!self::valueIsCacheable($item)) {
                    return false;
                }
            }
        }

        return true;
    }
}
