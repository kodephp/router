<?php

namespace Kode\Router\Tests;

use FastRoute\Dispatcher as FastRouteDispatcher;
use Kode\Router\Dispatcher;
use Kode\Router\Exception\MethodNotAllowedException;
use Kode\Router\Exception\RouteNotFoundException;
use Kode\Router\Router;
use PHPUnit\Framework\TestCase;

class DispatcherTest extends TestCase
{
    private function compiledDispatcher(): Dispatcher
    {
        $router = new Router();
        $router->get('/users/{id}', fn (string $id) => "user:$id", 'user.show');
        $router->post('/users', fn () => 'created');
        $router->get('/posts[/{page}]', fn (array $p) => $p, 'posts')->defaults(['page' => 1]);

        return $router->createDispatcher();
    }

    public function testMatchFound(): void
    {
        $dispatcher = $this->compiledDispatcher();
        $result = $dispatcher->match('GET', '/users/42');

        $this->assertTrue($result->isFound());
        $this->assertSame('42', $result->getParam('id'));
        $this->assertSame('user.show', $result->getName());
    }

    public function testMatchNotFound(): void
    {
        $dispatcher = $this->compiledDispatcher();
        $result = $dispatcher->match('GET', '/missing');

        $this->assertTrue($result->isNotFound());
    }

    public function testMatchMethodNotAllowed(): void
    {
        $dispatcher = $this->compiledDispatcher();
        $result = $dispatcher->match('DELETE', '/users/42');

        $this->assertTrue($result->isMethodNotAllowed());
        $this->assertContains('GET', $result->getAllowedMethods());
    }

    public function testHandleReturnsHandlerResult(): void
    {
        $dispatcher = $this->compiledDispatcher();
        $this->assertSame('user:7', $dispatcher->handle('GET', '/users/7'));
    }

    public function testHandleThrowsOnNotFound(): void
    {
        $dispatcher = $this->compiledDispatcher();

        $this->expectException(RouteNotFoundException::class);
        $dispatcher->handle('GET', '/nope');
    }

    public function testHandleThrowsOnMethodNotAllowed(): void
    {
        $dispatcher = $this->compiledDispatcher();

        $this->expectException(MethodNotAllowedException::class);
        try {
            $dispatcher->handle('DELETE', '/users/42');
        } catch (MethodNotAllowedException $e) {
            $this->assertStringContainsString('GET', $e->getAllowHeader());
            throw $e;
        }
    }

    public function testExecuteIsAliasOfHandle(): void
    {
        $dispatcher = $this->compiledDispatcher();
        $this->assertSame('user:9', $dispatcher->execute('GET', '/users/9'));
    }

    public function testDispatchTriple(): void
    {
        $dispatcher = $this->compiledDispatcher();
        $found = $dispatcher->dispatch('GET', '/users/1');
        $this->assertSame(FastRouteDispatcher::FOUND, $found[0]); // FastRoute FOUND = 1
        $this->assertSame('1', $found[2]['id'] ?? null);

        $notFound = $dispatcher->dispatch('GET', '/zzz');
        $this->assertSame(FastRouteDispatcher::NOT_FOUND, $notFound[0]); // FastRoute NOT_FOUND = 0
    }

    public function testDefaultsMergedOnMatch(): void
    {
        $dispatcher = $this->compiledDispatcher();
        $result = $dispatcher->match('GET', '/posts');

        $this->assertTrue($result->isFound());
        $this->assertSame(1, $result->getParam('page'));
    }

    public function testQueryStringStripped(): void
    {
        $dispatcher = $this->compiledDispatcher();
        $result = $dispatcher->match('GET', '/users/5?foo=bar&baz=1');

        $this->assertTrue($result->isFound());
        $this->assertSame('5', $result->getParam('id'));
    }

    public function testUrlGenerationViaDispatcher(): void
    {
        $dispatcher = $this->compiledDispatcher();
        $this->assertSame('/users/11', $dispatcher->url('user.show', ['id' => 11]));
    }

    public function testFingerprintAvailable(): void
    {
        $dispatcher = $this->compiledDispatcher();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $dispatcher->getFingerprint());
        $this->assertNotNull($dispatcher->getCompiled());
    }

    public function testLegacyRouteCollectorMode(): void
    {
        $router = new Router();
        $router->get('/legacy/{id}', fn (string $id) => "legacy:$id", 'legacy.show');

        $dispatcher = new Dispatcher($router->getRouteCollector(), $router->resolveAll());

        $this->assertSame('legacy:3', $dispatcher->handle('GET', '/legacy/3'));
        $this->assertNull($dispatcher->getCompiled());
        $this->assertSame('', $dispatcher->getFingerprint());
        $this->assertSame('/legacy/3', $dispatcher->url('legacy.show', ['id' => 3]));
    }
}
