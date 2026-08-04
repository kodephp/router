<?php

declare(strict_types=1);

namespace Kode\Router;

use Kode\Router\Exception\InvalidRouteException;

/**
 * 路由定义
 *
 * 定位为"编译期的路由构建器"：注册阶段用链式调用把路径、约束、默认值、
 * 中间件一点点拼起来；注册完成后由 {@see RouteCollection::compile()}
 * 冻结成完全只读、可跨进程共享的 {@see CompiledRoutes}。
 *
 * 因此 Route 本身是可变的（这也保持了 1.x 的调用习惯），
 * 但它只应该出现在启动阶段。请求处理阶段面对的是不可变的 CompiledRoutes
 * 与 {@see MatchResult}，天然协程安全。
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
class Route
{
    /**
     * 路径占位符解析正则
     *
     * 与 FastRoute\RouteParser\Std 保持一致，支持 `{id}`、`{id:\d+}`
     * 以及正则中带嵌套花括号的 `{id:\d{1,9}}`。
     *
     * @var string
     */
    private const VARIABLE_REGEX = <<<'REGEX'
\{
    \s* ([a-zA-Z_][a-zA-Z0-9_-]*) \s*
    (?:
        : \s* ([^{}]*(?:\{(?-1)\}[^{}]*)*)
    )?
\}
REGEX;

    /** @var list<string> HTTP 方法，例如 ["GET", "POST"] */
    private array $methods;

    /** @var string 路由路径，例如 "/users/{id}" */
    private string $path;

    /** @var mixed 路由处理器：闭包、'Class@method'、[Class, 'method'] 或函数名 */
    private mixed $handler;

    /** @var string|null 路由名称，用于反向生成 URL */
    private ?string $name;

    /** @var list<mixed> 路由级中间件，按定义顺序依次执行 */
    private array $middlewares = [];

    /** @var array<string, string> 参数正则约束：参数名 => 正则片段 */
    private array $wheres = [];

    /** @var array<string, mixed> 参数默认值：参数名 => 默认值 */
    private array $defaults = [];

    /** @var string|null 所属路由组名称 */
    private ?string $groupName = null;

    /**
     * @param array<int, string> $methods HTTP 方法列表
     * @param string $path 路由路径
     * @param mixed $handler 路由处理器
     * @param string|null $name 路由名称（可选）
     *
     * @throws InvalidRouteException 方法列表为空时抛出
     */
    public function __construct(
        array $methods,
        string $path,
        mixed $handler,
        ?string $name = null
    ) {
        if ($methods === []) {
            throw new InvalidRouteException("路由 {$path} 必须至少声明一个 HTTP 方法");
        }

        $this->methods = array_values(array_unique(array_map(
            static fn (string $m): string => strtoupper(trim($m)),
            $methods
        )));
        $this->path = self::normalizePath($path);
        $this->handler = $handler;
        $this->name = $name;
    }

    /**
     * 规范化路径：确保以 `/` 开头，并去掉末尾多余的 `/`
     *
     * @param string $path 原始路径
     * @return string 规范化后的路径
     */
    public static function normalizePath(string $path): string
    {
        $path = trim($path);

        if ($path === '' || $path === '/') {
            return '/';
        }

        if ($path[0] !== '/' && $path[0] !== '[') {
            $path = '/' . $path;
        }

        // 末尾的 `/` 去掉，但保留可选段的 `]`
        if (str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
            $path = $path === '' ? '/' : $path;
        }

        return $path;
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
     * 设置路由名称
     *
     * @param string $name 路由名称
     * @return self 当前实例，支持链式调用
     */
    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * 获取路由路径
     *
     * @return string 路由定义路径
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * 为路径添加前缀（路由组展开时使用）
     *
     * @param string $prefix 前缀，例如 "/api/v1"
     * @return self 当前实例，支持链式调用
     */
    public function prefix(string $prefix): self
    {
        $prefix = rtrim(trim($prefix), '/');

        if ($prefix === '') {
            return $this;
        }

        if ($prefix[0] !== '/') {
            $prefix = '/' . $prefix;
        }

        $this->path = $this->path === '/' ? $prefix : $prefix . $this->path;

        return $this;
    }

    /**
     * 获取 HTTP 方法列表
     *
     * @return list<string> 大写的方法列表
     */
    public function getMethods(): array
    {
        return $this->methods;
    }

    /**
     * 判断是否支持指定 HTTP 方法
     *
     * @param string $method HTTP 方法
     * @return bool 支持返回 true
     */
    public function matchesMethod(string $method): bool
    {
        return in_array(strtoupper($method), $this->methods, true);
    }

    /**
     * 获取路由处理器
     *
     * @return mixed 处理器
     */
    public function getHandler(): mixed
    {
        return $this->handler;
    }

    /**
     * 获取路由处理器（1.x 兼容别名）
     *
     * @return mixed 处理器
     */
    public function getCallback(): mixed
    {
        return $this->handler;
    }

    /**
     * 获取路由级中间件列表
     *
     * @return list<mixed> 中间件列表
     */
    public function getMiddlewares(): array
    {
        return $this->middlewares;
    }

    /**
     * 附加中间件（可一次传多个）
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
     * 声明路径参数的正则约束
     *
     * 支持两种写法：
     * - `where('id', '\d+')`
     * - `where(['id' => '\d+', 'slug' => '[a-z-]+'])`
     *
     * @param string|array<string, string> $name 参数名，或 参数名 => 正则 映射
     * @param string|null $pattern 正则片段，$name 为数组时忽略
     * @return self 当前实例，支持链式调用
     *
     * @throws InvalidRouteException 参数名不存在于路径中时抛出
     */
    public function where(string|array $name, ?string $pattern = null): self
    {
        $constraints = is_array($name) ? $name : [$name => (string) $pattern];
        $available = $this->getParameterNames();

        foreach ($constraints as $param => $regex) {
            if (!in_array($param, $available, true)) {
                throw new InvalidRouteException(
                    "路由 {$this->path} 中不存在参数 {{$param}}，无法为其设置约束"
                );
            }
            $this->wheres[$param] = $regex;
        }

        return $this;
    }

    /**
     * 获取参数正则约束表
     *
     * @return array<string, string> 参数名 => 正则片段
     */
    public function getWheres(): array
    {
        return $this->wheres;
    }

    /**
     * 声明参数默认值
     *
     * 用于可选段：`/posts[/{page}]` 配合 `defaults(['page' => 1])`，
     * 未传 page 时匹配结果里也能拿到 page = 1。
     *
     * @param array<string, mixed> $defaults 参数名 => 默认值
     * @return self 当前实例，支持链式调用
     */
    public function defaults(array $defaults): self
    {
        foreach ($defaults as $key => $value) {
            $this->defaults[$key] = $value;
        }

        return $this;
    }

    /**
     * 获取参数默认值表
     *
     * @return array<string, mixed> 参数名 => 默认值
     */
    public function getDefaults(): array
    {
        return $this->defaults;
    }

    /**
     * 设置所属路由组名称
     *
     * @param string|null $groupName 路由组名称
     * @return self 当前实例，支持链式调用
     */
    public function setGroupName(?string $groupName): self
    {
        $this->groupName = $groupName;
        return $this;
    }

    /**
     * 获取所属路由组名称
     *
     * @return string|null 路由组名称
     */
    public function getGroupName(): ?string
    {
        return $this->groupName;
    }

    /**
     * 提取路径中声明的全部参数名
     *
     * @return list<string> 参数名列表，按出现顺序
     */
    public function getParameterNames(): array
    {
        preg_match_all(
            '~' . self::VARIABLE_REGEX . '~x',
            $this->path,
            $matches,
            PREG_SET_ORDER
        );

        $names = [];
        foreach ($matches as $match) {
            $names[] = $match[1];
        }

        return $names;
    }

    /**
     * 获取注入了 where 约束后的最终路径
     *
     * `/users/{id}` + where(id, '\d+') 会编译成 `/users/{id:\d+}`，
     * 这是真正交给 FastRoute 的路径。已经内联写了正则的占位符不会被覆盖。
     *
     * @return string 编译后的路径
     */
    public function getCompiledPath(): string
    {
        if ($this->wheres === []) {
            return $this->path;
        }

        $wheres = $this->wheres;

        $compiled = preg_replace_callback(
            '~' . self::VARIABLE_REGEX . '~x',
            static function (array $match) use ($wheres): string {
                $name = $match[1];
                $inline = $match[2] ?? '';

                // 路径里已经内联了正则，以内联的为准
                if ($inline !== '') {
                    return $match[0];
                }

                return isset($wheres[$name])
                    ? '{' . $name . ':' . $wheres[$name] . '}'
                    : $match[0];
            },
            $this->path
        );

        return $compiled ?? $this->path;
    }

    /**
     * 是否为可静态缓存的路由
     *
     * 处理器为闭包时无法序列化，该路由所在的路由表也就无法写入文件缓存。
     *
     * @return bool 可缓存返回 true
     */
    public function isCacheable(): bool
    {
        if ($this->handler instanceof \Closure) {
            return false;
        }

        if (is_object($this->handler)) {
            return false;
        }

        foreach ($this->middlewares as $middleware) {
            if ($middleware instanceof \Closure || is_object($middleware)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 转为 FastRoute 路由数组格式 [methods, path, handler]
     *
     * @return array{0: list<string>, 1: string, 2: mixed} FastRoute 三元组
     */
    public function toFastRoute(): array
    {
        return [$this->methods, $this->getCompiledPath(), $this->handler];
    }

    /**
     * 导出为可序列化的元数据数组
     *
     * @param int|null $id 路由 ID
     * @return array<string, mixed> 元数据
     */
    public function toArray(?int $id = null): array
    {
        return [
            'id' => $id,
            'methods' => $this->methods,
            'path' => $this->path,
            'compiled' => $this->getCompiledPath(),
            'name' => $this->name,
            'handler' => $this->handler,
            'middleware' => $this->middlewares,
            'wheres' => $this->wheres,
            'defaults' => $this->defaults,
            'group' => $this->groupName,
        ];
    }
}
