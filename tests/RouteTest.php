<?php

namespace Kode\Router\Tests;

use Kode\Router\Route;
use PHPUnit\Framework\TestCase;

class RouteTest extends TestCase
{
    public function testRouteCreation()
    {
        $route = new Route(['GET'], '/hello', function () {
            return 'hello';
        }, 'hello');

        $this->assertEquals(['GET'], $route->getMethods());
        $this->assertEquals('/hello', $route->getPath());
        $this->assertEquals('hello', $route->getName());
    }

    public function testRouteWithoutName()
    {
        $route = new Route(['GET'], '/hello', function () {
            return 'hello';
        });

        $this->assertNull($route->getName());
    }

    public function testRouteMiddleware()
    {
        $route = new Route(['GET'], '/hello', function () {
            return 'hello';
        });

        $route->middleware('auth');
        $route->middleware('cors');

        $this->assertEquals(['auth', 'cors'], $route->getMiddlewares());
    }

    public function testRouteGroupName()
    {
        $route = new Route(['GET'], '/hello', function () {
            return 'hello';
        });

        $route->setGroupName('api');
        $this->assertEquals('api', $route->getGroupName());
    }

    public function testToFastRoute()
    {
        $callback = function () {
            return 'hello';
        };

        $route = new Route(['GET'], '/hello', $callback, 'hello');
        $fastRoute = $route->toFastRoute();

        $this->assertEquals([$route->getMethods(), $route->getPath(), $route->getCallback()], $fastRoute);
    }

    public function testWhereAddsConstraint(): void
    {
        $route = new Route(['GET'], '/users/{id}', fn () => null);
        $route->where('id', '\d+');

        $this->assertSame(['id' => '\d+'], $route->getWheres());
        $this->assertSame('/users/{id:\d+}', $route->getCompiledPath());
    }

    public function testWhereUnknownParameterThrows(): void
    {
        $route = new Route(['GET'], '/users/{id}', fn () => null);

        $this->expectException(\Kode\Router\Exception\InvalidRouteException::class);
        $route->where('slug', '\w+');
    }

    public function testDefaults(): void
    {
        $route = new Route(['GET'], '/posts[/{page}]', fn () => null);
        $route->defaults(['page' => 1]);

        $this->assertSame(['page' => 1], $route->getDefaults());
    }

    public function testGetParameterNames(): void
    {
        $route = new Route(['GET'], '/users/{id}/posts/{slug}', fn () => null);

        $this->assertSame(['id', 'slug'], $route->getParameterNames());
    }

    public function testNormalizePathStatic(): void
    {
        $this->assertSame('/users', Route::normalizePath('users'));
        $this->assertSame('/', Route::normalizePath(''));
        $this->assertSame('/', Route::normalizePath('/'));
        $this->assertSame('/users', Route::normalizePath('/users/'));
        $this->assertSame('/posts[/{page}]', Route::normalizePath('posts[/{page}]'));
    }

    public function testPrefix(): void
    {
        $route = new Route(['GET'], '/users', fn () => null);
        $route->prefix('/api/v1');

        $this->assertSame('/api/v1/users', $route->getPath());
    }

    public function testIsCacheable(): void
    {
        $withClosure = new Route(['GET'], '/a', fn () => null);
        $this->assertFalse($withClosure->isCacheable());

        $stringHandler = new Route(['GET'], '/b', 'B@handle');
        $this->assertTrue($stringHandler->isCacheable());
    }

    public function testEmptyMethodsThrows(): void
    {
        $this->expectException(\Kode\Router\Exception\InvalidRouteException::class);
        new Route([], '/x', fn () => null);
    }

    public function testGetCallbackAlias(): void
    {
        $route = new Route(['GET'], '/x', 'X@handle');
        $this->assertSame('X@handle', $route->getCallback());
    }
}
