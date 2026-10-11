<?php

declare(strict_types=1);

namespace Kode\Router;

/**
 * 响应对象
 *
 * 封装 HTTP 响应信息，提供状态码和内容的设置。
 */
final class Response
{
    /**
     * @var int HTTP 状态码
     */
    private int $statusCode = 200;

    /**
     * @var array<string, string> HTTP 响应头
     */
    private array $headers = [];

    /**
     * @var mixed 响应内容
     */
    private $content;

    /**
     * 构造函数
     *
     * @param mixed               $content    响应内容
     * @param int                 $statusCode HTTP 状态码
     * @param array<string, string> $headers  响应头
     */
    public function __construct($content = null, int $statusCode = 200, array $headers = [])
    {
        $this->content    = $content;
        $this->statusCode = $statusCode;
        $this->headers    = $headers;
    }

    /**
     * 获取 HTTP 状态码
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * 设置 HTTP 状态码
     *
     * @param int $statusCode HTTP 状态码
     * @return self
     */
    public function withStatus(int $statusCode): self
    {
        $this->statusCode = $statusCode;
        return $this;
    }

    /**
     * 获取响应头
     *
     * @param string|null $key 头键（可选）
     * @return mixed
     */
    public function getHeader(?string $key = null)
    {
        if ($key === null) {
            return $this->headers;
        }

        // 尝试直接匹配和转小写匹配
        return $this->headers[$key] ?? $this->headers[strtolower($key)] ?? null;
    }

    /**
     * 设置响应头
     *
     * @param string $key   头键
     * @param string $value 头值
     * @return self
     */
    public function withHeader(string $key, string $value): self
    {
        $this->headers[$key] = $value;
        return $this;
    }

    /**
     * 获取响应内容
     *
     * @return mixed
     */
    public function getContent()
    {
        return $this->content;
    }

    /**
     * 发送响应
     *
     * @return void
     */
    public function send(): void
    {
        // 发送状态码
        http_response_code($this->statusCode);

        // 发送响应头
        foreach ($this->headers as $key => $value) {
            header("{$key}: {$value}");
        }

        // 发送响应内容
        if ($this->content !== null) {
            echo $this->content;
        }
    }

    /**
     * 创建 JSON 响应
     *
     * @param mixed $data     响应数据
     * @param int   $statusCode HTTP 状态码
     * @return static
     */
    public static function json($data, int $statusCode = 200): self
    {
        $content = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new static(
            $content,
            $statusCode,
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    /**
     * 创建 HTML 响应
     *
     * @param string $html     HTML 内容
     * @param int    $statusCode HTTP 状态码
     * @return static
     */
    public static function html(string $html, int $statusCode = 200): self
    {
        return new static(
            $html,
            $statusCode,
            ['Content-Type' => 'text/html; charset=utf-8']
        );
    }

    /**
     * 创建纯文本响应
     *
     * @param string $text     文本内容
     * @param int    $statusCode HTTP 状态码
     * @return static
     */
    public static function text(string $text, int $statusCode = 200): self
    {
        return new static(
            $text,
            $statusCode,
            ['Content-Type' => 'text/plain; charset=utf-8']
        );
    }

    /**
     * 创建重定向响应
     *
     * @param string $url      重定向 URL
     * @param int    $statusCode HTTP 状态码（301 或 302）
     * @return static
     */
    public static function redirect(string $url, int $statusCode = 302): self
    {
        return new static(
            null,
            $statusCode,
            ['Location' => $url]
        );
    }
}
