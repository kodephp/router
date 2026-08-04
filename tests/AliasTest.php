<?php

declare(strict_types=1);

namespace Kode\Router\Tests;

use Kode\Router\Exception\InvalidRouteException;
use Kode\Router\Router;
use Kode\Router\UrlGenerator;
use PHPUnit\Framework\TestCase;

/**
 * 别名指向测试：路由自身别名、集合级别名、URL 生成、命名解析、指纹一致性。
 */
final class AliasTest extends TestCase
{
    public function testRouteAliasGeneratesSameUrl(): void
    {
        $router = new Router();
        $router->get('/users/{id}', static fn ($id) => "user:$id")
            ->name('user.show')
            ->alias('profile');

        $generator = UrlGenerator::fromCompiled($router->compile());

        $this->assertSame('/users/1', $generator->generate('user.show', ['id' => 1]));
        $this->assertSame('/users/1', $generator->generate('profile', ['id' => 1]));
    }

    public function testRouterAlias(): void
    {
        $router = new Router();
        $router->get('/posts', static fn () => 'list')->name('post.index');
        $router->alias('articles', 'post.index');

        $generator = $router->createUrlGenerator();

        $this->assertSame('/posts', $generator->generate('articles'));
    }

    public function testAliasResolvesPreCompile(): void
    {
        $router = new Router();
        $router->get('/p', static fn () => 'p')->name('page');
        $router->alias('home', 'page');

        $this->assertSame('/p', $router->resolveRoute('home'));
        $this->assertArrayHasKey('home', $router->resolveAll());
    }

    public function testAliasConflictWithRouteNameThrows(): void
    {
        $router = new Router();
        $router->get('/a', static fn () => 'a')->name('a');
        $router->get('/b', static fn () => 'b')->name('b');

        $this->expectException(InvalidRouteException::class);
        $router->alias('a', 'b');
    }

    public function testAliasToMissingTargetThrows(): void
    {
        $router = new Router();

        $this->expectException(InvalidRouteException::class);
        $router->alias('x', 'nonexistent');
    }

    public function testFingerprintChangesWithAlias(): void
    {
        $a = new Router();
        $a->get('/x', static fn () => 'h')->name('x');

        $b = new Router();
        $b->get('/x', static fn () => 'h')->name('x');
        $b->alias('y', 'x');

        $this->assertNotSame($a->fingerprint(), $b->fingerprint());
    }

    public function testCompiledNamedIncludesAlias(): void
    {
        $router = new Router();
        $router->get('/u/{id}', static fn ($id) => $id)->name('user')->alias('me');

        $compiled = $router->compile();

        $this->assertArrayHasKey('me', $compiled->getNamed());
        $this->assertNotNull($compiled->getRouteByName('me'));
    }
}
