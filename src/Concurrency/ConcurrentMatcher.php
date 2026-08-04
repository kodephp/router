<?php

declare(strict_types=1);

namespace Kode\Router\Concurrency;

use Kode\Router\Dispatcher;
use Kode\Router\MatchResult;

/**
 * 批量并发匹配与执行
 *
 * 先说清楚一件事，免得用错：**路由匹配本身是纯 CPU 操作**，
 * FastRoute 一次匹配在微秒级，把它丢到协程里并发不会更快，只会更慢。
 * 所以 {@see self::matchAll()} 是串行实现，这是刻意为之。
 *
 * 真正值得并发的是**处理器执行**——控制器里往往要查库、调接口，
 * 这些 IO 等待可以重叠。{@see self::handleAll()} 用原生 Fiber 做协作式并发：
 * 处理器内部只要 suspend（或使用 Swoole/Swow 的协程化 IO），
 * 就会自动切到下一个请求，整批的总耗时接近最慢的那一个而不是全部之和。
 *
 * 典型场景：Swoole 常驻服务里的批量接口、CLI 里的路由预热与压测、
 * 网关侧一次转发多个下游路由。
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class ConcurrentMatcher
{
    /**
     * @param Dispatcher $dispatcher 分发器（只读，可被任意协程共享）
     */
    public function __construct(
        private readonly Dispatcher $dispatcher
    ) {
    }

    /**
     * 批量匹配路由
     *
     * 串行实现。匹配是纯内存计算，串行反而最快。
     *
     * @param array<int|string, array{0: string, 1: string}> $requests [[method, path], ...]
     * @return array<int|string, MatchResult> 与入参同键的匹配结果
     */
    public function matchAll(array $requests): array
    {
        $results = [];

        foreach ($requests as $key => $request) {
            $results[$key] = $this->dispatcher->match($request[0], $request[1]);
        }

        return $results;
    }

    /**
     * 批量并发执行路由
     *
     * 每个请求跑在独立的 Fiber 里，因此各自的 {@see RouteContext}
     * 天然隔离，不会串号。不支持 Fiber 的环境自动退化为串行执行。
     *
     * @param array<int|string, array{0: string, 1: string}> $requests [[method, path], ...]
     * @param int $concurrency 最大并发数，0 表示不限制
     * @param bool $captureErrors true 时把异常作为结果值返回，false 时直接抛出
     * @return array<int|string, mixed> 与入参同键的执行结果
     *
     * @throws \Throwable $captureErrors 为 false 且处理器抛异常时抛出
     */
    public function handleAll(array $requests, int $concurrency = 0, bool $captureErrors = false): array
    {
        if (!Runtime::supportsFibers()) {
            return $this->handleSerially($requests, $captureErrors);
        }

        if ($concurrency <= 0 || $concurrency >= count($requests)) {
            return $this->runBatch($requests, $captureErrors);
        }

        $results = [];

        foreach (array_chunk($requests, $concurrency, true) as $chunk) {
            foreach ($this->runBatch($chunk, $captureErrors) as $key => $value) {
                $results[$key] = $value;
            }
        }

        return $results;
    }

    /**
     * 并发映射：对每个匹配结果执行自定义回调
     *
     * 适合"匹配一次、并发做多件事"的场景，例如批量鉴权、批量预取。
     *
     * @template T
     * @param array<int|string, array{0: string, 1: string}> $requests [[method, path], ...]
     * @param callable(MatchResult): T $callback 回调
     * @param int $concurrency 最大并发数，0 表示不限制
     * @return array<int|string, mixed> 与入参同键的回调返回值
     */
    public function mapConcurrently(array $requests, callable $callback, int $concurrency = 0): array
    {
        $matched = $this->matchAll($requests);

        if (!Runtime::supportsFibers()) {
            return array_map($callback, $matched);
        }

        $tasks = [];

        foreach ($matched as $key => $result) {
            $tasks[$key] = static fn (): mixed => $callback($result);
        }

        return self::runTasks($tasks, $concurrency, false);
    }

    /**
     * 获取底层分发器
     *
     * @return Dispatcher 分发器
     */
    public function getDispatcher(): Dispatcher
    {
        return $this->dispatcher;
    }

    /**
     * 并发执行一批任务（通用工具）
     *
     * @param array<int|string, callable(): mixed> $tasks 任务表
     * @param int $concurrency 最大并发数，0 表示不限制
     * @param bool $captureErrors true 时把异常作为结果值返回
     * @return array<int|string, mixed> 与入参同键的执行结果
     *
     * @throws \Throwable $captureErrors 为 false 且任务抛异常时抛出
     */
    public static function runTasks(array $tasks, int $concurrency = 0, bool $captureErrors = false): array
    {
        if (!Runtime::supportsFibers()) {
            $results = [];

            foreach ($tasks as $key => $task) {
                $results[$key] = self::invoke($task, $captureErrors);
            }

            return $results;
        }

        if ($concurrency <= 0 || $concurrency >= count($tasks)) {
            return self::drain($tasks, $captureErrors);
        }

        $results = [];

        foreach (array_chunk($tasks, $concurrency, true) as $chunk) {
            foreach (self::drain($chunk, $captureErrors) as $key => $value) {
                $results[$key] = $value;
            }
        }

        return $results;
    }

    /**
     * 执行一批请求（不限并发）
     *
     * @param array<int|string, array{0: string, 1: string}> $requests 请求列表
     * @param bool $captureErrors 是否捕获异常
     * @return array<int|string, mixed> 执行结果
     *
     * @throws \Throwable 未捕获模式下处理器抛出的异常
     */
    private function runBatch(array $requests, bool $captureErrors): array
    {
        $dispatcher = $this->dispatcher;
        $tasks = [];

        foreach ($requests as $key => $request) {
            $method = $request[0];
            $path = $request[1];
            $tasks[$key] = static fn (): mixed => $dispatcher->handle($method, $path);
        }

        return self::drain($tasks, $captureErrors);
    }

    /**
     * 串行执行（无 Fiber 环境的回退实现）
     *
     * @param array<int|string, array{0: string, 1: string}> $requests 请求列表
     * @param bool $captureErrors 是否捕获异常
     * @return array<int|string, mixed> 执行结果
     *
     * @throws \Throwable 未捕获模式下处理器抛出的异常
     */
    private function handleSerially(array $requests, bool $captureErrors): array
    {
        $results = [];

        foreach ($requests as $key => $request) {
            $results[$key] = self::invoke(
                fn (): mixed => $this->dispatcher->handle($request[0], $request[1]),
                $captureErrors
            );
        }

        return $results;
    }

    /**
     * 用 Fiber 跑干一批任务
     *
     * 先把所有 Fiber 启动一遍（跑到第一次 suspend 或结束），
     * 再轮转恢复仍未完成的，直到全部结束。
     *
     * @param array<int|string, callable(): mixed> $tasks 任务表
     * @param bool $captureErrors 是否捕获异常
     * @return array<int|string, mixed> 执行结果
     *
     * @throws \Throwable 未捕获模式下任务抛出的异常
     */
    private static function drain(array $tasks, bool $captureErrors): array
    {
        $results = [];
        /** @var array<int|string, \Fiber<mixed, mixed, mixed, mixed>> $pending */
        $pending = [];

        foreach ($tasks as $key => $task) {
            /** @var \Fiber<mixed, mixed, mixed, mixed> $fiber */
            $fiber = new \Fiber($task);

            try {
                $fiber->start();
            } catch (\Throwable $e) {
                if (!$captureErrors) {
                    throw $e;
                }

                $results[$key] = $e;
                continue;
            }

            if ($fiber->isTerminated()) {
                $results[$key] = $fiber->getReturn();
                continue;
            }

            $pending[$key] = $fiber;
        }

        // 轮转恢复，直到全部 Fiber 结束
        while ($pending !== []) {
            foreach ($pending as $key => $fiber) {
                try {
                    $fiber->resume();
                } catch (\Throwable $e) {
                    unset($pending[$key]);

                    if (!$captureErrors) {
                        throw $e;
                    }

                    $results[$key] = $e;
                    continue;
                }

                if ($fiber->isTerminated()) {
                    $results[$key] = $fiber->getReturn();
                    unset($pending[$key]);
                }
            }
        }

        // 恢复入参顺序
        $ordered = [];

        foreach (array_keys($tasks) as $key) {
            if (array_key_exists($key, $results)) {
                $ordered[$key] = $results[$key];
            }
        }

        return $ordered;
    }

    /**
     * 调用单个任务，按需捕获异常
     *
     * @param callable(): mixed $task 任务
     * @param bool $captureErrors 是否捕获异常
     * @return mixed 任务返回值，捕获模式下异常会作为返回值
     *
     * @throws \Throwable 未捕获模式下任务抛出的异常
     */
    private static function invoke(callable $task, bool $captureErrors): mixed
    {
        if (!$captureErrors) {
            return $task();
        }

        try {
            return $task();
        } catch (\Throwable $e) {
            return $e;
        }
    }
}
