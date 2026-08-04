<?php

declare(strict_types=1);

namespace Kode\Router\Distributed;

use Kode\Router\Concurrency\Runtime;

/**
 * W3C 分布式链路上下文
 *
 * 实现 W3C Trace Context 规范的 `traceparent` 头，让一次请求在跨越
 * 多个服务、多个进程之后仍然能被串成一条链路。
 *
 * traceparent 格式：`00-<32位traceId>-<16位spanId>-<2位flags>`
 *
 * 路由层是做这件事最合适的位置：请求刚进来、还没进业务逻辑时就把
 * trace 建立起来，后续中间件、控制器、下游调用全都能拿到。
 *
 * 不可变对象，派生新 span 用 {@see self::child()}。
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class TraceContext
{
    /** @var string W3C 标准请求头名 */
    public const HEADER = 'traceparent';

    /** @var string W3C 标准附加状态头名 */
    public const STATE_HEADER = 'tracestate';

    /** @var string 当前支持的规范版本 */
    public const VERSION = '00';

    /** @var int 采样标志位 */
    public const FLAG_SAMPLED = 0x01;

    /**
     * @param string $traceId 32 位小写十六进制链路 ID
     * @param string $spanId 16 位小写十六进制跨度 ID
     * @param string|null $parentSpanId 父跨度 ID
     * @param bool $sampled 是否采样
     * @param string $state tracestate 原文
     */
    private function __construct(
        private readonly string $traceId,
        private readonly string $spanId,
        private readonly ?string $parentSpanId = null,
        private readonly bool $sampled = true,
        private readonly string $state = ''
    ) {
    }

    /**
     * 开启一条新链路
     *
     * @param bool $sampled 是否采样
     * @return self 链路上下文
     */
    public static function start(bool $sampled = true): self
    {
        return new self(self::randomHex(32), self::randomHex(16), null, $sampled);
    }

    /**
     * 从请求头解析链路上下文
     *
     * 头名大小写不敏感。解析失败（缺失或格式非法）时开启一条新链路，
     * 保证调用方永远能拿到可用的上下文，不必自己判空。
     *
     * @param array<string, mixed> $headers 请求头
     * @return self 链路上下文
     */
    public static function fromHeaders(array $headers): self
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = is_array($value)
                ? (string) reset($value)
                : (string) $value;
        }

        $raw = $normalized[self::HEADER] ?? null;
        $state = $normalized[self::STATE_HEADER] ?? '';

        if (!is_string($raw) || $raw === '') {
            return self::start();
        }

        $parsed = self::parse($raw, $state);

        return $parsed ?? self::start();
    }

    /**
     * 解析 traceparent 字符串
     *
     * @param string $traceparent traceparent 头值
     * @param string $state tracestate 头值
     * @return self|null 链路上下文，格式非法返回 null
     */
    public static function parse(string $traceparent, string $state = ''): ?self
    {
        $parts = explode('-', trim($traceparent));

        if (count($parts) !== 4) {
            return null;
        }

        [$version, $traceId, $spanId, $flags] = $parts;

        if (preg_match('/^[0-9a-f]{2}$/', $version) !== 1
            || preg_match('/^[0-9a-f]{32}$/', $traceId) !== 1
            || preg_match('/^[0-9a-f]{16}$/', $spanId) !== 1
            || preg_match('/^[0-9a-f]{2}$/', $flags) !== 1
        ) {
            return null;
        }

        // 全 0 的 ID 按规范属于非法值
        if ($traceId === str_repeat('0', 32) || $spanId === str_repeat('0', 16)) {
            return null;
        }

        // 上游的 spanId 是本地的 parentSpanId，本地要生成自己的 spanId
        return new self(
            $traceId,
            self::randomHex(16),
            $spanId,
            (hexdec($flags) & self::FLAG_SAMPLED) === self::FLAG_SAMPLED,
            $state
        );
    }

    /**
     * 派生子跨度（保持同一条链路）
     *
     * @return self 新的链路上下文
     */
    public function child(): self
    {
        return new self($this->traceId, self::randomHex(16), $this->spanId, $this->sampled, $this->state);
    }

    /**
     * 获取链路 ID
     *
     * @return string 32 位十六进制
     */
    public function getTraceId(): string
    {
        return $this->traceId;
    }

    /**
     * 获取当前跨度 ID
     *
     * @return string 16 位十六进制
     */
    public function getSpanId(): string
    {
        return $this->spanId;
    }

    /**
     * 获取父跨度 ID
     *
     * @return string|null 16 位十六进制，链路起点返回 null
     */
    public function getParentSpanId(): ?string
    {
        return $this->parentSpanId;
    }

    /**
     * 是否采样
     *
     * @return bool 采样返回 true
     */
    public function isSampled(): bool
    {
        return $this->sampled;
    }

    /**
     * 获取 tracestate 原文
     *
     * @return string tracestate
     */
    public function getState(): string
    {
        return $this->state;
    }

    /**
     * 是否为链路起点
     *
     * @return bool 起点返回 true
     */
    public function isRoot(): bool
    {
        return $this->parentSpanId === null;
    }

    /**
     * 生成 traceparent 头值
     *
     * @return string traceparent
     */
    public function toTraceparent(): string
    {
        return sprintf(
            '%s-%s-%s-%02x',
            self::VERSION,
            $this->traceId,
            $this->spanId,
            $this->sampled ? self::FLAG_SAMPLED : 0
        );
    }

    /**
     * 生成可直接透传给下游的请求头
     *
     * @return array<string, string> 请求头键值对
     */
    public function toHeaders(): array
    {
        $headers = [self::HEADER => $this->toTraceparent()];

        if ($this->state !== '') {
            $headers[self::STATE_HEADER] = $this->state;
        }

        return $headers;
    }

    /**
     * 导出为数组，便于写日志
     *
     * @return array<string, mixed> 数组表示
     */
    public function toArray(): array
    {
        return [
            'trace_id' => $this->traceId,
            'span_id' => $this->spanId,
            'parent_span_id' => $this->parentSpanId,
            'sampled' => $this->sampled,
            'node' => Runtime::nodeId(),
            'pid' => Runtime::processId(),
        ];
    }

    /**
     * 写入 kode/context，供生态内其它组件读取
     *
     * 未安装 kode/context 时静默跳过。
     *
     * @return bool 写入成功返回 true
     */
    public function publish(): bool
    {
        if (!class_exists('\Kode\Context\Context')) {
            return false;
        }

        \Kode\Context\Context::set(\Kode\Context\Context::TRACE_ID, $this->traceId);
        \Kode\Context\Context::set(\Kode\Context\Context::SPAN_ID, $this->spanId);
        \Kode\Context\Context::set(\Kode\Context\Context::PARENT_SPAN_ID, $this->parentSpanId);

        return true;
    }

    /**
     * 生成指定长度的随机十六进制字符串
     *
     * @param int $length 字符个数，必须为偶数
     * @return string 小写十六进制字符串
     */
    private static function randomHex(int $length): string
    {
        return bin2hex(random_bytes(max(1, intdiv($length, 2))));
    }
}
