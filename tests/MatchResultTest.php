<?php

namespace Kode\Router\Tests;

use Kode\Router\MatchResult;
use PHPUnit\Framework\TestCase;

class MatchResultTest extends TestCase
{
    public function testFoundResult(): void
    {
        $handler = fn () => 'ok';
        $result = MatchResult::found($handler, ['id' => '42'], ['auth'], 'user.show', '/users/{id}', 7);

        $this->assertTrue($result->isFound());
        $this->assertFalse($result->isNotFound());
        $this->assertFalse($result->isMethodNotAllowed());
        $this->assertSame(MatchResult::FOUND, $result->getStatus());
        $this->assertSame($handler, $result->getHandler());
        $this->assertSame(['id' => '42'], $result->getParams());
        $this->assertSame(['auth'], $result->getMiddleware());
        $this->assertSame('user.show', $result->getName());
        $this->assertSame('/users/{id}', $result->getPath());
        $this->assertSame(7, $result->getRouteId());
        $this->assertSame('42', $result->getParam('id'));
        $this->assertNull($result->getParam('missing'));
        $this->assertSame('fallback', $result->getParam('missing', 'fallback'));
    }

    public function testNotFoundResult(): void
    {
        $result = MatchResult::notFound();

        $this->assertTrue($result->isNotFound());
        $this->assertSame(MatchResult::NOT_FOUND, $result->getStatus());
        $this->assertNull($result->getHandler());
        $this->assertSame([], $result->getAllowedMethods());
    }

    public function testMethodNotAllowedNormalizesMethods(): void
    {
        $result = MatchResult::methodNotAllowed(['get', 'post', 'GET']);

        $this->assertTrue($result->isMethodNotAllowed());
        $this->assertSame(['GET', 'POST'], $result->getAllowedMethods());
        $this->assertSame(MatchResult::METHOD_NOT_ALLOWED, $result->getStatus());
    }

    public function testImmutabilityViaWithParams(): void
    {
        $original = MatchResult::found('h', ['a' => '1'], [], 'n');
        $derived = $original->withParams(['a' => '2', 'b' => '3']);

        $this->assertNotSame($original, $derived);
        $this->assertSame(['a' => '1'], $original->getParams());
        $this->assertSame(['a' => '2', 'b' => '3'], $derived->getParams());
    }

    public function testImmutabilityViaWithMiddleware(): void
    {
        $original = MatchResult::found('h', [], ['a'], 'n');
        $derived = $original->withMiddleware(['b', 'c']);

        $this->assertSame(['a'], $original->getMiddleware());
        $this->assertSame(['b', 'c'], $derived->getMiddleware());
    }

    public function testImmutabilityViaWithHandler(): void
    {
        $original = MatchResult::found('h1');
        $derived = $original->withHandler('h2');

        $this->assertSame('h1', $original->getHandler());
        $this->assertSame('h2', $derived->getHandler());
    }

    public function testToArrayObfuscatesClosures(): void
    {
        $result = MatchResult::found(fn () => 'x', ['id' => '5'], ['m'], 'r', '/p', 3);
        $array = $result->toArray();

        $this->assertSame('Closure', $array['handler']);
        $this->assertSame(['id' => '5'], $array['params']);
        $this->assertSame('r', $array['name']);
        $this->assertSame(3, $array['route_id']);
        $this->assertSame('/p', $array['path']);
    }
}
