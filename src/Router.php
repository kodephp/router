<?php

declare(strict_types=1);

namespace Kode\Router;

use FastRoute\DataGenerator\GroupCountBased as DataGenerator;
use FastRoute\RouteCollector;
use FastRoute\RouteParser\Std as StdRouteParser;
use Kode\Router\Exception\InvalidRouteException;
use Kode\Router\Middleware\MiddlewareResolver;

/**
 * 路由器
 *
 * 编译期的门面：负责收集路由定义，最终产出不可变的 {@see CompiledRoutes}。
 *
 * 使用建议：Router 只在应用启动阶段使用（注册路由 → compile()），
 * 请求处理阶段应当持有编译产物或 {@see Dispatcher}。这样多进程模型下
 * 可以"主进程编译一次、worker 共享"，协程模型下也不存在任何共享可变状态。
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
class Router
{
    /** @var RouteCollection 路由集合（唯一数据源） */
    private RouteCollection $collection;

    /** @var array<string, RouteGroup> 路由组表：名称 => RouteGroup */
    private array $groups = [];

    /** @var RouteCollector|null 外部注入的 FastRoute 收集器（1.x 兼容） */
    private ?RouteCollector $injectedCollector;

    /** @var RouteCollector|null 惰性构建的 FastRoute 收集器（1.x 兼容） */
    private ?RouteCollector $legacyCollector = null;

    /** @var string 当前路由组前缀栈拼接结果 */
    private string $groupPrefix = '';

    /** @var list<mixed> 当前路由组中间件栈 */
    private array $groupMiddleware = [];

    /**
     * @param RouteCollector|null $collector FastRoute 路由收集器（可选，1.x 兼容）
     */
    public function __construct(?RouteCollector $collector = null)
    {
        $this->collection = new RouteCollection();
        $this->injectedCollector = $collector;
    }

    /**
     * 注册 GET 路由
     *
     * @param string $path 路由路径
     * @param mixed $handler 路由处理器
     * @param string|null $name 路由名称（可选）
     * @return Route 新建的路由
     */
    public function get(string $path, mixed $handler, ?string $name = null): Route
    {
        return $this->addRoute(['GET', 'HEAD'], $path, $handler, $name);
    }

    /**
     * 注册 POST 路由
     *
     * @param string $path 路由路径
     * @param mixed $handler 路由处理器
     * @param string|null $name 路由名称（可选）
     * @return Route 新建的路由
     */
    public function post(string $path, mixed $handler, ?string $name = null): Route
    {
        return $this->addRoute(['POST'], $path, $handler, $name);
    }

    /**
     * 注册 PUT 路由
     *
     * @param string $path 路由路径
     * @param mixed $handler 路由处理器
     * @param string|null $name 路由名称（可选）
     * @return Route 新建的路由
     */
    public function put(string $path, mixed $handler, ?string $name = null): Route
    {
        return $this->addRoute(['PUT'], $path, $handler, $name);
    }

    /**
     * 注册 DELETE 路由
     *
     * @param string $path 路由路径
     * @param mixed $handler 路由处理器
     * @param string|null $name 路由名称（可选）
     * @return Route 新建的路由
     */
    public function delete(string $path, mixed $handler, ?string $name = null): Route
    {
        return $this->addRoute(['DELETE'], $path, $handler, $name);
    }

    /**
     * 注册 PATCH 路由
     *
     * @param string $path 路由路径
     * @param mixed $handler 路由处理器
     * @param string|null $name 路由名称（可选）
     * @return Route 新建的路由
     */
    public function patch(string $path, mixed $handler, ?string $name = null): Route
    {
        return $this->addRoute(['PATCH'], $path, $handler, $name);
    }

    /**
     * 注册 HEAD 路由
     *
     * @param string $path 路由路径
     * @param mixed $handler 路由处理器
     * @param string|null $name 路由名称（可选）
     * @return Route 新建的路由
     */
    public function head(string $path, mixed $handler, ?string $name = null): Route
    {
        return $this->addRoute(['HEAD'], $path, $handler, $name);
    }

    /**
     * 注册 OPTIONS 路由
     *
     * @param string $path 路由路径
     * @param mixed $handler 路由处理器
     * @param string|null $name 路由名称（可选）
     * @return Route 新建的路由
     */
    public function options(string $path, mixed $handler, ?string $name = null): Route
    {
        return $this->addRoute(['OPTIONS'], $path, $handler, $name);
    }

    /**
     * 注册匹配全部常用 HTTP 方法的路由
     *
     * @param string $path 路由路径
     * @param mixed $handler 路由处理器
     * @param string|null $name 路由名称（可选）
     * @return Route 新建的路由
     */
    public function any(string $path, mixed $handler, ?string $name = null): Route
    {
        return $this->addRoute(
            ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
            $path,
            $handler,
            $name
        );
    }

    /**
     * 注册匹配指定 HTTP 方法集合的路由
     *
     * @param array<int, string> $methods HTTP 方法列表
     * @param string $path 路由路径
     * @param mixed $handler 路由处理器
     * @param string|null $name 路由名称（可选）
     * @return Route 新建的路由
     */
    public function map(array $methods, string $path, mixed $handler, ?string $name = null): Route
    {
        return $this->addRoute($methods, $path, $handler, $name);
    }

    /**
     * 注册路由
     *
     * @param array<int, string> $methods HTTP 方法列表
     * @param string $path 路由路径
     * @param mixed $handler 路由处理器
     * @param string|null $name 路由名称（可选）
     * @return Route 新建的路由
     *
     * @throws InvalidRouteException 定义非法或名称重复时抛出
     */
    public function addRoute(array $methods, string $path, mixed $handler, ?string $name = null): Route
    {
        $route = new Route($methods, $path, $handler, $name);

        if ($this->groupPrefix !== '') {
            $route->prefix($this->groupPrefix);
        }

        if ($this->groupMiddleware !== []) {
            $route->middleware(...$this->groupMiddleware);
        }

        $this->collection->add($route);
        $this->invalidate();

        // 1.x 兼容：外部注入的收集器保持同步
        $this->injectedCollector?->addRoute(
            $route->getMethods(),
            $route->getCompiledPath(),
            $handler
        );

        return $route;
    }

    /**
     * 定义路由组
     *
     * 两种用法：
     * 1. 1.x 风格：回调里拿到 RouteGroup，手工 addRoute()
     * 2. 2.x 风格：回调里拿到 Router 本身，直接 $r->get() —— 前缀与中间件自动继承
     *
     * 两种风格由回调的实际使用方式决定，回调会同时收到 (RouteGroup, Router)。
     *
     * @param string $name 分组名称
     * @param callable $callback 分组回调，签名 fn(RouteGroup $group, Router $router)
     * @param string $prefix 分组前缀（可选）
     * @param array<int, mixed> $middleware 分组中间件（可选）
     * @return RouteGroup 路由组实例
     */
    public function group(
        string $name,
        callable $callback,
        string $prefix = '',
        array $middleware = []
    ): RouteGroup {
        $group = new RouteGroup($name, $prefix);

        foreach ($middleware as $item) {
            $group->middleware($item);
        }

        $this->groups[$name] = $group;

        // 记录进入分组前的状态，支持嵌套
        $previousPrefix = $this->groupPrefix;
        $previousMiddleware = $this->groupMiddleware;

        $this->groupPrefix = self::joinPrefix($previousPrefix, $prefix);
        $this->groupMiddleware = array_merge($previousMiddleware, $middleware);

        try {
            $callback($group, $this);
        } finally {
            $this->groupPrefix = $previousPrefix;
            $this->groupMiddleware = $previousMiddleware;
        }

        // 回调里可能在 RouteGroup 上直接注册了路由（1.x 风格），
        // 也可能在回调执行后才调用 prefix()，所以这里统一展开一次
        foreach ($group->flatten() as $route) {
            $route->prefix(self::joinPrefix($previousPrefix, ''));

            if ($previousMiddleware !== []) {
                $route->middleware(...$previousMiddleware);
            }

            $this->collection->add($route);
        }

        $this->invalidate();

        return $group;
    }

    /**
     * 挂载另一个路由集合（子模块 / 子应用）
     *
     * @param RouteCollection|Router $routes 待挂载的路由
     * @param string $prefix 统一前缀
     * @param array<int, mixed> $middleware 统一中间件
     * @return self 当前实例，支持链式调用
     */
    public function mount(RouteCollection|Router $routes, string $prefix = '', array $middleware = []): self
    {
        $collection = $routes instanceof Router ? $routes->getCollection() : $routes;

        $this->collection->merge($collection, $prefix, $middleware);
        $this->invalidate();

        return $this;
    }

    /**
     * 获取路由集合
     *
     * @return RouteCollection 路由集合
     */
    public function getCollection(): RouteCollection
    {
        return $this->collection;
    }

    /**
     * 获取已定义的路由列表
     *
     * @return list<Route> 路由列表
     */
    public function getRoutes(): array
    {
        return array_values($this->collection->all());
    }

    /**
     * 获取所有路由组
     *
     * @return array<string, RouteGroup> 名称 => 路由组
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * 获取指定路由组
     *
     * @param string $name 分组名称
     * @return RouteGroup|null 路由组，不存在返回 null
     */
    public function getGroup(string $name): ?RouteGroup
    {
        return $this->groups[$name] ?? null;
    }

    /**
     * 通过名称获取路由路径
     *
     * @param string $name 路由名称
     * @return string|null 路由路径，不存在返回 null
     */
    public function resolveRoute(string $name): ?string
    {
        $id = $this->collection->namesWithAliases()[$name] ?? null;

        return $id === null ? null : $this->collection->get($id)?->getPath();
    }

    /**
     * 获取全部命名路由
     *
     * @return array<string, string> 名称 => 路径
     */
    public function resolveAll(): array
    {
        $result = [];

        foreach ($this->collection->namesWithAliases() as $name => $id) {
            $route = $this->collection->get($id);

            if ($route !== null) {
                $result[$name] = $route->getCompiledPath();
            }
        }

        return $result;
    }

    /**
     * 注册路由别名
     *
     * 把一个名字指向已存在的命名路由，别名与原名共享同一段路由，
     * 可用于 URL 生成与命名解析。例如兼容旧链接、语义化多入口。
     *
     * @param string $alias 别名
     * @param string $targetName 目标路由名称
     * @return self 当前实例，支持链式调用
     *
     * @throws InvalidRouteException 目标路由不存在或别名冲突时抛出
     */
    public function alias(string $alias, string $targetName): self
    {
        $this->collection->addAlias($alias, $targetName);
        $this->invalidate();

        return $this;
    }

    /**
     * 编译路由表
     *
     * @return CompiledRoutes 不可变的编译产物
     */
    public function compile(): CompiledRoutes
    {
        return $this->collection->compile();
    }

    /**
     * 创建分发器
     *
     * @param HandlerResolver|null $resolver 处理器解析器（可选）
     * @return Dispatcher 分发器
     */
    public function createDispatcher(?HandlerResolver $resolver = null): Dispatcher
    {
        return new Dispatcher($this->compile(), [], $resolver, '', new MiddlewareResolver());
    }

    /**
     * 创建 URL 生成器
     *
     * @param string $basePath 统一前缀
     * @return UrlGenerator URL 生成器
     */
    public function createUrlGenerator(string $basePath = ''): UrlGenerator
    {
        return UrlGenerator::fromCompiled($this->compile(), $basePath);
    }

    /**
     * 反向生成 URL
     *
     * @param string $name 路由名称
     * @param array<string, mixed> $params 路径参数
     * @param array<string, mixed> $query 查询参数
     * @return string 生成的 URL
     */
    public function url(string $name, array $params = [], array $query = []): string
    {
        return $this->createUrlGenerator()->generate($name, $params, $query);
    }

    /**
     * 获取路由表指纹
     *
     * @return string sha256 指纹
     */
    public function fingerprint(): string
    {
        return $this->collection->fingerprint();
    }

    /**
     * 获取 FastRoute 路由收集器（1.x 兼容）
     *
     * 注意：这里返回的收集器把真实处理器直接塞进了调度数据，
     * 因而无法序列化缓存。新代码请改用 {@see self::compile()}。
     *
     * @return RouteCollector FastRoute 收集器
     */
    public function getRouteCollector(): RouteCollector
    {
        if ($this->injectedCollector !== null) {
            return $this->injectedCollector;
        }

        if ($this->legacyCollector !== null) {
            return $this->legacyCollector;
        }

        $collector = new RouteCollector(new StdRouteParser(), new DataGenerator());

        foreach ($this->collection->all() as $route) {
            $collector->addRoute(
                $route->getMethods(),
                $route->getCompiledPath(),
                $route->getHandler()
            );
        }

        return $this->legacyCollector = $collector;
    }

    /**
     * 拼接两级前缀
     *
     * @param string $outer 外层前缀
     * @param string $inner 内层前缀
     * @return string 拼接结果
     */
    private static function joinPrefix(string $outer, string $inner): string
    {
        $outer = rtrim(trim($outer), '/');
        $inner = rtrim(trim($inner), '/');

        if ($inner !== '' && $inner[0] !== '/') {
            $inner = '/' . $inner;
        }

        return $outer . $inner;
    }

    /**
     * 使编译缓存失效
     *
     * @return void
     */
    private function invalidate(): void
    {
        $this->legacyCollector = null;
    }
}
