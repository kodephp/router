<?php

declare(strict_types=1);

namespace Kode\Router;

use Kode\Router\Exception\InvalidRouteException;

/**
 * 路由集合
 *
 * 编译期的容器：负责收集路由、校验命名唯一性、合并路由组，
 * 最终产出不可变的 {@see CompiledRoutes}。
 *
 * 集合本身只在启动阶段被写入。一旦调用 {@see self::compile()}，
 * 得到的编译产物就可以被所有 worker 进程、线程与协程只读共享。
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 *
 * @implements \IteratorAggregate<int, Route>
 */
final class RouteCollection implements \IteratorAggregate, \Countable
{
    /** @var array<int, Route> 路由表：自增 ID => Route */
    private array $routes = [];

    /** @var array<string, int> 命名路由表：名称 => 路由 ID */
    private array $named = [];

    /** @var int 下一个路由 ID */
    private int $nextId = 0;

    /** @var CompiledRoutes|null 编译结果缓存，集合变更后失效 */
    private ?CompiledRoutes $compiled = null;

    /**
     * 加入一个已构建好的路由
     *
     * @param Route $route 路由对象
     * @return Route 传入的路由，便于链式继续配置
     *
     * @throws InvalidRouteException 路由名称重复时抛出
     */
    public function add(Route $route): Route
    {
        $name = $route->getName();

        if ($name !== null && isset($this->named[$name])) {
            throw new InvalidRouteException("路由名称重复: {$name}");
        }

        $id = $this->nextId++;
        $this->routes[$id] = $route;

        if ($name !== null) {
            $this->named[$name] = $id;
        }

        $this->compiled = null;

        return $route;
    }

    /**
     * 直接以参数创建并加入路由
     *
     * @param array<int, string> $methods HTTP 方法列表
     * @param string $path 路由路径
     * @param mixed $handler 路由处理器
     * @param string|null $name 路由名称
     * @return Route 新建的路由
     *
     * @throws InvalidRouteException 定义非法时抛出
     */
    public function addRoute(array $methods, string $path, mixed $handler, ?string $name = null): Route
    {
        return $this->add(new Route($methods, $path, $handler, $name));
    }

    /**
     * 获取全部路由
     *
     * @return array<int, Route> 路由 ID => Route
     */
    public function all(): array
    {
        return $this->routes;
    }

    /**
     * 按 ID 获取路由
     *
     * @param int $id 路由 ID
     * @return Route|null 路由对象，不存在返回 null
     */
    public function get(int $id): ?Route
    {
        return $this->routes[$id] ?? null;
    }

    /**
     * 按名称获取路由
     *
     * @param string $name 路由名称
     * @return Route|null 路由对象，不存在返回 null
     */
    public function getByName(string $name): ?Route
    {
        $id = $this->named[$name] ?? null;

        return $id === null ? null : ($this->routes[$id] ?? null);
    }

    /**
     * 判断命名路由是否存在
     *
     * @param string $name 路由名称
     * @return bool 存在返回 true
     */
    public function hasName(string $name): bool
    {
        return isset($this->named[$name]);
    }

    /**
     * 获取命名路由表
     *
     * @return array<string, int> 名称 => 路由 ID
     */
    public function names(): array
    {
        return $this->named;
    }

    /**
     * 合并另一个路由集合
     *
     * 常用于把子模块、子应用的路由挂载到主应用上。
     *
     * @param self $other 待合并的集合
     * @param string $prefix 统一追加的路径前缀
     * @param array<int, mixed> $middleware 统一追加的中间件
     * @return self 当前实例，支持链式调用
     *
     * @throws InvalidRouteException 名称冲突时抛出
     */
    public function merge(self $other, string $prefix = '', array $middleware = []): self
    {
        foreach ($other->all() as $route) {
            $clone = clone $route;

            if ($prefix !== '') {
                $clone->prefix($prefix);
            }

            if ($middleware !== []) {
                $clone->middleware(...$middleware);
            }

            $this->add($clone);
        }

        return $this;
    }

    /**
     * 计算路由表指纹
     *
     * 指纹只由"结构性信息"决定（方法、编译后路径、名称、可序列化的中间件），
     * 与闭包对象地址无关，因此同一份路由定义在不同进程、不同节点上
     * 一定得到相同的指纹。分布式部署时可用它校验各节点路由表是否一致。
     *
     * @return string 64 位十六进制 sha256 指纹
     */
    public function fingerprint(): string
    {
        $parts = [];

        foreach ($this->routes as $id => $route) {
            $parts[] = implode('|', [
                $id,
                implode(',', $route->getMethods()),
                $route->getCompiledPath(),
                $route->getName() ?? '',
                self::stringifyMiddleware($route->getMiddlewares()),
                self::stringifyHandler($route->getHandler()),
            ]);
        }

        return hash('sha256', implode("\n", $parts));
    }

    /**
     * 编译路由表
     *
     * 结果会被缓存，集合未变更时重复调用不会重复编译。
     *
     * @return CompiledRoutes 不可变的编译产物
     */
    public function compile(): CompiledRoutes
    {
        return $this->compiled ??= CompiledRoutes::fromCollection($this);
    }

    /**
     * 清空集合
     *
     * @return self 当前实例，支持链式调用
     */
    public function clear(): self
    {
        $this->routes = [];
        $this->named = [];
        $this->nextId = 0;
        $this->compiled = null;

        return $this;
    }

    /**
     * 路由数量
     *
     * @return int 已注册的路由条数
     */
    public function count(): int
    {
        return count($this->routes);
    }

    /**
     * 迭代全部路由
     *
     * @return \Traversable<int, Route> 迭代器
     */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->routes);
    }

    /**
     * 将处理器转成用于指纹计算的稳定字符串
     *
     * @param mixed $handler 处理器
     * @return string 稳定标识
     */
    private static function stringifyHandler(mixed $handler): string
    {
        if (is_string($handler)) {
            return $handler;
        }

        if ($handler instanceof \Closure) {
            // 闭包无法稳定标识，用定义位置作为近似值
            $ref = new \ReflectionFunction($handler);

            return 'closure@' . ($ref->getFileName() ?: 'internal') . ':' . $ref->getStartLine();
        }

        if (is_array($handler) && count($handler) === 2) {
            $target = is_object($handler[0]) ? $handler[0]::class : (string) $handler[0];

            return $target . '::' . (string) $handler[1];
        }

        if (is_object($handler)) {
            return $handler::class;
        }

        return gettype($handler);
    }

    /**
     * 将中间件列表转成用于指纹计算的稳定字符串
     *
     * @param array<int, mixed> $middleware 中间件列表
     * @return string 稳定标识
     */
    private static function stringifyMiddleware(array $middleware): string
    {
        $parts = [];

        foreach ($middleware as $item) {
            $parts[] = self::stringifyHandler($item);
        }

        return implode(',', $parts);
    }
}
