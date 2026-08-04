<?php

declare(strict_types=1);

namespace Kode\Router;

/**
 * 路由组
 *
 * 把多个路由按前缀和中间件归拢，支持任意层级嵌套。
 *
 * 两个方法容易混淆，这里说明清楚：
 * - {@see self::getRoutes()} 返回组内路由的**原始对象**，路径不含前缀。
 *   它反映"你写下的定义"，用于内省与测试。
 * - {@see self::flatten()} 返回**克隆体**，已经逐层拼好前缀、逐层继承中间件。
 *   它反映"最终要注册的样子"，由 {@see Router::group()} 使用。
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
class RouteGroup
{
    /** @var string 分组前缀，例如 "/api/v1" */
    private string $prefix;

    /** @var string 分组名称 */
    private string $name;

    /** @var list<Route> 组内直属路由 */
    private array $routes = [];

    /** @var list<RouteGroup> 嵌套子组 */
    private array $groups = [];

    /** @var list<mixed> 分组中间件，按定义顺序依次执行 */
    private array $middlewares = [];

    /**
     * @param string $name 分组名称
     * @param string $prefix 分组前缀（可选）
     */
    public function __construct(string $name, string $prefix = '')
    {
        $this->name = $name;
        $this->prefix = $prefix;
    }

    /**
     * 设置分组前缀
     *
     * @param string $prefix 分组前缀
     * @return self 当前实例，支持链式调用
     */
    public function prefix(string $prefix): self
    {
        $this->prefix = $prefix;
        return $this;
    }

    /**
     * 创建并加入子路由组
     *
     * @param string $name 子组名称
     * @param callable $callback 子组回调，签名 fn(RouteGroup $group)
     * @param string $prefix 子组前缀（可选）
     * @return RouteGroup 子路由组
     */
    public function group(string $name, callable $callback, string $prefix = ''): RouteGroup
    {
        $group = new RouteGroup($name, $prefix);
        $this->addGroup($group);
        $callback($group);

        return $group;
    }

    /**
     * 获取分组名称
     *
     * @return string 分组名称
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 获取分组前缀
     *
     * @return string 分组前缀
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * 获取分组中间件列表
     *
     * @return list<mixed> 中间件列表
     */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    /**
     * 附加中间件到分组（可一次传多个）
     *
     * @param mixed ...$middleware 中间件类名、闭包或实例
     * @return self 当前实例，支持链式调用
     */
    public function middleware(mixed ...$middleware): self
    {
        foreach ($middleware as $item) {
            if (is_array($item)) {
                foreach ($item as $sub) {
                    $this->middlewares[] = $sub;
                }
                continue;
            }
            $this->middlewares[] = $item;
        }

        return $this;
    }

    /**
     * 加入路由
     *
     * @param Route $route 路由对象
     * @return self 当前实例，支持链式调用
     */
    public function addRoute(Route $route): self
    {
        $this->routes[] = $route;
        $route->setGroupName($this->name);

        return $this;
    }

    /**
     * 在组内直接注册路由
     *
     * @param array<int, string> $methods HTTP 方法列表
     * @param string $path 路由路径
     * @param mixed $handler 路由处理器
     * @param string|null $name 路由名称（可选）
     * @return Route 新建的路由
     */
    public function route(array $methods, string $path, mixed $handler, ?string $name = null): Route
    {
        $route = new Route($methods, $path, $handler, $name);
        $this->addRoute($route);

        return $route;
    }

    /**
     * 加入子路由组
     *
     * @param RouteGroup $group 子路由组
     * @return self 当前实例，支持链式调用
     */
    public function addGroup(RouteGroup $group): self
    {
        $this->groups[] = $group;

        return $this;
    }

    /**
     * 获取全部路由的原始对象（含子组，递归展开，路径不含前缀）
     *
     * @return list<Route> 路由列表
     */
    public function getRoutes(): array
    {
        $all = $this->routes;

        foreach ($this->groups as $group) {
            foreach ($group->getRoutes() as $route) {
                $all[] = $route;
            }
        }

        return $all;
    }

    /**
     * 获取组内直属路由（不含子组）
     *
     * @return list<Route> 路由列表
     */
    public function getOwnRoutes(): array
    {
        return $this->routes;
    }

    /**
     * 获取子组列表
     *
     * @return list<RouteGroup> 子组列表
     */
    public function getGroups(): array
    {
        return $this->groups;
    }

    /**
     * 展开为最终要注册的路由克隆体
     *
     * 逐层拼接前缀、逐层继承中间件，并生成层级化的组名
     * （父组.子组，例如 `api.v1`）。返回的是克隆对象，
     * 因此不会污染组内原始定义。
     *
     * @param string $parentPrefix 父级累积前缀
     * @param array<int, mixed> $parentMiddleware 父级累积中间件
     * @param string $parentName 父级累积组名（用于生成层级名）
     * @return list<Route> 展开后的路由列表
     */
    public function flatten(string $parentPrefix = '', array $parentMiddleware = [], string $parentName = ''): array
    {
        $prefix = self::joinPrefix($parentPrefix, $this->prefix);
        $middleware = array_merge($parentMiddleware, $this->middlewares);
        $name = $parentName === '' ? $this->name : $parentName . '.' . $this->name;

        $result = [];

        foreach ($this->routes as $route) {
            $clone = clone $route;

            if ($prefix !== '') {
                $clone->prefix($prefix);
            }

            if ($middleware !== []) {
                $clone->middleware(...$middleware);
            }

            $clone->setGroupName($name);
            $result[] = $clone;
        }

        foreach ($this->groups as $group) {
            foreach ($group->flatten($prefix, $middleware, $name) as $route) {
                $result[] = $route;
            }
        }

        return $result;
    }

    /**
     * 路由数量（含子组）
     *
     * @return int 路由条数
     */
    public function count(): int
    {
        return count($this->getRoutes());
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
}
