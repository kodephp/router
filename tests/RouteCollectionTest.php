<?php

namespace Kode\Router\Tests;

use Kode\Router\CompiledRoutes;
use Kode\Router\Route;
use Kode\Router\RouteCollection;
use Kode\Router\Exception\InvalidRouteException;
use PHPUnit\Framework\TestCase;

class RouteCollectionTest extends TestCase
{
    public function testAddAndCount(): void
    {
        $collection = new RouteCollection();
        $collection->addRoute(['GET'], '/a', 'A@handle', 'a');
        $collection->addRoute(['POST'], '/b', 'B@handle');

        $this->assertCount(2, $collection);
        $this->assertTrue($collection->hasName('a'));
        $this->assertFalse($collection->hasName('b'));
        $this->assertArrayHasKey('a', $collection->names());
    }

    public function testDuplicateNameThrows(): void
    {
        $collection = new RouteCollection();
        $collection->addRoute(['GET'], '/a', 'A@handle', 'dup');

        $this->expectException(InvalidRouteException::class);
        $collection->addRoute(['GET'], '/b', 'B@handle', 'dup');
    }

    public function testIteratorAndGet(): void
    {
        $collection = new RouteCollection();
        $collection->addRoute(['GET'], '/a', 'A@handle', 'a');
        $collection->addRoute(['GET'], '/b', 'B@handle', 'b');

        $ids = [];
        foreach ($collection as $id => $route) {
            $ids[] = $id;
            $this->assertInstanceOf(Route::class, $route);
        }
        $this->assertSame([0, 1], $ids);
        $this->assertInstanceOf(Route::class, $collection->get(0));
        $this->assertSame('a', $collection->getByName('a')->getName());
    }

    public function testFingerprintIsDeterministic(): void
    {
        $a = $this->buildSample();
        $b = $this->buildSample();

        $this->assertSame($a->fingerprint(), $b->fingerprint());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a->fingerprint());
    }

    public function testFingerprintChangesWithHandler(): void
    {
        $a = new RouteCollection();
        $a->addRoute(['GET'], '/x', 'A@handle', 'x');

        $b = new RouteCollection();
        $b->addRoute(['GET'], '/x', 'B@handle', 'x');

        $this->assertNotSame($a->fingerprint(), $b->fingerprint());
    }

    public function testCompileCachesResult(): void
    {
        $collection = $this->buildSample();
        $first = $collection->compile();
        $second = $collection->compile();

        $this->assertSame($first, $second);
        $this->assertInstanceOf(CompiledRoutes::class, $first);
    }

    public function testCompileInvalidatedAfterAdd(): void
    {
        $collection = $this->buildSample();
        $first = $collection->compile();
        $collection->addRoute(['GET'], '/new', 'N@handle');
        $second = $collection->compile();

        $this->assertNotSame($first, $second);
    }

    public function testMergeAppliesPrefixAndMiddleware(): void
    {
        $sub = new RouteCollection();
        $sub->addRoute(['GET'], '/users', 'U@index', 'u.index');

        $main = new RouteCollection();
        $main->merge($sub, '/api', ['auth']);

        $route = $main->getByName('u.index');
        $this->assertNotNull($route);
        $this->assertSame('/api/users', $route->getPath());
        $this->assertSame(['auth'], $route->getMiddlewares());
    }

    public function testMergeDuplicateNameThrows(): void
    {
        $sub = new RouteCollection();
        $sub->addRoute(['GET'], '/x', 'X@h', 'dup');

        $main = new RouteCollection();
        $main->addRoute(['GET'], '/y', 'Y@h', 'dup');

        $this->expectException(InvalidRouteException::class);
        $main->merge($sub);
    }

    public function testClear(): void
    {
        $collection = $this->buildSample();
        $collection->clear();

        $this->assertCount(0, $collection);
        $this->assertFalse($collection->hasName('a'));
    }

    private function buildSample(): RouteCollection
    {
        $collection = new RouteCollection();
        $collection->addRoute(['GET'], '/users/{id}', 'User@show', 'user.show');
        $collection->addRoute(['GET'], '/posts', 'Post@index', 'post.index');

        return $collection;
    }
}
