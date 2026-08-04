<?php

declare(strict_types=1);

namespace Kode\Router\Exception;

/**
 * 路由未找到异常
 *
 * 请求路径没有匹配到任何已注册路由时抛出，HTTP 状态码固定为 404。
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
class RouteNotFoundException extends RouterException
{
    /**
     * @param string $method HTTP 方法
     * @param string $path   请求路径
     */
    public function __construct(
        private readonly string $method = '',
        private readonly string $path = ''
    ) {
        parent::__construct(
            $method === '' && $path === ''
                ? '路由未找到'
                : "路由未找到: {$method} {$path}",
            404
        );
    }

    /**
     * 获取触发异常的 HTTP 方法
     *
     * @return string HTTP 方法
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * 获取触发异常的请求路径
     *
     * @return string 请求路径
     */
    public function getPath(): string
    {
        return $this->path;
    }
}
