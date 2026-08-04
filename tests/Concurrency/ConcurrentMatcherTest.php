<?php

namespace Kode\Router\Tests\Concurrency;

use Kode\Router\Concurrency\ConcurrentMatcher;
use Kode\Router\Concurrency\Runtime;
use Kode\Router\Dispatcher;
use Kode\Router\Router;
use PHPUnit\Framework\TestCase;

class ConcurrentMatcherTest extends TestCase
{
    private function dispatcher(): Dispatcher
    {
        $router = new Router();
        $router->get('/users/{id}', fn (string $id) => "user:$id", 'user.show');
        $router->get('/posts', fn () => 'posts');
        $router->get('/boom', static function (): string {
            throw new \RuntimeException('boom');
        });

        return $router->createDispatcher();
    }

    public function testMatchAllIsKeyed(): void
    {
        $matcher = new ConcurrentMatcher($this->dispatcher());
        $results = $matcher->matchAll([
            'a' => ['GET', '/users/1'],
            'b' => ['GET', '/missing'],
        ]);

        $this->assertArrayHasKey('a', $results);
        $this->assertArrayHasKey('b', $results);
        $this->assertTrue($results['a']->isFound());
        $this->assertTrue($results['b']->isNotFound());
    }

    public function testHandleAllReturnsResults(): void
    {
        $matcher = new ConcurrentMatcher($this->dispatcher());
        $results = $matcher->handleAll([['GET', '/users/1'], ['GET', '/posts']]);

        $this->assertSame('user:1', $results[0]);
        $this->assertSame('posts', $results[1]);
    }

    public function testHandleAllCaptureErrorsWrapsException(): void
    {
        $matcher = new ConcurrentMatcher($this->dispatcher());
        $results = $matcher->handleAll([['GET', '/boom']], 0, true);

        $this->assertInstanceOf(\Throwable::class, $results[0]);
        $this->assertSame('boom', $results[0]->getMessage());
    }

    public function testHandleAllThrowsWithoutCapture(): void
    {
        $matcher = new ConcurrentMatcher($this->dispatcher());

        $this->expectException(\RuntimeException::class);
        $matcher->handleAll([['GET', '/boom']]);
    }

    public function testHandleAllWithConcurrencyChunks(): void
    {
        $matcher = new ConcurrentMatcher($this->dispatcher());
        $requests = [
            ['GET', '/users/1'],
            ['GET', '/users/2'],
            ['GET', '/users/3'],
            ['GET', '/users/4'],
            ['GET', '/users/5'],
        ];
        $results = $matcher->handleAll($requests, 2);

        $this->assertSame(['user:1', 'user:2', 'user:3', 'user:4', 'user:5'], array_values($results));
    }

    public function testMapConcurrently(): void
    {
        $matcher = new ConcurrentMatcher($this->dispatcher());
        $names = $matcher->mapConcurrently(
            [['GET', '/users/1'], ['GET', '/posts']],
            static fn ($result) => $result->isFound() ? $result->getName() : 'none'
        );

        $this->assertSame(['user.show', null], array_values($names));
    }

    public function testRunTasksStatic(): void
    {
        $results = ConcurrentMatcher::runTasks([
            'x' => static fn () => 10,
            'y' => static fn () => 20,
        ]);

        $this->assertSame(['x' => 10, 'y' => 20], $results);
    }

    public function testFiberDrainingWithSuspendingHandler(): void
    {
        if (!Runtime::supportsFibers()) {
            $this->markTestSkipped('Fibers 不可用');
        }

        $router = new Router();
        $router->get('/slow', static function (): string {
            $signal = \Fiber::suspend('paused');
            return 'resumed:' . $signal;
        });

        $matcher = new ConcurrentMatcher($router->createDispatcher());
        $results = $matcher->handleAll([['GET', '/slow'], ['GET', '/slow']]);

        $this->assertSame(['resumed:', 'resumed:'], array_values($results));
    }
}
