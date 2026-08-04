<?php

declare(strict_types=1);

namespace Kode\Router\Distributed;

use Kode\Router\CompiledRoutes;
use Kode\Router\Concurrency\Runtime;

/**
 * 路由表清单
 *
 * 解决分布式部署里一个很隐蔽但代价很高的问题：**灰度期间各节点路由表不一致**。
 *
 * 滚动发布时，节点 A 已经加载了新路由、节点 B 还是旧的。请求打到 B 就 404，
 * 打到 A 就正常，表现为"接口时好时坏"，排查起来非常痛苦。
 *
 * 清单把路由表指纹、应用版本、节点标识打包成一个轻量对象，
 * 可以随响应头下发、也可以上报给注册中心。网关或调用方只要比对指纹，
 * 就能立刻发现"这个集群里有节点路由表不一致"。
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class RouteManifest
{
    /** @var string 路由指纹响应头 */
    public const HEADER_FINGERPRINT = 'X-Kode-Route-Fingerprint';

    /** @var string 应用版本响应头 */
    public const HEADER_VERSION = 'X-Kode-Route-Version';

    /** @var string 节点标识响应头 */
    public const HEADER_NODE = 'X-Kode-Route-Node';

    /**
     * @param string $fingerprint 路由表指纹
     * @param string $version 应用版本号
     * @param string $node 节点标识
     * @param int $routeCount 路由条数
     * @param int $generatedAt 生成时间戳
     */
    public function __construct(
        private readonly string $fingerprint,
        private readonly string $version = '',
        private readonly string $node = '',
        private readonly int $routeCount = 0,
        private readonly int $generatedAt = 0
    ) {
    }

    /**
     * 从编译产物生成清单
     *
     * @param CompiledRoutes $compiled 编译后的路由表
     * @param string $version 应用版本号
     * @param string|null $node 节点标识，为 null 时取主机名
     * @return self 清单
     */
    public static function fromCompiled(
        CompiledRoutes $compiled,
        string $version = '',
        ?string $node = null
    ): self {
        return new self(
            $compiled->getFingerprint(),
            $version,
            $node ?? Runtime::nodeId(),
            $compiled->count(),
            $compiled->getCompiledAt()
        );
    }

    /**
     * 从请求头还原清单
     *
     * @param array<string, mixed> $headers 请求头
     * @return self|null 清单，缺少指纹时返回 null
     */
    public static function fromHeaders(array $headers): ?self
    {
        $normalized = [];

        foreach ($headers as $name => $value) {
            $normalized[strtolower((string) $name)] = is_array($value)
                ? (string) reset($value)
                : (string) $value;
        }

        $fingerprint = $normalized[strtolower(self::HEADER_FINGERPRINT)] ?? '';

        if (!is_string($fingerprint) || $fingerprint === '') {
            return null;
        }

        return new self(
            $fingerprint,
            (string) ($normalized[strtolower(self::HEADER_VERSION)] ?? ''),
            (string) ($normalized[strtolower(self::HEADER_NODE)] ?? '')
        );
    }

    /**
     * 获取路由表指纹
     *
     * @return string sha256 指纹
     */
    public function getFingerprint(): string
    {
        return $this->fingerprint;
    }

    /**
     * 获取短指纹（前 16 位），用于日志与响应头
     *
     * @return string 短指纹
     */
    public function getShortFingerprint(): string
    {
        return substr($this->fingerprint, 0, 16);
    }

    /**
     * 获取应用版本号
     *
     * @return string 版本号
     */
    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * 获取节点标识
     *
     * @return string 节点标识
     */
    public function getNode(): string
    {
        return $this->node;
    }

    /**
     * 获取路由条数
     *
     * @return int 路由条数
     */
    public function getRouteCount(): int
    {
        return $this->routeCount;
    }

    /**
     * 获取生成时间戳
     *
     * @return int Unix 时间戳
     */
    public function getGeneratedAt(): int
    {
        return $this->generatedAt;
    }

    /**
     * 判断两份清单的路由表是否一致
     *
     * 只比对指纹，不比对版本号与节点——同一份路由表在不同版本、
     * 不同机器上仍然应该被认为是一致的。
     *
     * @param self $other 对方清单
     * @return bool 一致返回 true
     */
    public function matches(self $other): bool
    {
        return hash_equals($this->fingerprint, $other->fingerprint);
    }

    /**
     * 判断是否与指定编译产物一致
     *
     * @param CompiledRoutes $compiled 编译后的路由表
     * @return bool 一致返回 true
     */
    public function matchesCompiled(CompiledRoutes $compiled): bool
    {
        return hash_equals($this->fingerprint, $compiled->getFingerprint());
    }

    /**
     * 生成可下发的响应头
     *
     * 指纹必须输出完整 64 位：链路两端的节点都要用同一份指纹做一致性比对，
     * 如果用截断的短指纹，接收方拿到的就是 16 位、本地编译产物是 64 位，
     * {@see self::matches()} 永远对不上。指纹本身是 sha256，不含敏感信息。
     *
     * @return array<string, string> 响应头键值对
     */
    public function toHeaders(): array
    {
        $headers = [self::HEADER_FINGERPRINT => $this->fingerprint];

        if ($this->version !== '') {
            $headers[self::HEADER_VERSION] = $this->version;
        }

        if ($this->node !== '') {
            $headers[self::HEADER_NODE] = $this->node;
        }

        return $headers;
    }

    /**
     * 导出为数组，便于上报注册中心或写日志
     *
     * @return array<string, mixed> 数组表示
     */
    public function toArray(): array
    {
        return [
            'fingerprint' => $this->fingerprint,
            'version' => $this->version,
            'node' => $this->node,
            'route_count' => $this->routeCount,
            'generated_at' => $this->generatedAt,
        ];
    }

    /**
     * 从数组还原
     *
     * @param array<string, mixed> $data 数组表示
     * @return self 清单
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['fingerprint'] ?? ''),
            (string) ($data['version'] ?? ''),
            (string) ($data['node'] ?? ''),
            (int) ($data['route_count'] ?? 0),
            (int) ($data['generated_at'] ?? 0)
        );
    }
}
