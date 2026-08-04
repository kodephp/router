<?php

declare(strict_types=1);

/**
 * 示例：使用 kode/router 构建基础 Web 应用
 *
 * 运行：php -S localhost:8000 example/index.php
 */

require __DIR__ . '/../vendor/autoload.php';

use Kode\Router\Application;
use Kode\Router\Concurrency\Runtime;
use Kode\Router\Request;
use Kode\Router\Response;
use Kode\Router\RouteGroup;

$app = new Application();

// —— 基础路由 ——
$app->get('/', fn (): Response => Response::html(
    '<h1>欢迎来到 kode/router</h1><p>运行环境：' . Runtime::host() . '</p>'
), 'home');

$app->get('/hello/{name}', fn (string $name): string => "你好，{$name}！", 'hello');

// —— 带约束与默认值的路由 ——
$app->get('/posts/{slug}/{page}', fn (string $slug, int $page): Response => Response::json([
    'slug' => $slug,
    'page' => $page,
]), 'post.page')
    ->where('slug', '[a-z0-9\-]+')
    ->where('page', '\d+')
    ->defaults(['page' => 1]);

// —— 路由组 ——
$app->group('api', function (RouteGroup $group): void {
    $group->prefix('/api/v1');

    $group->route(['GET'], '/users', fn (): Response => Response::json([
        'users' => ['张三', '李四', '王五'],
    ]), 'api.users');

    $group->route(['POST'], '/users', fn (): Response => Response::json([
        'created' => true,
        'id'      => 1,
    ], 201), 'api.users.create');
});

// —— URL 生成 ——
$app->get('/about', function () use ($app): Response {
    $dispatcher = $app->getDispatcher();

    return Response::json([
        'name'        => 'kode/router',
        'version'     => '2.0.0',
        'runtime'     => Runtime::detect(),
        'fingerprint' => substr($dispatcher->getFingerprint(), 0, 16),
        'links'       => [
            'home'      => $dispatcher->url('home'),
            'hello'     => $dispatcher->url('hello', ['name' => '世界']),
            'post.page' => $dispatcher->url('post.page', ['slug' => 'hello-world']),
        ],
    ]);
}, 'about');

// —— 全局中间件：响应生成后执行 ——
$app->middleware(function (Request $request, Response $response): void {
    error_log(sprintf(
        '[%s] %s %s -> %d',
        date('Y-m-d H:i:s'),
        $request->getMethod(),
        $request->getPath(),
        $response->getStatusCode()
    ));
});

$app->send();
