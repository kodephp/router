<?php

namespace Kode\Router\Tests\Integration;

use Kode\Router\Dispatcher;
use Kode\Router\Exception\RouterException;
use Kode\Router\Integration\MiddlewareBridge;
use Kode\Router\MatchResult;
use Kode\Router\Router;
use PHPUnit\Framework\TestCase;

class MiddlewareBridgeTest extends TestCase
{
    public function testIsAvailableFalseWithoutPackage(): void
    {
        $this->assertFalse(MiddlewareBridge::isAvailable());
    }

    public function testToRouteResultThrowsWhenUnavailable(): void
    {
        if (MiddlewareBridge::isAvailable()) {
            $this->markTestSkipped('kode/middleware 已安装');
        }

        $this->expectException(RouterException::class);
        MiddlewareBridge::toRouteResult(MatchResult::found('h'));
    }

    public function testMatcherThrowsWhenUnavailable(): void
    {
        if (MiddlewareBridge::isAvailable()) {
            $this->markTestSkipped('kode/middleware 已安装');
        }

        $router = new Router();
        $router->get('/x', fn () => 'x', 'x');
        $dispatcher = $router->createDispatcher();

        $this->expectException(RouterException::class);
        MiddlewareBridge::matcher($dispatcher);
    }

    public function testAvailableBranchProducesRouteResult(): void
    {
        if (!MiddlewareBridge::isAvailable()) {
            $this->markTestSkipped('kode/middleware 未安装');
        }

        $router = new Router();
        $router->get('/users/{id}', fn () => 'x', 'user.show');
        $dispatcher = $router->createDispatcher();

        $matcher = MiddlewareBridge::matcher($dispatcher);
        $request = new \Nyholm\Psr7\ServerRequest('GET', '/users/1');
        $result = $matcher($request);

        $this->assertInstanceOf(\Kode\Middleware\Routing\RouteResult::class, $result);
    }
}
