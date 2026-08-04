<?php

declare(strict_types=1);

namespace Kode\Router\Concurrency;

use Kode\Router\MatchResult;

/**
 * 当前路由上下文
 *
 * 解决常驻内存 + 协程模型下最容易踩的坑：**当前路由串号**。
 *
 * 很多框架把"当前路由"存成静态属性或对象属性，单请求单进程时没问题，
 * 一旦跑在 Swoole / Fiber 下，协程 A 挂起、协程 B 写入，A 恢复后
 * 读到的就是 B 的路由。本类按执行流 ID 分桶存放，从根上杜绝这个问题。
 *
 * 若项目中安装了 kode/context，会自动委托给它，
 * 从而与生态内其它组件（中间件、链路追踪）共享同一份上下文生命周期。
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class RouteContext
{
    /** @var string 存入 kode/context 时使用的键名 */
    public const KEY = 'kode.router.match';

    /** @var array<string, MatchResult> 回退存储：执行流 ID => 匹配结果 */
    private static array $store = [];

    /** @var bool|null kode/context 可用性缓存 */
    private static ?bool $contextAvailable = null;

    /**
     * 私有构造，纯静态工具类
     */
    private function __construct()
    {
    }

    /**
     * 写入当前执行流的路由匹配结果
     *
     * @param MatchResult $result 匹配结果
     * @return void
     */
    public static function set(MatchResult $result): void
    {
        if (self::useContext()) {
            \Kode\Context\Context::set(self::KEY, $result);

            return;
        }

        self::$store[Runtime::executionId()] = $result;
    }

    /**
     * 读取当前执行流的路由匹配结果
     *
     * 当前执行流没有值时，会回退查找所属进程级的值，
     * 这样"在协程外匹配、在协程内读取"的用法也能正常工作。
     *
     * @return MatchResult|null 匹配结果，未设置返回 null
     */
    public static function current(): ?MatchResult
    {
        if (self::useContext()) {
            $value = \Kode\Context\Context::get(self::KEY);

            return $value instanceof MatchResult ? $value : null;
        }

        $id = Runtime::executionId();

        if (isset(self::$store[$id])) {
            return self::$store[$id];
        }

        // 回退到进程级
        $processKey = 'process:' . Runtime::processId();

        return self::$store[$processKey] ?? null;
    }

    /**
     * 是否已设置当前路由
     *
     * @return bool 已设置返回 true
     */
    public static function has(): bool
    {
        return self::current() !== null;
    }

    /**
     * 获取当前路由名称
     *
     * @return string|null 路由名，未设置或匿名路由返回 null
     */
    public static function name(): ?string
    {
        return self::current()?->getName();
    }

    /**
     * 获取当前路由的某个路径参数
     *
     * @param string $key 参数名
     * @param mixed $default 默认值
     * @return mixed 参数值
     */
    public static function param(string $key, mixed $default = null): mixed
    {
        return self::current()?->getParam($key, $default) ?? $default;
    }

    /**
     * 清除当前执行流的路由上下文
     *
     * 常驻内存模型下**必须**在请求结束时调用，否则执行流 ID 复用时
     * 会读到上一个请求的残留数据，同时也会造成内存缓慢增长。
     *
     * @return void
     */
    public static function clear(): void
    {
        if (self::useContext()) {
            \Kode\Context\Context::delete(self::KEY);

            return;
        }

        unset(self::$store[Runtime::executionId()]);
    }

    /**
     * 在指定路由上下文中执行回调，结束后自动还原
     *
     * 这是最安全的用法：无论回调正常返回还是抛异常，上下文都会被复原。
     *
     * @template T
     * @param MatchResult $result 匹配结果
     * @param callable(): T $callback 回调
     * @return T 回调返回值
     */
    public static function run(MatchResult $result, callable $callback): mixed
    {
        $previous = self::current();
        self::set($result);

        try {
            return $callback();
        } finally {
            if ($previous === null) {
                self::clear();
            } else {
                self::set($previous);
            }
        }
    }

    /**
     * 清空全部执行流的上下文
     *
     * 仅用于测试或进程退出前的兜底清理。
     *
     * @return void
     */
    public static function flush(): void
    {
        self::$store = [];

        if (self::useContext()) {
            \Kode\Context\Context::delete(self::KEY);
        }
    }

    /**
     * 当前保存的上下文数量（仅回退存储有效，用于测试与泄漏排查）
     *
     * @return int 上下文数量
     */
    public static function count(): int
    {
        return count(self::$store);
    }

    /**
     * 重置 kode/context 可用性缓存（仅供测试使用）
     *
     * @return void
     */
    public static function resetIntegration(): void
    {
        self::$contextAvailable = null;
    }

    /**
     * 是否委托给 kode/context
     *
     * @return bool 委托返回 true
     */
    private static function useContext(): bool
    {
        return self::$contextAvailable ??= class_exists('\Kode\Context\Context');
    }
}
