<?php

namespace Kode\Router\Tests\Integration;

use Kode\Router\Concurrency\RouteContext;
use Kode\Router\Dispatcher;
use Kode\Router\Distributed\RouteManifest;
use Kode\Router\Integration\RoutingMiddleware;
use Kode\Router\Router;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

class RoutingMiddlewareTest extends TestCase
{
    protected function setUp(): void
    {
        RouteContext::flush();
    }

    protected function tearDown(): void
    {
        RouteContext::flush();
    }

    private function dispatcher(): Dispatcher
    {
        $router = new Router();
        $router->get('/users/{id}', fn (string $id) => "user:$id", 'user.show');

        return $router->createDispatcher();
    }

    /**
     * 下游处理器：把收到的请求（携带路由属性）存进 holder，再返回固定响应。
     * 因为 PSR-7 消息是不可变的，$request->withAttribute() 会返回新对象，
     * 所以必须从"中间件实际传给下游的那个请求"上读取属性。
     */
    private function handlerRecording(object $holder, ResponseInterface $response): RequestHandlerInterface
    {
        return new class ($holder, $response) implements RequestHandlerInterface {
            public function __construct(private object $holder, private readonly ResponseInterface $response)
            {
            }

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->holder->request = $request;

                return $this->response;
            }
        };
    }

    public function testWritesMatchAttributeAndExposesParams(): void
    {
        $holder = new \stdClass();
        $middleware = new RoutingMiddleware($this->dispatcher());
        $request = new ServerRequest('GET', '/users/123');
        $middleware->process($request, $this->handlerRecording($holder, new Response(200)));

        $downstream = $holder->request;
        $match = $downstream->getAttribute(RoutingMiddleware::ATTRIBUTE);
        $this->assertNotNull($match);
        $this->assertSame('123', $downstream->getAttribute('id'));
    }

    public function testDoesNotExposeParamsWhenDisabled(): void
    {
        $holder = new \stdClass();
        $middleware = new RoutingMiddleware($this->dispatcher(), false);
        $request = new ServerRequest('GET', '/users/123');
        $middleware->process($request, $this->handlerRecording($holder, new Response(200)));

        $downstream = $holder->request;
        $this->assertNull($downstream->getAttribute('id'));
        $this->assertNotNull($downstream->getAttribute(RoutingMiddleware::ATTRIBUTE));
    }

    public function testContextClearedAfterProcess(): void
    {
        $holder = new \stdClass();
        $middleware = new RoutingMiddleware($this->dispatcher());
        $request = new ServerRequest('GET', '/users/123');
        $middleware->process($request, $this->handlerRecording($holder, new Response(200)));

        // RouteContext::run 应在请求结束后清除上下文
        $this->assertFalse(RouteContext::has());
    }

    public function testManifestHeadersAdded(): void
    {
        $holder = new \stdClass();
        $manifest = RouteManifest::fromCompiled($this->dispatcher()->getCompiled(), '2.0.0', 'node-1');
        $middleware = new RoutingMiddleware($this->dispatcher(), true, $manifest);

        $request = new ServerRequest('GET', '/users/123');
        $response = $middleware->process($request, $this->handlerRecording($holder, new Response(200)));

        $this->assertTrue($response->hasHeader(RouteManifest::HEADER_FINGERPRINT));
        $this->assertTrue($response->hasHeader(RouteManifest::HEADER_VERSION));
        $this->assertTrue($response->hasHeader(RouteManifest::HEADER_NODE));
    }

    public function testMethodNotAllowedAddsAllowHeader(): void
    {
        $holder = new \stdClass();
        $middleware = new RoutingMiddleware($this->dispatcher());
        $request = new ServerRequest('POST', '/users/123');
        $response = $middleware->process($request, $this->handlerRecording($holder, new Response(200)));

        $this->assertTrue($response->hasHeader('Allow'));
        $this->assertStringContainsString('GET', $response->getHeaderLine('Allow'));
    }

    public function testFromRequestReadsAttribute(): void
    {
        $holder = new \stdClass();
        $middleware = new RoutingMiddleware($this->dispatcher());
        $request = new ServerRequest('GET', '/users/123');
        $middleware->process($request, $this->handlerRecording($holder, new Response(200)));

        $match = RoutingMiddleware::fromRequest($holder->request);
        $this->assertNotNull($match);
        $this->assertSame('user.show', $match->getName());
    }
}
