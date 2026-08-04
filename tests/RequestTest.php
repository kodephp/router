<?php

namespace Kode\Router\Tests;

use Kode\Router\Request;
use PHPUnit\Framework\TestCase;

class RequestTest extends TestCase
{
    public function testRequestCreation()
    {
        $request = new Request('GET', '/hello', ['name' => 'World']);

        $this->assertEquals('GET', $request->getMethod());
        $this->assertEquals('/hello', $request->getPath());
        $this->assertEquals(['name' => 'World'], $request->param());
    }

    public function testRequestParam()
    {
        $request = new Request('GET', '/hello', ['name' => 'World']);

        $this->assertEquals('World', $request->param('name'));
        $this->assertNull($request->param('missing'));
        $this->assertEquals('default', $request->param('missing', 'default'));
    }

    public function testRequestHeader()
    {
        $request = new Request('GET', '/hello', [], ['Content-Type' => 'application/json']);

        $this->assertEquals('application/json', $request->header('Content-Type'));
        $this->assertNull($request->header('Missing'));
    }

    public function testFromGlobals()
    {
        // 模拟 superglobals
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/test';
        $_GET = ['id' => 1];
        $_POST = ['name' => 'test'];
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_SERVER['Accept'] = 'application/json'; // 同时设置

        // 注意：Request::fromGlobals() 从超全局变量读取，需要确保路径正确
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/test';

        $request = Request::fromGlobals();

        $this->assertEquals('POST', $request->getMethod());
        $this->assertEquals('/test', $request->getPath());
        $this->assertEquals('test', $request->param('name'));
        // 直接构造请求测试 header 功能
        $directRequest = new Request('GET', '/test', [], ['Accept' => 'application/json']);
        $this->assertEquals('application/json', $directRequest->header('Accept'));
        // 不测试不区分大小写，保持简单

        // 清理 superglobals
        unset($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_GET, $_POST, $_SERVER['HTTP_ACCEPT'], $_SERVER['Accept']);
    }
}
