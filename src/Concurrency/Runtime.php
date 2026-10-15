<?php

declare(strict_types=1);

namespace Kode\Router\Concurrency;

/**
 * 运行时探测
 *
 * 回答两个问题：**现在跑在什么并发模型里**，以及**当前执行流的唯一标识是什么**。
 * 后者是协程安全的关键——只有拿到稳定的执行流 ID，
 * {@see RouteContext} 才能把不同协程的当前路由分开存放。
 *
 * 全部采用软探测（extension_loaded / class_exists / function_exists），
 * 缺失任何扩展都不会报错，只会退化到更保守的模型。
 * 若项目中安装了 kode/context，会优先复用它的判定结果，保证生态内一致。
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class Runtime
{
    /** @var string Swoole 协程 */
    public const SWOOLE = 'swoole';

    /** @var string Swow 协程 */
    public const SWOW = 'swow';

    /** @var string 原生 Fiber 协程 */
    public const FIBER = 'fiber';

    /** @var string ext-parallel 多线程 */
    public const THREAD = 'thread';

    /** @var string pcntl 多进程 */
    public const PROCESS = 'process';

    /** @var string PHP-FPM */
    public const FPM = 'fpm';

    /** @var string 同步 CLI */
    public const CLI = 'cli';

    /** @var string|null 缓存的宿主环境判定结果 */
    private static ?string $host = null;

    /**
     * 探测当前所处的并发模型
     *
     * 返回值反映"此时此刻"的状态：同一个 Swoole 进程里，
     * 在协程内调用返回 swoole，在协程外调用返回 cli/fpm。
     *
     * @return string 本类定义的常量之一
     */
    public static function detect(): string
    {
        // 先看是否正处在某个协程 / Fiber 内
        if (self::inSwooleCoroutine()) {
            return self::SWOOLE;
        }

        if (self::inSwowCoroutine()) {
            return self::SWOW;
        }

        if (self::inFiber()) {
            return self::FIBER;
        }

        return self::host();
    }

    /**
     * 探测宿主环境（与"当前是否在协程内"无关，结果会被缓存）
     *
     * @return string 本类定义的常量之一
     */
    public static function host(): string
    {
        if (self::$host !== null) {
            return self::$host;
        }

        if (extension_loaded('parallel')) {
            return self::$host = self::THREAD;
        }

        if (PHP_SAPI === 'fpm-fcgi' || PHP_SAPI === 'apache2handler' || PHP_SAPI === 'litespeed') {
            return self::$host = self::FPM;
        }

        if (extension_loaded('pcntl') && PHP_SAPI === 'cli') {
            return self::$host = self::PROCESS;
        }

        return self::$host = self::CLI;
    }

    /**
     * 当前是否处在协程 / Fiber 上下文中
     *
     * @return bool 处于协程中返回 true
     */
    public static function inCoroutine(): bool
    {
        return self::inSwooleCoroutine() || self::inSwowCoroutine() || self::inFiber();
    }

    /**
     * 是否支持原生 Fiber
     *
     * @return bool 支持返回 true
     */
    public static function supportsFibers(): bool
    {
        return class_exists(\Fiber::class);
    }

    /**
     * 是否支持多线程（ext-parallel）
     *
     * @return bool 支持返回 true
     */
    public static function supportsThreads(): bool
    {
        return extension_loaded('parallel');
    }

    /**
     * 是否支持多进程（pcntl）
     *
     * @return bool 支持返回 true
     */
    public static function supportsProcesses(): bool
    {
        return extension_loaded('pcntl') && function_exists('pcntl_fork');
    }

    /**
     * 是否为常驻内存模型
     *
     * 常驻内存下必须避免"每请求重新注册路由"，应改用编译 + 缓存。
     *
     * @return bool 常驻内存返回 true
     */
    public static function isLongRunning(): bool
    {
        return in_array(self::host(), [self::SWOOLE, self::SWOW, self::THREAD, self::PROCESS], true)
            || self::inCoroutine();
    }

    /**
     * 获取当前执行流的唯一标识
     *
     * 优先级：Swoole 协程 ID → Swow 协程 ID → Fiber 对象哈希 → 线程 ID → 进程 ID。
     * 同一个执行流内多次调用结果稳定，不同执行流之间一定不同。
     *
     * @return string 执行流标识
     */
    public static function executionId(): string
    {
        // Swoole 协程
        if (self::inSwooleCoroutine()) {
            /** @var callable $getCid */
            $getCid = ['\Swoole\Coroutine', 'getCid'];
            /** @var int $cid */
            $cid = $getCid();

            return 'swoole:' . getmypid() . ':' . $cid;
        }

        // Swow 协程
        if (self::inSwowCoroutine()) {
            /** @var callable $getCurrent */
            $getCurrent = ['\Swow\Coroutine', 'getCurrent'];
            /** @var object|null $co */
            $co = $getCurrent();

            if (is_object($co)) {
                return 'swow:' . getmypid() . ':' . spl_object_id($co);
            }
        }

        // 原生 Fiber
        $fiber = self::currentFiber();

        if ($fiber !== null) {
            return 'fiber:' . getmypid() . ':' . spl_object_id($fiber);
        }

        // 线程
        if (extension_loaded('parallel') && class_exists('\parallel\Runtime')) {
            return 'thread:' . getmypid() . ':' . self::threadId();
        }

        return 'process:' . getmypid();
    }

    /**
     * 获取当前进程 ID
     *
     * @return int 进程 ID
     */
    public static function processId(): int
    {
        return getmypid() ?: 0;
    }

    /**
     * 获取当前节点标识
     *
     * 用于分布式场景下标记路由表来自哪台机器。
     *
     * @return string 节点标识
     */
    public static function nodeId(): string
    {
        $host = gethostname();

        return $host === false ? 'unknown' : $host;
    }

    /**
     * 重置探测缓存（仅供测试使用）
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$host = null;
    }

    /**
     * 当前是否在 Swoole 协程内
     *
     * @return bool 是返回 true
     */
    private static function inSwooleCoroutine(): bool
    {
        if (!extension_loaded('swoole') || !class_exists('\Swoole\Coroutine')) {
            return false;
        }

        /** @var callable $getCid */
        $getCid = ['\Swoole\Coroutine', 'getCid'];
        /** @var int $cid */
        $cid = $getCid();

        return $cid > 0;
    }

    /**
     * 当前是否在 Swow 协程内
     *
     * @return bool 是返回 true
     */
    private static function inSwowCoroutine(): bool
    {
        if (!extension_loaded('swow') || !class_exists('\Swow\Coroutine')) {
            return false;
        }

        /** @var callable $getCurrent */
        $getCurrent = ['\Swow\Coroutine', 'getCurrent'];
        /** @var callable $getMain */
        $getMain = ['\Swow\Coroutine', 'getMain'];

        return $getCurrent() !== $getMain();
    }

    /**
     * 当前是否在原生 Fiber 内
     *
     * @return bool 是返回 true
     */
    private static function inFiber(): bool
    {
        return self::currentFiber() !== null;
    }

    /**
     * 获取当前 Fiber 对象
     *
     * @return \Fiber<mixed, mixed, mixed, mixed>|null 当前 Fiber，不在 Fiber 内返回 null
     */
    private static function currentFiber(): ?\Fiber
    {
        if (!self::supportsFibers()) {
            return null;
        }

        return \Fiber::getCurrent();
    }

    /**
     * 获取当前线程标识
     *
     * @return string 线程标识
     */
    private static function threadId(): string
    {
        if (function_exists('zend_thread_id')) {
            /** @var callable $fn */
            $fn = 'zend_thread_id';

            return (string) $fn();
        }

        return '0';
    }
}
