<?php

declare(strict_types=1);

namespace Kode\Router\Tests\Fixtures;

use Kode\Router\MiddlewareInterface;

/**
 * 测试用中间件：把自身 tag 追加进 args['tags'] 后交给下一层。
 *
 * 构造函数参数可选，因此既可作为实例、也可作为类名字符串被
 * {@see \Kode\Router\Middleware\MiddlewareResolver} 解析。
 */
class TagMiddleware implements MiddlewareInterface
{
    public function __construct(private string $tag = 'default')
    {
    }

    public function handle(array $args, callable $next): mixed
    {
        $args['tags'][] = $this->tag;

        return $next($args);
    }
}
