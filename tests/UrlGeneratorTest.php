<?php

namespace Kode\Router\Tests;

use Kode\Router\Exception\UrlGenerationException;
use Kode\Router\UrlGenerator;
use PHPUnit\Framework\TestCase;

class UrlGeneratorTest extends TestCase
{
    public function testGenerateSimpleRoute(): void
    {
        $gen = new UrlGenerator(['user.show' => '/users/{id}']);
        $this->assertSame('/users/123', $gen->generate('user.show', ['id' => 123]));
    }

    public function testGenerateWithBasePath(): void
    {
        $gen = new UrlGenerator(['user.show' => '/users/{id}'], '/app');
        $this->assertSame('/app/users/123', $gen->generate('user.show', ['id' => 123]));
    }

    public function testOptionalSegmentShortVariant(): void
    {
        $gen = new UrlGenerator(['posts' => '/posts[/{page}]']);
        $this->assertSame('/posts', $gen->generate('posts'));
        $this->assertSame('/posts/2', $gen->generate('posts', ['page' => 2]));
    }

    public function testRegexConstraintValidated(): void
    {
        $gen = new UrlGenerator(['user' => '/users/{id:\d+}']);
        $this->assertSame('/users/42', $gen->generate('user', ['id' => 42]));

        $this->expectException(UrlGenerationException::class);
        $gen->generate('user', ['id' => 'abc']);
    }

    public function testExtraParamsBecomeQueryString(): void
    {
        $gen = new UrlGenerator(['user' => '/users/{id}']);
        $this->assertSame('/users/5?ref=home', $gen->generate('user', ['id' => 5, 'ref' => 'home']));
    }

    public function testExplicitQueryParams(): void
    {
        $gen = new UrlGenerator(['user' => '/users/{id}']);
        $this->assertSame('/users/5?sort=desc', $gen->generate('user', ['id' => 5], ['sort' => 'desc']));
    }

    public function testMissingRequiredParamThrows(): void
    {
        $gen = new UrlGenerator(['user' => '/users/{id}']);

        $this->expectException(UrlGenerationException::class);
        $gen->generate('user');
    }

    public function testUnknownRouteThrows(): void
    {
        $gen = new UrlGenerator(['user' => '/users/{id}']);

        $this->expectException(UrlGenerationException::class);
        $gen->generate('nope', ['id' => 1]);
    }

    public function testHasAndAll(): void
    {
        $gen = new UrlGenerator(['a' => '/a', 'b' => '/b']);
        $this->assertTrue($gen->has('a'));
        $this->assertFalse($gen->has('z'));
        $this->assertSame(['a' => '/a', 'b' => '/b'], $gen->all());
    }

    public function testUrlEncoding(): void
    {
        $gen = new UrlGenerator(['s' => '/search/{q}']);
        $this->assertSame('/search/hello%20world', $gen->generate('s', ['q' => 'hello world']));
    }
}
