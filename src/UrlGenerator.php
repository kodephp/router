<?php

declare(strict_types=1);

namespace Kode\Router;

use FastRoute\RouteParser\Std as StdRouteParser;
use Kode\Router\Exception\UrlGenerationException;

/**
 * URL 反向生成器
 *
 * 由命名路由反推出 URL，正确处理三件 1.x 版本做不到的事：
 * - 可选段 `/posts[/{page}]`：按传入参数自动选择最长可满足的变体
 * - 正则约束 `{id:\d+}`：生成前校验参数值，不合法直接抛异常而不是产出坏链接
 * - 多余参数：自动降级为查询字符串
 *
 * 无状态且只读，可跨协程共享。
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class UrlGenerator
{
    /** @var StdRouteParser 路径解析器 */
    private StdRouteParser $parser;

    /** @var array<string, list<list<string|array{0: string, 1: string}>>> 解析结果缓存：路径 => 变体列表 */
    private array $parsed = [];

    /**
     * @param array<string, string> $namedPaths 命名路由表：名称 => 编译后路径
     * @param string $basePath 统一前缀，例如部署在子目录时的 "/app"
     */
    public function __construct(
        private readonly array $namedPaths = [],
        private readonly string $basePath = ''
    ) {
        $this->parser = new StdRouteParser();
    }

    /**
     * 从编译产物创建
     *
     * @param CompiledRoutes $compiled 编译后的路由表
     * @param string $basePath 统一前缀
     * @return self 生成器实例
     */
    public static function fromCompiled(CompiledRoutes $compiled, string $basePath = ''): self
    {
        $paths = [];

        foreach ($compiled->getNamed() as $name => $id) {
            $meta = $compiled->getRoute($id);

            if ($meta !== null) {
                $paths[$name] = (string) ($meta['compiled'] ?? $meta['path'] ?? '');
            }
        }

        return new self($paths, $basePath);
    }

    /**
     * 判断命名路由是否存在
     *
     * @param string $name 路由名称
     * @return bool 存在返回 true
     */
    public function has(string $name): bool
    {
        return isset($this->namedPaths[$name]);
    }

    /**
     * 获取全部命名路由
     *
     * @return array<string, string> 名称 => 路径
     */
    public function all(): array
    {
        return $this->namedPaths;
    }

    /**
     * 生成 URL
     *
     * @param string $name 路由名称
     * @param array<string, mixed> $params 路径参数，多余的会变成查询字符串
     * @param array<string, mixed> $query 额外的查询参数
     * @return string 生成的 URL
     *
     * @throws UrlGenerationException 路由不存在或参数不满足时抛出
     */
    public function generate(string $name, array $params = [], array $query = []): string
    {
        $path = $this->namedPaths[$name] ?? null;

        if ($path === null) {
            throw new UrlGenerationException("命名路由不存在: {$name}");
        }

        $variants = $this->parsed[$path] ??= $this->parser->parse($path);

        // FastRoute 按"从短到长"返回变体，倒序遍历即优先选用参数最多的那个
        $missing = [];

        foreach (array_reverse($variants) as $segments) {
            $required = [];

            foreach ($segments as $segment) {
                if (is_array($segment)) {
                    $required[] = $segment[0];
                }
            }

            $absent = array_diff($required, array_keys($params));

            if ($absent !== []) {
                $missing = $absent;
                continue;
            }

            return $this->build($name, $segments, $required, $params, $query);
        }

        throw new UrlGenerationException(
            "生成路由 {$name} 的 URL 缺少必填参数: " . implode('、', array_unique($missing))
        );
    }

    /**
     * 拼装最终 URL
     *
     * @param string $name 路由名称，仅用于报错
     * @param list<string|array{0: string, 1: string}> $segments 路径片段
     * @param list<string> $required 该变体用到的参数名
     * @param array<string, mixed> $params 全部路径参数
     * @param array<string, mixed> $query 额外查询参数
     * @return string URL
     *
     * @throws UrlGenerationException 参数值不满足正则约束时抛出
     */
    private function build(
        string $name,
        array $segments,
        array $required,
        array $params,
        array $query
    ): string {
        $url = '';

        foreach ($segments as $segment) {
            if (is_string($segment)) {
                $url .= $segment;
                continue;
            }

            [$varName, $regex] = $segment;
            $value = self::stringify($params[$varName]);

            if (preg_match('~^(?:' . $regex . ')$~u', $value) !== 1) {
                throw new UrlGenerationException(
                    "路由 {$name} 的参数 {$varName} 取值 \"{$value}\" 不满足约束 {$regex}"
                );
            }

            $url .= rawurlencode($value);
        }

        // 没用上的路径参数降级为查询字符串
        $extra = array_diff_key($params, array_flip($required));
        $queryParams = array_merge($extra, $query);

        $url = $this->basePath . $url;

        if ($queryParams !== []) {
            $url .= '?' . http_build_query($queryParams);
        }

        return $url === '' ? '/' : $url;
    }

    /**
     * 把参数值转成字符串
     *
     * @param mixed $value 参数值
     * @return string 字符串形式
     *
     * @throws UrlGenerationException 值无法转成字符串时抛出
     */
    private static function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if ($value instanceof \BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        throw new UrlGenerationException(
            '路由参数必须是标量、BackedEnum 或 Stringable，收到 ' . get_debug_type($value)
        );
    }
}
