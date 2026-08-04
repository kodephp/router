<?php

namespace Kode\Router\Tests\Cache;

use Kode\Router\Cache\ApcuRouteCache;
use Kode\Router\CompiledRoutes;
use Kode\Router\RouteCollection;
use PHPUnit\Framework\TestCase;

class ApcuRouteCacheTest extends TestCase
{
    private function compiled(): CompiledRoutes
    {
        $collection = new RouteCollection();
        $collection->addRoute(['GET'], '/users/{id}', 'User@show', 'user.show');

        return CompiledRoutes::fromCollection($collection);
    }

    public function testRoundTripWhenSupported(): void
    {
        if (!ApcuRouteCache::isSupported()) {
            $this->markTestSkipped('APCu 不可用');
        }

        $cache = new ApcuRouteCache('kode.router.test.');
        $compiled = $this->compiled();

        $this->assertTrue($cache->set('k1', $compiled));

        $loaded = $cache->get('k1');
        $this->assertInstanceOf(CompiledRoutes::class, $loaded);
        $this->assertSame($compiled->getFingerprint(), $loaded->getFingerprint());

        $this->assertTrue($cache->forget('k1'));
        $this->assertNull($cache->get('k1'));
    }

    public function testUncacheableReturnsFalse(): void
    {
        if (!ApcuRouteCache::isSupported()) {
            $this->markTestSkipped('APCu 不可用');
        }

        $collection = new RouteCollection();
        $collection->addRoute(['GET'], '/x', fn () => 'x', 'x');

        $cache = new ApcuRouteCache('kode.router.test.');
        $this->assertFalse($cache->set('k2', CompiledRoutes::fromCollection($collection)));
    }

    public function testIsSupportedReturnsBool(): void
    {
        $this->assertIsBool(ApcuRouteCache::isSupported());
    }
}
