<?php

declare(strict_types=1);

namespace Kode\Router;

use Kode\Router\Cache\NullRouteCache;
use Kode\Router\Cache\RouteCacheInterface;
use Kode\Router\Concurrency\RouteContext;
use Kode\Router\Exception\MethodNotAllowedException;
use Kode\Router\Exception\RouteNotFoundException;

/**
 * 应用入口
 *
 * 面向"一个进程一个应用、一个请求一个响应"的简易场景，把路由注册、
 * 编译、分发、响应组装串成一条线。
 *
 * 常驻内存模型（Swoole / Workerman / RoadRunner）下的正确用法：
 * 在 worker 启动时调用一次 {@see self::boot()} 完成编译，
 * 之后每个请求只调用 {@see self::run()}，不要重复注册路由。
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
class Application
{
    /** @var Router 路由器实例 */
    private Router $router;

    /** @var Dispatcher|null 分发器（惰性构建） */
    private ?Dispatcher $dispatcher = null;

    /** @var list<callable> 全局中间件队列 */
    private array $middlewares = [];

    /** @var RouteCacheInterface 路由缓存 */
    private RouteCacheInterface $cache;

    /** @var HandlerResolver 处理器解析器 */
    private HandlerResolver $resolver;

    /** @var bool 是否把当前路由写入协程上下文 */
    private bool $trackContext = true;

    /**
     * @param Router|null $router 路由器（可选）
     * @param RouteCacheInterface|null $cache 路由缓存（可选）
     * @param HandlerResolver|null $resolver 处理器解析器（可选）
     */
    public function __construct(
        ?Router $router = null,
        ?RouteCacheInterface $cache = null,
        ?HandlerResolver $resolver = null
    ) {
        $this->router = $router ?? new Router();
        $this->cache = $cache ?? new NullRouteCache();
        $this->resolver = $resolver ?? new HandlerResolver();
    }

    /**
     * 获取路由器
     *
     * @return Router 路由器
     */
    public function getRouter(): Router
    {
        return $this->router;
    }

    /**
     * 预热：立即完成路由编译并构建分发器
     *
     * 多进程模型下应该在 worker 启动回调里调用，避免首个请求承担编译开销。
     *
     * @return self 当前实例，支持链式调用
     */
    public function boot(): self
    {
        $this->getDispatcher();

        return $this;
    }

    /**
     * 获取分发器（惰性构建，构建后只读复用）
     *
     * @return Dispatcher 分发器
     */
    public function getDispatcher(): Dispatcher
    {
        if ($this->dispatcher !== null) {
            return $this->dispatcher;
        }

        $router = $this->router;
        $compiled = $this->cache->remember(
            'kode.router.' . substr($router->fingerprint(), 0, 16),
            static fn (): CompiledRoutes => $router->compile()
        );

        return $this->dispatcher = new Dispatcher($compiled, [], $this->resolver);
    }

    /**
     * 设置路由缓存
     *
     * @param RouteCacheInterface $cache 缓存实现
     * @return self 当前实例，支持链式调用
     */
    public function setCache(RouteCacheInterface $cache): self
    {
        $this->cache = $cache;
        $this->dispatcher = null;

        return $this;
    }

    /**
     * 开关"当前路由写入协程上下文"
     *
     * 关闭后 {@see RouteContext::current()} 将拿不到值，可省下极少量开销。
     *
     * @param bool $enabled 是否开启
     * @return self 当前实例，支持链式调用
     */
    public function trackContext(bool $enabled): self
    {
        $this->trackContext = $enabled;

        return $this;
    }

    /**
     * 添加全局中间件
     *
     * 签名为 fn(Request $request, Response $response): void，
     * 在响应生成之后执行，主要用于统一加响应头、埋点等。
     *
     * @param callable $middleware 中间件回调
     * @return self 当前实例，支持链式调用
     */
    public function middleware(callable $middleware): self
    {
        $this->middlewares[] = $middleware;

        return $this;
    }

    /**
     * 运行应用，处理一个请求
     *
     * 全程不写任何实例级请求状态，因此同一个 Application 实例
     * 可以被多个协程并发调用。
     *
     * @param Request|null $request 请求对象，为 null 时从超全局变量创建
     * @return Response 响应对象
     */
    public function run(?Request $request = null): Response
    {
        $request ??= Request::fromGlobals();

        $dispatcher = $this->getDispatcher();
        $result = $dispatcher->match($request->getMethod(), $request->getPath());

        if ($this->trackContext) {
            RouteContext::set($result);
        }

        try {
            $response = $this->resolve($request, $result);
        } catch (RouteNotFoundException $e) {
            $response = Response::json(['error' => $e->getMessage()], 404);
        } catch (MethodNotAllowedException $e) {
            $response = Response::json(['error' => $e->getMessage()], 405)
                ->withHeader('Allow', $e->getAllowHeader());
        } finally {
            if ($this->trackContext) {
                RouteContext::clear();
            }
        }

        foreach ($this->middlewares as $middleware) {
            $middleware($request, $response);
        }

        return $response;
    }

    /**
     * 发送响应
     *
     * @param Response|null $response 响应对象，为 null 时先执行 run()
     * @return void
     */
    public function send(?Response $response = null): void
    {
        ($response ?? $this->run())->send();
    }

    /**
     * 魔法方法代理到路由器
     *
     * @param string $method 方法名
     * @param array<int, mixed> $args 参数
     * @return mixed 路由器方法返回值
     *
     * @throws \BadMethodCallException 方法不存在时抛出
     */
    public function __call(string $method, array $args): mixed
    {
        if (method_exists($this->router, $method)) {
            /** @var callable $callable */
            $callable = [$this->router, $method];

            return $callable(...$args);
        }

        throw new \BadMethodCallException("方法 {$method} 不存在");
    }

    /**
     * 根据匹配结果生成响应
     *
     * @param Request $request 请求对象
     * @param MatchResult $result 匹配结果
     * @return Response 响应对象
     *
     * @throws RouteNotFoundException 未匹配到路由时抛出
     * @throws MethodNotAllowedException HTTP 方法不被允许时抛出
     */
    private function resolve(Request $request, MatchResult $result): Response
    {
        if ($result->isNotFound()) {
            throw new RouteNotFoundException($request->getMethod(), $request->getPath());
        }

        if ($result->isMethodNotAllowed()) {
            throw new MethodNotAllowedException(
                $request->getMethod(),
                $result->getAllowedMethods(),
                $request->getPath()
            );
        }

        $value = $this->resolver->call($result->getHandler(), $result->getParams());

        return self::toResponse($value);
    }

    /**
     * 把处理器返回值规整成 Response
     *
     * @param mixed $value 处理器返回值
     * @return Response 响应对象
     */
    private static function toResponse(mixed $value): Response
    {
        if ($value instanceof Response) {
            return $value;
        }

        if (is_string($value)) {
            return Response::text($value);
        }

        if (is_array($value) || $value instanceof \JsonSerializable) {
            return Response::json($value);
        }

        if ($value === null) {
            return Response::text('', 204);
        }

        if (is_scalar($value)) {
            return Response::text((string) $value);
        }

        return Response::json($value);
    }
}
