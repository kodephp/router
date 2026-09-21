<?php

declare(strict_types=1);

namespace Kode\Router;

use FastRoute\Dispatcher as FastRouteDispatcher;
use FastRoute\Dispatcher\GroupCountBased;
use FastRoute\RouteCollector;
use Kode\Router\Exception\MethodNotAllowedException;
use Kode\Router\Exception\RouteNotFoundException;
use Kode\Router\Middleware\MiddlewareResolver;
use Kode\Router\Middleware\Pipeline;

/**
 * 路由分发器
 *
 * 构造完成后完全只读，没有任何请求级可变状态。这意味着：
 * - 一个实例可以被成千上万个协程同时调用，无需加锁
 * - 可以在多进程 worker 启动时构造一次，之后一直复用
 * - Fiber 中途挂起再恢复，也不会读到别的请求的数据
 *
 * 支持两种数据源：
 * - {@see CompiledRoutes}（推荐）：完整元数据，可缓存，支持中间件与命名路由
 * - {@see RouteCollector}（1.x 兼容）：处理器直接嵌在调度数据里，无法缓存
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
class Dispatcher
{
    /** @var FastRouteDispatcher FastRoute 分发器 */
    private FastRouteDispatcher $dispatcher;

    /** @var CompiledRoutes|null 编译产物，1.x 兼容模式下为 null */
    private ?CompiledRoutes $compiled = null;

    /** @var RouteCollector|null 1.x 兼容模式下的收集器 */
    private ?RouteCollector $routeCollector = null;

    /** @var HandlerResolver 处理器解析器 */
    private HandlerResolver $resolver;

    /** @var MiddlewareResolver 中间件解析器 */
    private MiddlewareResolver $middlewareResolver;

    /** @var UrlGenerator URL 生成器 */
    private UrlGenerator $urlGenerator;

    /**
     * @param CompiledRoutes|RouteCollector $source 路由数据源
     * @param array<string, string> $namedRoutes 命名路由表（仅 1.x 兼容模式需要）
     * @param HandlerResolver|null $resolver 处理器解析器（可选）
     * @param string $basePath URL 生成时的统一前缀
     * @param MiddlewareResolver|null $middlewareResolver 中间件解析器（可选）
     */
    public function __construct(
        CompiledRoutes|RouteCollector $source,
        array $namedRoutes = [],
        ?HandlerResolver $resolver = null,
        string $basePath = '',
        ?MiddlewareResolver $middlewareResolver = null
    ) {
        $this->resolver = $resolver ?? new HandlerResolver();
        $this->middlewareResolver = $middlewareResolver ?? new MiddlewareResolver();

        if ($source instanceof CompiledRoutes) {
            $this->compiled = $source;
            $this->dispatcher = $source->getDispatcher();
            $this->urlGenerator = $namedRoutes === []
                ? UrlGenerator::fromCompiled($source, $basePath)
                : new UrlGenerator($namedRoutes, $basePath);

            return;
        }

        // 1.x 兼容模式
        $this->routeCollector = $source;
        /** @var array{0: array<string, array<string, mixed>>, 1: array<string, array<int, mixed>>} $data */
        $data = $source->getData();
        $this->dispatcher = new GroupCountBased($data);
        $this->urlGenerator = new UrlGenerator($namedRoutes, $basePath);
    }

    /**
     * 匹配路由，返回不可变的匹配结果
     *
     * 这是 2.x 推荐的入口：只做匹配、不做执行，因此可以安全地
     * 放在中间件管道最前面，把结果透传给后续环节。
     *
     * @param string $method HTTP 方法
     * @param string $path 请求路径（不含查询字符串）
     * @return MatchResult 匹配结果
     */
    public function match(string $method, string $path): MatchResult
    {
        $method = strtoupper($method);
        $path = self::normalizeRequestPath($path);

        $result = $this->dispatcher->dispatch($method, $path);

        switch ($result[0]) {
            case FastRouteDispatcher::NOT_FOUND:
                return MatchResult::notFound();

            case FastRouteDispatcher::METHOD_NOT_ALLOWED:
                /** @var array<int, string> $allowed */
                $allowed = $result[1] ?? [];

                return MatchResult::methodNotAllowed($allowed);

            case FastRouteDispatcher::FOUND:
                /** @var array<string, mixed> $params */
                $params = self::decodeParams($result[2] ?? []);

                return $this->buildFoundResult($result[1], $params);

            default:
                return MatchResult::notFound();
        }
    }

    /**
     * 匹配并执行路由
     *
     * @param string $method HTTP 方法
     * @param string $path 请求路径
     * @param mixed ...$extra 追加给处理器的额外参数
     * @return mixed 处理器返回值
     *
     * @throws RouteNotFoundException 未匹配到路由时抛出
     * @throws MethodNotAllowedException HTTP 方法不被允许时抛出
     */
    public function handle(string $method, string $path, mixed ...$extra): mixed
    {
        $result = $this->match($method, $path);

        if ($result->isNotFound()) {
            throw new RouteNotFoundException($method, $path);
        }

        if ($result->isMethodNotAllowed()) {
            throw new MethodNotAllowedException($method, $result->getAllowedMethods(), $path);
        }

        return $this->resolver->call($result->getHandler(), $result->getParams(), array_values($extra));
    }

    /**
     * 匹配并通过中间件管道执行路由
     *
     * 与 {@see self::handle()} 的区别：本方法会按"路由组中间件 + 路由中间件"
     * 的顺序，把处理器包裹进中间件洋葱模型再执行。这正是结合 kode/middleware
     * 的关键入口——路由上声明的中间件会在此被逐个调用。
     *
     * 管道无状态、只读，可在多进程 worker、多线程与 Fiber 协程间安全共享。
     *
     * @param string $method HTTP 方法
     * @param string $path 请求路径
     * @param array<string, mixed> $args 追加/覆盖给处理器的参数（合并在路径参数之后）
     * @param mixed ...$extra 追加到处理器末尾的额外参数
     * @return mixed 处理器返回值（经中间件链后）
     *
     * @throws RouteNotFoundException 未匹配到路由时抛出
     * @throws MethodNotAllowedException HTTP 方法不被允许时抛出
     */
    public function run(string $method, string $path, array $args = [], mixed ...$extra): mixed
    {
        $result = $this->match($method, $path);

        if ($result->isNotFound()) {
            throw new RouteNotFoundException($method, $path);
        }

        if ($result->isMethodNotAllowed()) {
            throw new MethodNotAllowedException($method, $result->getAllowedMethods(), $path);
        }

        $handler = $result->getHandler();
        $middlewares = $result->getMiddleware();

        /** @var array<int, callable> $resolved */
        $resolved = array_map(
            fn (mixed $middleware): callable => $this->middlewareResolver->resolve($middleware),
            $middlewares
        );

        $core = function (array $finalArgs) use ($handler, $extra): mixed {
            return $this->resolver->call($handler, $finalArgs, $extra);
        };

        $initialArgs = array_merge($result->getParams(), $args);

        return Pipeline::run($initialArgs, $core, ...$resolved);
    }

    /**
     * 匹配并执行路由（1.x 兼容别名）
     *
     * @param string $method HTTP 方法
     * @param string $path 请求路径
     * @return mixed 处理器返回值
     *
     * @throws RouteNotFoundException 未匹配到路由时抛出
     * @throws MethodNotAllowedException HTTP 方法不被允许时抛出
     */
    public function execute(string $method, string $path): mixed
    {
        return $this->handle($method, $path);
    }

    /**
     * 原始分发，返回 FastRoute 三元组（1.x 兼容）
     *
     * @param string $method HTTP 方法
     * @param string $path 请求路径
     * @return array<int, mixed> [status, handler|allowedMethods, params]
     */
    public function dispatch(string $method, string $path): array
    {
        $result = $this->dispatcher->dispatch(strtoupper($method), self::normalizeRequestPath($path));

        if (($result[0] ?? null) === FastRouteDispatcher::FOUND && isset($result[2]) && is_array($result[2])) {
            $result[2] = self::decodeParams($result[2]);
        }

        return $result;
    }

    /**
     * 反向生成 URL
     *
     * @param string $name 路由名称
     * @param array<string, mixed> $params 路径参数
     * @param array<string, mixed> $query 查询参数
     * @return string 生成的 URL
     *
     * @throws Exception\UrlGenerationException 路由不存在或参数不满足时抛出
     */
    public function url(string $name, array $params = [], array $query = []): string
    {
        return $this->urlGenerator->generate($name, $params, $query);
    }

    /**
     * 获取 URL 生成器
     *
     * @return UrlGenerator URL 生成器
     */
    public function getUrlGenerator(): UrlGenerator
    {
        return $this->urlGenerator;
    }

    /**
     * 获取处理器解析器
     *
     * @return HandlerResolver 处理器解析器
     */
    public function getResolver(): HandlerResolver
    {
        return $this->resolver;
    }

    /**
     * 获取编译产物
     *
     * @return CompiledRoutes|null 编译产物，1.x 兼容模式下为 null
     */
    public function getCompiled(): ?CompiledRoutes
    {
        return $this->compiled;
    }

    /**
     * 获取路由表指纹
     *
     * @return string sha256 指纹，1.x 兼容模式下为空串
     */
    public function getFingerprint(): string
    {
        return $this->compiled?->getFingerprint() ?? '';
    }

    /**
     * 获取 FastRoute 路由收集器（1.x 兼容）
     *
     * @return RouteCollector|null 收集器，编译模式下为 null
     */
    public function getRouteCollector(): ?RouteCollector
    {
        return $this->routeCollector;
    }

    /**
     * 获取底层 FastRoute 分发器
     *
     * @return FastRouteDispatcher FastRoute 分发器
     */
    public function getDispatcher(): FastRouteDispatcher
    {
        return $this->dispatcher;
    }

    /**
     * 构造命中结果
     *
     * @param mixed $handler FastRoute 返回的 handler：编译模式下是 int 路由 ID
     * @param array<string, mixed> $params 路径参数
     * @return MatchResult 匹配结果
     */
    private function buildFoundResult(mixed $handler, array $params): MatchResult
    {
        if ($this->compiled === null || !is_int($handler)) {
            return MatchResult::found($handler, $params);
        }

        $meta = $this->compiled->getRoute($handler);

        if ($meta === null) {
            return MatchResult::found($handler, $params);
        }

        /** @var array<string, mixed> $defaults */
        $defaults = $meta['defaults'] ?? [];
        /** @var list<mixed> $middleware */
        $middleware = $meta['middleware'] ?? [];

        return MatchResult::found(
            $meta['handler'] ?? null,
            $defaults === [] ? $params : array_merge($defaults, $params),
            $middleware,
            isset($meta['name']) ? (string) $meta['name'] : null,
            isset($meta['path']) ? (string) $meta['path'] : null,
            $handler
        );
    }

    /**
     * 规范化请求路径：剥离查询字符串、补全前导斜杠、归一末尾斜杠
     *
     * 注意：不做 urldecode——匹配必须发生在编码态路径上，
     * 否则 %2F 会被还原成 / 而凭空多出一个段，导致 /a/b%2Fc 误配 /a/b/c。
     * 路径参数在命中后由 {@see self::decodeParams()} 统一解码。
     *
     * @param string $path 原始请求路径
     * @return string 规范化后的路径（仍为 URL 编码态）
     */
    private static function normalizeRequestPath(string $path): string
    {
        $pos = strpos($path, '?');

        if ($pos !== false) {
            $path = substr($path, 0, $pos);
        }

        if ($path === '') {
            return '/';
        }

        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        // 末尾斜杠归一（根路径除外）
        if (strlen($path) > 1 && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
            $path = $path === '' ? '/' : $path;
        }

        return $path;
    }

    /**
     * 对命中的路径参数逐个 rawurldecode（与匹配前不解码的口径配对）。
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private static function decodeParams(array $params): array
    {
        foreach ($params as $name => $value) {
            if (is_string($value)) {
                $params[$name] = rawurldecode($value);
            }
        }

        return $params;
    }
}
