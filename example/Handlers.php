<?php

declare(strict_types=1);

namespace Kode\Router\Example;

use Kode\Router\MiddlewareInterface;

/**
 * 示例用的路由处理器
 *
 * 写成静态方法（而非闭包）是刻意的：只有字符串或 [类名, 方法名] 形式的
 * 处理器才能被 var_export 落盘，路由缓存与跨进程 / 跨线程传递才能生效。
 */
final class Handlers
{
    public static function home(): string
    {
        return 'home';
    }

    public static function showUser(int $id): string
    {
        return "user {$id}";
    }

    public static function createUser(): string
    {
        return 'created';
    }

    public static function post(string $slug, int $page = 1): string
    {
        return "post {$slug} p{$page}";
    }
}

/**
 * 示例中间件：在参数里追加一个 tag，用于演示洋葱模型执行。
 *
 * 实现内置的 {@see MiddlewareInterface}（与 kode/middleware 同一份管道契约），
 * 用字符串类名注册也能被 var_export 落盘，不影响路由缓存。
 */
final class TagMiddleware implements MiddlewareInterface
{
    public function __construct(private string $tag = 'auth')
    {
    }

    public function handle(array $args, callable $next): mixed
    {
        $args['tags'][] = $this->tag;

        return $next($args);
    }
}
