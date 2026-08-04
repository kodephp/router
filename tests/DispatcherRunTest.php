<?php

declare(strict_types=1);

namespace Kode\Router\Tests;

use Kode\Router\Router;
use Kode\Router\Tests\Fixtures\TagMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * Dispatcher::run() 测试：按 route + group 中间件顺序执行管道，
 * 同时验证 handle() 不执行中间件（向后兼容）。
 */
final class DispatcherRunTest extends TestCase
{
    public function testRunExecutesRouteMiddleware(): void
    {
        $router = new Router();
        $router->get('/hi', static fn (array $tags = []) => $tags)
            ->middleware(new TagMiddleware('A'), new TagMiddleware('B'));

        $dispatcher = $router->createDispatcher();

        $this->assertSame(['A', 'B'], $dispatcher->run('GET', '/hi'));
    }

    public function testRunExecutesGroupMiddleware(): void
    {
        $router = new Router();
        $router->group('g', static function ($g, $r): void {
            $r->get('/x', static fn (array $tags = []) => $tags);
        }, '', [new TagMiddleware('G')]);

        $dispatcher = $router->createDispatcher();

        $this->assertSame(['G'], $dispatcher->run('GET', '/x'));
    }

    public function testHandleSkipsMiddleware(): void
    {
        $router = new Router();
        $router->get('/hi', static fn () => 'direct')->middleware(new TagMiddleware('A'));

        $dispatcher = $router->createDispatcher();

        $this->assertSame('direct', $dispatcher->handle('GET', '/hi'));
    }

    public function testRunPassesParamsToHandler(): void
    {
        $router = new Router();
        $router->get('/echo/{msg}', static fn (string $msg) => "msg:$msg");

        $dispatcher = $router->createDispatcher();

        $this->assertSame('msg:hello', $dispatcher->run('GET', '/echo/hello'));
    }

    public function testRunThrowsOnNotFound(): void
    {
        $router = new Router();
        $router->get('/ok', static fn () => 'x');

        $dispatcher = $router->createDispatcher();

        $this->expectException(\Kode\Router\Exception\RouteNotFoundException::class);
        $dispatcher->run('GET', '/missing');
    }
}
