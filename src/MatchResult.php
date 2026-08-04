<?php

declare(strict_types=1);

namespace Kode\Router;

/**
 * 路由匹配结果
 *
 * 不可变值对象，是"匹配"与"执行"之间唯一的通信载体。
 * 因为完全只读，同一个实例可以安全地在协程、线程与进程之间传递而不需要加锁。
 *
 * 三种状态：
 * - 命中（found）：携带处理器、路径参数、路由级中间件与路由名
 * - 未找到（notFound）：没有任何路由匹配该路径
 * - 方法不允许（methodNotAllowed）：路径匹配但 HTTP 方法不符，携带 Allow 列表
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class MatchResult
{
    /** @var int 状态：匹配成功 */
    public const FOUND = 0;

    /** @var int 状态：未找到路由 */
    public const NOT_FOUND = 1;

    /** @var int 状态：请求方法不被允许 */
    public const METHOD_NOT_ALLOWED = 2;

    /**
     * @param int $status 匹配状态，取自本类的 FOUND / NOT_FOUND / METHOD_NOT_ALLOWED
     * @param mixed $handler 路由处理器（闭包、'Class@method'、[Class, 'method'] 等）
     * @param array<string, mixed> $params 路径参数（已合并 defaults）
     * @param list<mixed> $middleware 路由级中间件声明
     * @param string|null $name 路由名称
     * @param list<string> $allowedMethods 方法不允许时的可用方法列表
     * @param string|null $path 命中的路由定义路径（非请求路径）
     * @param int|null $routeId 命中的路由 ID，未命中为 null
     */
    private function __construct(
        private readonly int $status,
        private readonly mixed $handler = null,
        private readonly array $params = [],
        private readonly array $middleware = [],
        private readonly ?string $name = null,
        private readonly array $allowedMethods = [],
        private readonly ?string $path = null,
        private readonly ?int $routeId = null
    ) {
    }

    /**
     * 构造一个"匹配成功"的结果
     *
     * @param mixed $handler 路由处理器
     * @param array<string, mixed> $params 路径参数
     * @param array<int, mixed> $middleware 路由级中间件声明
     * @param string|null $name 路由名称
     * @param string|null $path 路由定义路径
     * @param int|null $routeId 路由 ID
     * @return self 匹配结果
     */
    public static function found(
        mixed $handler,
        array $params = [],
        array $middleware = [],
        ?string $name = null,
        ?string $path = null,
        ?int $routeId = null
    ): self {
        return new self(
            self::FOUND,
            $handler,
            $params,
            array_values($middleware),
            $name,
            [],
            $path,
            $routeId
        );
    }

    /**
     * 构造一个"未找到路由"的结果
     *
     * @return self 匹配结果
     */
    public static function notFound(): self
    {
        return new self(self::NOT_FOUND);
    }

    /**
     * 构造一个"方法不被允许"的结果
     *
     * @param array<int, string> $allowedMethods 该路径支持的 HTTP 方法列表
     * @return self 匹配结果
     */
    public static function methodNotAllowed(array $allowedMethods): self
    {
        return new self(
            self::METHOD_NOT_ALLOWED,
            allowedMethods: array_values(array_unique(array_map(
                static fn (string $m): string => strtoupper($m),
                $allowedMethods
            )))
        );
    }

    /**
     * 是否匹配成功
     *
     * @return bool 命中返回 true
     */
    public function isFound(): bool
    {
        return $this->status === self::FOUND;
    }

    /**
     * 是否未找到路由
     *
     * @return bool 未找到返回 true
     */
    public function isNotFound(): bool
    {
        return $this->status === self::NOT_FOUND;
    }

    /**
     * 是否方法不被允许
     *
     * @return bool 方法不允许返回 true
     */
    public function isMethodNotAllowed(): bool
    {
        return $this->status === self::METHOD_NOT_ALLOWED;
    }

    /**
     * 获取匹配状态
     *
     * @return int 状态常量
     */
    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * 获取路由处理器
     *
     * @return mixed 处理器，未命中时为 null
     */
    public function getHandler(): mixed
    {
        return $this->handler;
    }

    /**
     * 获取全部路径参数
     *
     * @return array<string, mixed> 参数键值对
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * 获取单个路径参数
     *
     * @param string $key 参数名
     * @param mixed $default 参数不存在时的默认值
     * @return mixed 参数值
     */
    public function getParam(string $key, mixed $default = null): mixed
    {
        return $this->params[$key] ?? $default;
    }

    /**
     * 获取路由级中间件声明
     *
     * @return list<mixed> 中间件列表
     */
    public function getMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * 获取路由名称
     *
     * @return string|null 路由名，匿名路由为 null
     */
    public function getName(): ?string
    {
        return $this->name;
    }

    /**
     * 获取允许的 HTTP 方法列表
     *
     * @return list<string> 允许的方法，仅在 405 状态下有值
     */
    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }

    /**
     * 获取命中的路由定义路径
     *
     * @return string|null 路由定义路径，如 "/users/{id}"
     */
    public function getPath(): ?string
    {
        return $this->path;
    }

    /**
     * 获取命中的路由 ID
     *
     * @return int|null 路由 ID
     */
    public function getRouteId(): ?int
    {
        return $this->routeId;
    }

    /**
     * 派生一个替换了路径参数的新结果
     *
     * @param array<string, mixed> $params 新的参数集合
     * @return self 新实例，原实例不受影响
     */
    public function withParams(array $params): self
    {
        return new self(
            $this->status,
            $this->handler,
            $params,
            $this->middleware,
            $this->name,
            $this->allowedMethods,
            $this->path,
            $this->routeId
        );
    }

    /**
     * 派生一个替换了中间件列表的新结果
     *
     * @param array<int, mixed> $middleware 新的中间件列表
     * @return self 新实例，原实例不受影响
     */
    public function withMiddleware(array $middleware): self
    {
        return new self(
            $this->status,
            $this->handler,
            $this->params,
            array_values($middleware),
            $this->name,
            $this->allowedMethods,
            $this->path,
            $this->routeId
        );
    }

    /**
     * 派生一个替换了处理器的新结果
     *
     * @param mixed $handler 新的处理器
     * @return self 新实例，原实例不受影响
     */
    public function withHandler(mixed $handler): self
    {
        return new self(
            $this->status,
            $handler,
            $this->params,
            $this->middleware,
            $this->name,
            $this->allowedMethods,
            $this->path,
            $this->routeId
        );
    }

    /**
     * 转为数组，便于日志输出与跨进程传递
     *
     * 处理器为闭包时以 "Closure" 占位，避免不可序列化的值泄漏到日志中。
     *
     * @return array<string, mixed> 数组表示
     */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'handler' => $this->handler instanceof \Closure ? 'Closure' : $this->handler,
            'params' => $this->params,
            'middleware' => $this->middleware,
            'name' => $this->name,
            'allowed_methods' => $this->allowedMethods,
            'path' => $this->path,
            'route_id' => $this->routeId,
        ];
    }
}
