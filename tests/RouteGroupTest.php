<?php

namespace Kode\Router\Tests;

use Kode\Router\Router;
use Kode\Router\RouteGroup;
use PHPUnit\Framework\TestCase;

class RouteGroupTest extends TestCase
{
    public function testGroupPrefix()
    {
        $group = new RouteGroup('api', '/api/v1');
        $this->assertEquals('api', $group->getName());
        $this->assertEquals('/api/v1', $group->getPrefix());
    }

    public function testGroupMiddleware()
    {
        $group = new RouteGroup('api');
        $group->middleware('auth');

        $this->assertEquals(['auth'], $group->getMiddlewares());
    }

    public function testNestedGroups()
    {
        $router = new Router();

        // 直接创建嵌套组
        $apiGroup = new RouteGroup('api', '/api');
        $v1Group = new RouteGroup('v1', '/v1');
        
        $v1Group->addRoute(new \Kode\Router\Route(['GET'], '/users', function () {
            return 'users';
        }, 'api.v1.users'));
        
        $apiGroup->addGroup($v1Group);
        $apiGroup->addRoute(new \Kode\Router\Route(['GET'], '/posts', function () {
            return 'posts';
        }, 'api.posts'));

        $this->assertCount(1, $apiGroup->getGroups());
        $this->assertCount(2, $apiGroup->getRoutes());
        
        // 测试嵌套路由是否正确注册（包含子组的路由）
        $routes = $apiGroup->getRoutes();
        $this->assertCount(2, $routes);
        // 路由顺序取决于添加顺序，这里检查路径存在即可
        $paths = array_map(fn($r) => $r->getPath(), $routes);
        $this->assertContains('/users', $paths);
        $this->assertContains('/posts', $paths);
    }

    public function testGroupWithMultipleRoutes()
    {
        $group = new RouteGroup('blog');
        $group->prefix('/blog');

        $group->addRoute(new \Kode\Router\Route(['GET'], '/posts', function () {
            return 'posts';
        }, 'blog.posts'));

        $group->addRoute(new \Kode\Router\Route(['POST'], '/posts', function () {
            return 'create';
        }, 'blog.create'));

        $this->assertCount(2, $group->getRoutes());
    }
}
