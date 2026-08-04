<?php

namespace Kode\Router\Tests;

use Kode\Router\Exception\InvalidRouteException;
use Kode\Router\HandlerResolver;
use Kode\Router\Tests\Fixtures\InvokableController;
use Kode\Router\Tests\Fixtures\NeedsDependency;
use Kode\Router\Tests\Fixtures\UserController;
use PHPUnit\Framework\TestCase;

class HandlerResolverTest extends TestCase
{
    public function testResolveClassAtMethod(): void
    {
        $resolver = new HandlerResolver();
        $callable = $resolver->resolve(UserController::class . '@show');

        $this->assertSame('user:42', $callable('42'));
    }

    public function testResolveStaticMethod(): void
    {
        $resolver = new HandlerResolver();
        $callable = $resolver->resolve(UserController::class . '::statik');

        $this->assertSame('static:7', $callable('7'));
    }

    public function testResolveArrayForm(): void
    {
        $resolver = new HandlerResolver();
        $callable = $resolver->resolve([UserController::class, 'reversed']);

        $this->assertSame('a#2', $callable('2', 'a'));
    }

    public function testNameBindingOverridesPosition(): void
    {
        $resolver = new HandlerResolver();
        // 形参顺序与路径参数顺序相反，必须按名字绑定
        $callable = $resolver->resolve([UserController::class, 'reversed']);

        $this->assertSame('a#2', $resolver->call([UserController::class, 'reversed'], ['slug' => 'a', 'page' => '2']));
    }

    public function testResolveInvokableClass(): void
    {
        $resolver = new HandlerResolver();
        $callable = $resolver->resolve(InvokableController::class);

        $this->assertSame('invoke:9', $callable('9'));
    }

    public function testResolveClosureReturnsSame(): void
    {
        $resolver = new HandlerResolver();
        $closure = fn (string $x) => "c:$x";

        $this->assertSame($closure, $resolver->resolve($closure));
        $this->assertSame('c:z', $resolver->call($closure, ['x' => 'z']));
    }

    public function testRequiredConstructorDependencyWithoutContainerThrows(): void
    {
        $resolver = new HandlerResolver();

        $this->expectException(InvalidRouteException::class);
        $resolver->resolve(NeedsDependency::class . '@handle');
    }

    public function testContainerResolution(): void
    {
        $container = new class {
            public function has(string $id): bool
            {
                return $id === NeedsDependency::class;
            }

            public function get(string $id): object
            {
                return new NeedsDependency('from-container');
            }
        };

        $resolver = new HandlerResolver($container);
        $callable = $resolver->resolve(NeedsDependency::class . '@handle');

        $this->assertSame('from-container', $callable());
    }

    public function testUnknownClassThrows(): void
    {
        $resolver = new HandlerResolver();

        $this->expectException(InvalidRouteException::class);
        $resolver->resolve('App\\NoSuchClass@handle');
    }

    public function testIsStatic(): void
    {
        $this->assertTrue(HandlerResolver::isStatic(UserController::class . '@show'));
        $this->assertTrue(HandlerResolver::isStatic([UserController::class, 'show']));
        $this->assertFalse(HandlerResolver::isStatic(fn () => null));
        $this->assertFalse(HandlerResolver::isStatic([new UserController(), 'show']));
    }
}
