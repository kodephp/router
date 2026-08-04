<?php

namespace Kode\Router\Tests;

use Kode\Router\Application;
use Kode\Router\Request;
use Kode\Router\Response;
use PHPUnit\Framework\TestCase;

class ApplicationTest extends TestCase
{
    public function testBasicRouting()
    {
        $app = new Application();
        $app->get('/hello', function () {
            return 'Hello, World!';
        }, 'hello');

        $request = new Request('GET', '/hello');
        $response = $app->run($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Hello, World!', $response->getContent());
    }

    public function testJsonResponse()
    {
        $app = new Application();
        $app->get('/status', function () {
            return ['status' => 'ok'];
        }, 'status');

        $request = new Request('GET', '/status');
        $response = $app->run($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('"status":"ok"', $response->getContent());
    }

    public function testNotFound()
    {
        $app = new Application();

        $request = new Request('GET', '/not-found');
        $response = $app->run($request);

        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testMethodNotAllowed()
    {
        $app = new Application();
        $app->get('/hello', function () {
            return 'hello';
        }, 'hello');

        $request = new Request('POST', '/hello');
        $response = $app->run($request);

        // FastRoute 返回 METHOD_NOT_ALLOWED
        $this->assertEquals(405, $response->getStatusCode());
    }

    public function testMiddleware()
    {
        $app = new Application();
        $app->middleware(function (Request $request, Response $response) {
            $response->withHeader('X-Middleware', 'test');
        });
        $app->get('/hello', function () {
            return 'hello';
        });

        $request = new Request('GET', '/hello');
        $response = $app->run($request);

        $this->assertEquals('test', $response->getHeader('X-Middleware'));
    }

    public function testResponseObject()
    {
        $app = new Application();
        $app->get('/custom', function () {
            return Response::json(['status' => 'ok']);
        }, 'custom');

        $request = new Request('GET', '/custom');
        $response = $app->run($request);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('"status":"ok"', $response->getContent());
    }
}
