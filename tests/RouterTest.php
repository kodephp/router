<?php

namespace Kode\Router\Tests;

use Kode\Router\Router;
use Kode\Router\Dispatcher;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    public function testBasicRoute()
    {
        $router = new Router();
        $router->get('/hello/{name}', function ($name) {
            return "Hello, {$name}!";
        }, 'hello');

        $dispatcher = new Dispatcher($router->getRouteCollector(), $router->resolveAll());

        $result = $dispatcher->execute('GET', '/hello/World');
        $this->assertEquals('Hello, World!', $result);
    }

    public function testNamedRoute()
    {
        $router = new Router();
        $router->get('/users/{id}', function ($id) {
            return "User {$id}";
        }, 'user.show');

        $this->assertEquals('/users/{id}', $router->resolveRoute('user.show'));
    }

    public function testRouteGroup()
    {
        $router = new Router();

        $router->group('api', function ($group) {
            $group->prefix('/api/v1');

            $group->addRoute(new \Kode\Router\Route(['GET'], '/users', function () {
                return ['users' => []];
            }, 'api.users'));
        });

        $this->assertCount(1, $router->getGroups());
        $this->assertInstanceOf(\Kode\Router\RouteGroup::class, $router->getGroup('api'));
    }

    public function testNotFound()
    {
        $router = new Router();
        $dispatcher = new Dispatcher($router->getRouteCollector(), []);

        $this->expectException(\RuntimeException::class);
        $dispatcher->execute('GET', '/not-found');
    }

    public function testMethodNotAllowed()
    {
        $router = new Router();
        $router->get('/hello', function () {
            return 'hello';
        });

        $dispatcher = new Dispatcher($router->getRouteCollector(), []);

        $this->expectException(\RuntimeException::class);
        $dispatcher->execute('POST', '/hello');
    }

    public function testUrlGeneration()
    {
        $router = new Router();
        $router->get('/users/{id}', function () {
            return 'user';
        }, 'user.show');

        $dispatcher = new Dispatcher($router->getRouteCollector(), ['user.show' => '/users/{id}']);
        $url = $dispatcher->url('user.show', ['id' => 123]);

        $this->assertEquals('/users/123', $url);
    }
}
