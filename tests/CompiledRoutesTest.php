<?php

namespace Kode\Router\Tests;

use Kode\Router\CompiledRoutes;
use Kode\Router\Exception\RouteCacheException;
use Kode\Router\RouteCollection;
use PHPUnit\Framework\TestCase;

class CompiledRoutesTest extends TestCase
{
    public function testFromCollectionRegistersIntRouteIds(): void
    {
        $collection = new RouteCollection();
        $collection->addRoute(['GET'], '/users/{id}', 'User@show', 'user.show');

        $compiled = CompiledRoutes::fromCollection($collection);

        // 元数据里保留真实处理器（字符串，可序列化），命名表指向 int 路由 ID
        $this->assertArrayHasKey(0, $compiled->getRoutes());
        $this->assertSame('User@show', $compiled->getRoutes()[0]['handler']);
        $this->assertSame(['user.show' => 0], $compiled->getNamed());

        // 调度数据里 handler 位置必须是 int 路由 ID（0）
        $foundId = false;
        $dispatchData = $compiled->getDispatchData();
        array_walk_recursive($dispatchData, static function ($value) use (&$foundId): void {
            if ($value === 0) {
                $foundId = true;
            }
        });
        $this->assertTrue($foundId, '调度数据应携带 int 路由 ID');

        $this->assertSame(1, $compiled->count());
        $this->assertSame(64, strlen($compiled->getFingerprint()));
    }

    public function testRoundTripViaArray(): void
    {
        $collection = new RouteCollection();
        $collection->addRoute(['GET'], '/users/{id}', 'User@show', 'user.show');
        $collection->addRoute(['GET'], '/posts', 'Post@index', 'post.index');

        $compiled = CompiledRoutes::fromCollection($collection);
        $array = $compiled->toArray();
        $restored = CompiledRoutes::fromArray($array);

        $this->assertSame($compiled->getFingerprint(), $restored->getFingerprint());
        $this->assertSame($compiled->count(), $restored->count());
        $this->assertCount(2, $restored->getRoutes());
        $this->assertSame('user.show', $restored->getRouteByName('user.show')['name'] ?? null);
    }

    public function testIsCacheableWithStringHandlers(): void
    {
        $collection = new RouteCollection();
        $collection->addRoute(['GET'], '/x', 'X@h', 'x');

        $compiled = CompiledRoutes::fromCollection($collection);
        $this->assertTrue($compiled->isCacheable());
        $this->assertSame([], $compiled->getUncacheableRoutes());
    }

    public function testUncacheableWhenClosureHandler(): void
    {
        $collection = new RouteCollection();
        $collection->addRoute(['GET'], '/x', fn () => 'x', 'x');

        $compiled = CompiledRoutes::fromCollection($collection);
        $this->assertFalse($compiled->isCacheable());
        $this->assertNotEmpty($compiled->getUncacheableRoutes());

        $this->expectException(RouteCacheException::class);
        $compiled->toArray();
    }

    public function testFromArrayMissingFieldThrows(): void
    {
        $this->expectException(RouteCacheException::class);
        CompiledRoutes::fromArray(['dispatch_data' => []]); // 缺少其它字段
    }

    public function testGetDispatcherIsReused(): void
    {
        $collection = new RouteCollection();
        $collection->addRoute(['GET'], '/x', 'X@h', 'x');

        $compiled = CompiledRoutes::fromCollection($collection);
        $first = $compiled->getDispatcher();
        $second = $compiled->getDispatcher();

        $this->assertSame($first, $second);
    }

    public function testNamedLookup(): void
    {
        $collection = new RouteCollection();
        $collection->addRoute(['GET'], '/users/{id}', 'User@show', 'user.show');

        $compiled = CompiledRoutes::fromCollection($collection);
        $meta = $compiled->getRouteByName('user.show');

        $this->assertNotNull($meta);
        $this->assertSame('/users/{id}', $meta['compiled'] ?? $meta['path'] ?? '');
        $this->assertNull($compiled->getRouteByName('nope'));
    }
}
