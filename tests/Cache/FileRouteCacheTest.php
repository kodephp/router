<?php

namespace Kode\Router\Tests\Cache;

use Kode\Router\Cache\FileRouteCache;
use Kode\Router\CompiledRoutes;
use Kode\Router\RouteCollection;
use PHPUnit\Framework\TestCase;

class FileRouteCacheTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/kode-router-cache-' . uniqid('', true);
    }

    protected function tearDown(): void
    {
        $cache = new FileRouteCache($this->dir);
        $cache->clear();
        @rmdir($this->dir);
    }

    private function compiled(bool $cacheable): CompiledRoutes
    {
        $collection = new RouteCollection();
        $handler = $cacheable ? 'User@show' : fn () => 'x';
        $collection->addRoute(['GET'], '/users/{id}', $handler, 'user.show');

        return CompiledRoutes::fromCollection($collection);
    }

    public function testSetAndGetRoundTrip(): void
    {
        $cache = new FileRouteCache($this->dir);
        $compiled = $this->compiled(true);

        $this->assertTrue($cache->set('k1', $compiled));

        $loaded = $cache->get('k1');
        $this->assertInstanceOf(CompiledRoutes::class, $loaded);
        $this->assertSame($compiled->getFingerprint(), $loaded->getFingerprint());
        $this->assertSame($compiled->count(), $loaded->count());
    }

    public function testRememberCachesAndReuses(): void
    {
        $cache = new FileRouteCache($this->dir);
        $compiled = $cache->remember('k1', fn () => $this->compiled(true));

        $this->assertInstanceOf(CompiledRoutes::class, $compiled);
        $this->assertTrue($cache->has('k1'));

        // 第二次命中缓存，工厂不应再被调用
        $calls = 0;
        $again = $cache->remember('k1', static function () use (&$calls): CompiledRoutes {
            $calls++;
            throw new \RuntimeException('工厂不应被调用');
        });
        $this->assertSame(0, $calls);
        $this->assertSame($compiled->getFingerprint(), $again->getFingerprint());
    }

    public function testHasAndForget(): void
    {
        $cache = new FileRouteCache($this->dir);
        $cache->set('k1', $this->compiled(true));

        $this->assertTrue($cache->has('k1'));
        $this->assertTrue($cache->forget('k1'));
        $this->assertFalse($cache->has('k1'));
        $this->assertNull($cache->get('k1'));
    }

    public function testUncacheableRouteNotWritten(): void
    {
        $cache = new FileRouteCache($this->dir);
        $this->assertFalse($cache->set('k1', $this->compiled(false)));
        $this->assertNull($cache->get('k1'));
    }

    public function testClear(): void
    {
        $cache = new FileRouteCache($this->dir);
        $cache->set('k1', $this->compiled(true));
        $cache->set('k2', $this->compiled(true));

        $this->assertTrue($cache->clear());
        $this->assertFalse($cache->has('k1'));
        $this->assertFalse($cache->has('k2'));
    }

    public function testPathGeneration(): void
    {
        $cache = new FileRouteCache($this->dir);
        $this->assertStringEndsWith('.route-cache.php', $cache->path('my-key'));
        $this->assertStringContainsString('my-key', $cache->path('my-key'));
    }
}
