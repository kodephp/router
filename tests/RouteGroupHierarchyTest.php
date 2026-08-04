<?php

declare(strict_types=1);

namespace Kode\Router\Tests;

use Kode\Router\RouteGroup;
use Kode\Router\Router;
use PHPUnit\Framework\TestCase;

/**
 * 嵌套分组层级命名测试：子组应生成 parent.child 形式的组名。
 */
final class RouteGroupHierarchyTest extends TestCase
{
    public function testNestedGroupProducesHierarchicalName(): void
    {
        $root = new RouteGroup('api', '/api');
        $inner = $root->group('v1', static function (): void {
        }, '/v1');
        $inner->route(['GET'], '/users', 'h')->name('users');

        $flat = $root->flatten();

        $this->assertCount(1, $flat);
        $this->assertSame('api.v1', $flat[0]->getGroupName());
        $this->assertSame('/api/v1/users', $flat[0]->getPath());
    }

    public function testTopLevelGroupNameIsFlat(): void
    {
        $g = new RouteGroup('admin', '/admin');
        $g->route(['GET'], '/dash', 'h');

        $flat = $g->flatten();

        $this->assertSame('admin', $flat[0]->getGroupName());
    }

    public function testRouterNestedGroupHierarchicalName(): void
    {
        $router = new Router();
        $router->group('api', static function ($g, $r): void {
            $g->group('v1', static function ($cg): void {
                $cg->route(['GET'], '/users', 'h')->name('users');
            }, '/v1');
        }, '/api');

        $routes = $router->getRoutes();

        $this->assertCount(1, $routes);
        $this->assertSame('api.v1', $routes[0]->getGroupName());
        $this->assertSame('/api/v1/users', $routes[0]->getPath());
    }
}
