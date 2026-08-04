<?php

namespace Kode\Router\Tests\Distributed;

use Kode\Router\CompiledRoutes;
use Kode\Router\Distributed\RouteManifest;
use Kode\Router\RouteCollection;
use PHPUnit\Framework\TestCase;

class RouteManifestTest extends TestCase
{
    private function compiled(): CompiledRoutes
    {
        $collection = new RouteCollection();
        $collection->addRoute(['GET'], '/users/{id}', 'User@show', 'user.show');
        $collection->addRoute(['GET'], '/posts', 'Post@index', 'post.index');

        return CompiledRoutes::fromCollection($collection);
    }

    public function testFromCompiled(): void
    {
        $compiled = $this->compiled();
        $manifest = RouteManifest::fromCompiled($compiled, '2.0.0', 'node-1');

        $this->assertSame($compiled->getFingerprint(), $manifest->getFingerprint());
        $this->assertSame('2.0.0', $manifest->getVersion());
        $this->assertSame('node-1', $manifest->getNode());
        $this->assertSame(2, $manifest->getRouteCount());
        $this->assertSame(16, strlen($manifest->getShortFingerprint()));
    }

    public function testFromHeaders(): void
    {
        $compiled = $this->compiled();
        $manifest = RouteManifest::fromCompiled($compiled, '2.0.0', 'node-1');

        $fromHeaders = RouteManifest::fromHeaders($manifest->toHeaders());
        $this->assertNotNull($fromHeaders);
        $this->assertSame($manifest->getFingerprint(), $fromHeaders->getFingerprint());
    }

    public function testFromHeadersMissingFingerprintReturnsNull(): void
    {
        $this->assertNull(RouteManifest::fromHeaders([]));
    }

    public function testMatchesByFingerprint(): void
    {
        $compiled = $this->compiled();
        $a = RouteManifest::fromCompiled($compiled, '2.0.0', 'node-1');
        $b = RouteManifest::fromCompiled($compiled, '9.9.9', 'node-2'); // 不同版本/节点，但同指纹

        $this->assertTrue($a->matches($b));
        $this->assertTrue($b->matchesCompiled($compiled));
        $this->assertTrue($a->matchesCompiled($compiled));
    }

    public function testMismatchDifferentFingerprint(): void
    {
        $a = RouteManifest::fromCompiled($this->compiled(), '1', 'n1');
        $other = new RouteCollection();
        $other->addRoute(['GET'], '/different', 'X@h', 'x');
        $compiledB = CompiledRoutes::fromCollection($other);
        $b = RouteManifest::fromCompiled($compiledB, '1', 'n1');

        $this->assertFalse($a->matches($b));
        $this->assertFalse($a->matchesCompiled($compiledB));
    }

    public function testToHeadersIncludesOptionalFields(): void
    {
        $manifest = RouteManifest::fromCompiled($this->compiled(), '2.0.0', 'node-1');
        $headers = $manifest->toHeaders();

        $this->assertArrayHasKey(RouteManifest::HEADER_FINGERPRINT, $headers);
        $this->assertArrayHasKey(RouteManifest::HEADER_VERSION, $headers);
        $this->assertArrayHasKey(RouteManifest::HEADER_NODE, $headers);
        $this->assertSame($manifest->getFingerprint(), $headers[RouteManifest::HEADER_FINGERPRINT]);
    }

    public function testArrayRoundTrip(): void
    {
        $compiled = $this->compiled();
        $manifest = RouteManifest::fromCompiled($compiled, '2.0.0', 'node-1');

        $restored = RouteManifest::fromArray($manifest->toArray());
        $this->assertSame($manifest->getFingerprint(), $restored->getFingerprint());
        $this->assertSame('2.0.0', $restored->getVersion());
        $this->assertSame('node-1', $restored->getNode());
        $this->assertSame(2, $restored->getRouteCount());
    }
}
