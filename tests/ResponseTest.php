<?php

namespace Kode\Router\Tests;

use Kode\Router\Response;
use PHPUnit\Framework\TestCase;

class ResponseTest extends TestCase
{
    public function testResponseCreation()
    {
        $response = new Response('Hello, World!', 200, ['Content-Type' => 'text/plain']);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Hello, World!', $response->getContent());
        $this->assertEquals('text/plain', $response->getHeader('Content-Type'));
    }

    public function testWithStatus()
    {
        $response = (new Response())->withStatus(404);

        $this->assertEquals(404, $response->getStatusCode());
    }

    public function testWithHeader()
    {
        $response = (new Response())->withHeader('X-Custom', 'value');

        $this->assertEquals('value', $response->getHeader('X-Custom'));
    }

    public function testJsonResponse()
    {
        $response = Response::json(['status' => 'ok']);

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertStringContainsString('"status":"ok"', $response->getContent());
        $this->assertEquals('application/json; charset=utf-8', $response->getHeader('Content-Type'));
    }

    public function testHtmlResponse()
    {
        $response = Response::html('<h1>Hello</h1>');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('<h1>Hello</h1>', $response->getContent());
        $this->assertEquals('text/html; charset=utf-8', $response->getHeader('Content-Type'));
    }

    public function testTextResponse()
    {
        $response = Response::text('Plain text');

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertEquals('Plain text', $response->getContent());
        $this->assertEquals('text/plain; charset=utf-8', $response->getHeader('Content-Type'));
    }

    public function testRedirectResponse()
    {
        $response = Response::redirect('https://example.com', 301);

        $this->assertEquals(301, $response->getStatusCode());
        $this->assertNull($response->getContent());
        $this->assertEquals('https://example.com', $response->getHeader('Location'));
    }
}
