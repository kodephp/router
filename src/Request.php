<?php

declare(strict_types=1);

namespace Kode\Router;

/**
 * 请求对象
 *
 * 封装 HTTP 请求信息，提供请求方法和路径的访问。
 */
final class Request
{
    /**
     * @var string HTTP 方法，例如 "GET"、"POST"
     */
    private string $method;

    /**
     * @var string 请求路径，例如 "/users/123"
     */
    private string $path;

    /**
     * @var array<string, mixed> 请求参数（GET/POST）
     */
    private array $params = [];

    /**
     * @var array<string, mixed> HTTP 请求头
     */
    private array $headers = [];

    /**
     * 构造函数
     *
     * @param string              $method  HTTP 方法
     * @param string              $path    请求路径
     * @param array<string, mixed> $params 请求参数
     * @param array<string, mixed> $headers 请求头
     */
    public function __construct(
        string $method,
        string $path,
        array $params = [],
        array $headers = []
    ) {
        $this->method  = strtoupper($method);
        $this->path    = $path;
        $this->params  = $params;
        $this->headers = $headers;
    }

    /**
     * 获取 HTTP 方法
     *
     * @return string
     */
    public function getMethod(): string
    {
        return $this->method;
    }

    /**
     * 获取请求路径
     *
     * @return string
     */
    public function getPath(): string
    {
        return $this->path;
    }

    /**
     * 获取请求参数
     *
     * @param string|null $key    参数键（可选）
     * @param mixed       $default 默认值
     * @return mixed
     */
    public function param(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->params;
        }

        return $this->params[$key] ?? $default;
    }

    /**
     * 获取请求头
     *
     * @param string|null $key    头键（可选）
     * @param mixed       $default 默认值
     * @return mixed
     */
    public function header(?string $key = null, $default = null)
    {
        if ($key === null) {
            return $this->headers;
        }

        // 尝试直接匹配和转小写匹配
        return $this->headers[$key] ?? $this->headers[strtolower($key)] ?? $default;
    }

    /**
     * 创建请求对象（从 superglobals）
     *
     * @return static
     */
    public static function fromGlobals(): self
    {
        $method  = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path    = $_SERVER['REQUEST_URI'] ?? '/';
        $params  = array_merge($_GET, $_POST);
        $headers = [];

        // 提取请求头
        foreach ($_SERVER as $key => $value) {
            if (strncmp($key, 'HTTP_', 5) === 0) {
                $headerName = str_replace('_', '-', substr($key, 5));
                // 同时存储标准格式和原始格式
                $headers[$headerName] = $value;
                $headers[strtolower($headerName)] = $value;
            }
        }
        
        // 也处理非 HTTP_ 前缀的常见头
        $commonHeaders = ['Content-Type', 'Authorization', 'Accept', 'Cache-Control'];
        foreach ($commonHeaders as $header) {
            if (isset($_SERVER[$header])) {
                $headers[$header] = $_SERVER[$header];
                $headers[strtolower($header)] = $_SERVER[$header];
            }
        }

        return new static($method, $path, $params, $headers);
    }
}
