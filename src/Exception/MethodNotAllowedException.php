<?php

declare(strict_types=1);

namespace Kode\Router\Exception;

/**
 * HTTP 方法不允许异常
 *
 * 路径匹配成功但 HTTP 方法不在允许列表中时抛出，HTTP 状态码固定为 405。
 * 异常携带 Allow 列表，调用方应据此写出 `Allow` 响应头。
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
class MethodNotAllowedException extends RouterException
{
    /** @var list<string> 该路径支持的 HTTP 方法列表 */
    private array $allowedMethods;

    /**
     * @param string $method         实际请求使用的 HTTP 方法
     * @param array<int, string> $allowedMethods 该路径允许的方法列表
     * @param string $path           请求路径
     */
    public function __construct(
        private readonly string $method = '',
        array $allowedMethods = [],
        private readonly string $path = ''
    ) {
        $this->allowedMethods = array_values(array_unique(array_map(
            static fn (string $m): string => strtoupper($m),
            $allowedMethods
        )));

        parent::__construct("不允许的 HTTP 方法: {$method}", 405);
    }

    /**
     * 获取该路径允许的 HTTP 方法列表
     *
     * @return list<string> 允许的方法列表
     */
    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }

    /**
     * 获取可直接写入响应的 Allow 头值
     *
     * @return string 逗号分隔的方法列表
     */
    public function getAllowHeader(): string
    {
        return implode(', ', $this->allowedMethods);
    }

    /**
     * 获取实际请求使用的 HTTP 方法
     *
     * @return string HTTP 方法
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * 获取请求路径
     *
     * @return string 请求路径
     */
    public function getPath(): string
    {
        return $this->path;
    }
}
