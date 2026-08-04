<?php

declare(strict_types=1);

namespace Kode\Router;

use Kode\Router\Exception\InvalidRouteException;

/**
 * 处理器解析器
 *
 * 把各种写法的路由处理器统一解析成可调用体：
 * - 闭包 / 任意 callable
 * - `'App\Controller\UserController@show'`
 * - `'App\Controller\UserController::show'`（静态方法）
 * - `[UserController::class, 'show']`
 * - `UserController::class`（可调用对象，走 __invoke）
 *
 * 字符串形式是"可缓存路由"的前提，也是多进程模型下推荐的写法：
 * 它不持有任何运行期状态，可以安全地 var_export 进缓存文件。
 *
 * 若注入了 PSR-11 容器（这里用鸭子类型判断，避免强依赖 psr/container），
 * 控制器实例会交给容器创建，从而支持构造函数自动装配。
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class HandlerResolver
{
    /**
     * @param object|null $container PSR-11 容器（可选），需实现 has()/get()
     */
    public function __construct(
        private readonly ?object $container = null
    ) {
    }

    /**
     * 解析处理器为可调用体
     *
     * @param mixed $handler 处理器声明
     * @return callable 可调用体
     *
     * @throws InvalidRouteException 无法解析时抛出
     */
    public function resolve(mixed $handler): callable
    {
        if ($handler instanceof \Closure) {
            return $handler;
        }

        if (is_string($handler)) {
            return $this->resolveString($handler);
        }

        if (is_array($handler) && count($handler) === 2) {
            /** @var array{0: object|string, 1: string} $handler */
            [$target, $method] = array_values($handler);

            $instance = is_object($target) ? $target : $this->instantiate((string) $target);

            if (!method_exists($instance, (string) $method)) {
                $class = $instance::class;

                throw new InvalidRouteException("处理器方法不存在: {$class}::{$method}");
            }

            /** @var callable $callable */
            $callable = [$instance, (string) $method];

            return $callable;
        }

        if (is_callable($handler)) {
            return $handler;
        }

        throw new InvalidRouteException('无法解析的路由处理器: ' . get_debug_type($handler));
    }

    /**
     * 解析并立即调用处理器
     *
     * 参数按"名称优先、位置兜底"的方式绑定：处理器形参名与路径参数名
     * 同名时按名传入，否则按顺序传入，这样 `fn(int $id)` 和
     * `fn(string $slug, int $page)` 都能正确工作。
     *
     * @param mixed $handler 处理器声明
     * @param array<string, mixed> $params 路径参数
     * @param array<int, mixed> $extra 追加到末尾的额外参数
     * @return mixed 处理器返回值
     *
     * @throws InvalidRouteException 无法解析时抛出
     */
    public function call(mixed $handler, array $params = [], array $extra = []): mixed
    {
        $callable = $this->resolve($handler);
        $args = $this->bindArguments($callable, $params);

        return $callable(...$args, ...$extra);
    }

    /**
     * 判断处理器是否为可静态缓存的形式
     *
     * @param mixed $handler 处理器声明
     * @return bool 可缓存返回 true
     */
    public static function isStatic(mixed $handler): bool
    {
        if (is_string($handler)) {
            return true;
        }

        if (is_array($handler) && count($handler) === 2) {
            return is_string($handler[0] ?? null) && is_string($handler[1] ?? null);
        }

        return false;
    }

    /**
     * 解析字符串形式的处理器
     *
     * @param string $handler 处理器字符串
     * @return callable 可调用体
     *
     * @throws InvalidRouteException 无法解析时抛出
     */
    private function resolveString(string $handler): callable
    {
        // Class@method
        if (str_contains($handler, '@')) {
            [$class, $method] = explode('@', $handler, 2);
            $instance = $this->instantiate($class);

            if (!method_exists($instance, $method)) {
                throw new InvalidRouteException("处理器方法不存在: {$class}::{$method}");
            }

            /** @var callable $callable */
            $callable = [$instance, $method];

            return $callable;
        }

        // Class::method（静态或实例方法都支持）
        if (str_contains($handler, '::')) {
            [$class, $method] = explode('::', $handler, 2);

            if (!class_exists($class)) {
                throw new InvalidRouteException("处理器类不存在: {$class}");
            }

            if (!method_exists($class, $method)) {
                throw new InvalidRouteException("处理器方法不存在: {$class}::{$method}");
            }

            $reflection = new \ReflectionMethod($class, $method);

            if ($reflection->isStatic()) {
                /** @var callable $staticCallable */
                $staticCallable = $class . '::' . $method;

                return $staticCallable;
            }

            /** @var callable $callable */
            $callable = [$this->instantiate($class), $method];

            return $callable;
        }

        // 普通函数
        if (function_exists($handler)) {
            return $handler;
        }

        // 可调用对象类名
        if (class_exists($handler)) {
            $instance = $this->instantiate($handler);

            if (!is_callable($instance)) {
                throw new InvalidRouteException("处理器类 {$handler} 未实现 __invoke()");
            }

            return $instance;
        }

        throw new InvalidRouteException("无法解析的路由处理器: {$handler}");
    }

    /**
     * 创建类实例，优先走容器
     *
     * @param string $class 类名
     * @return object 实例
     *
     * @throws InvalidRouteException 类不存在或无法实例化时抛出
     */
    private function instantiate(string $class): object
    {
        $container = $this->container;

        if ($container !== null
            && method_exists($container, 'has')
            && method_exists($container, 'get')
            && $container->has($class) === true
        ) {
            /** @var object $resolved */
            $resolved = $container->get($class);

            return $resolved;
        }

        if (!class_exists($class)) {
            throw new InvalidRouteException("处理器类不存在: {$class}");
        }

        $reflection = new \ReflectionClass($class);

        if (!$reflection->isInstantiable()) {
            throw new InvalidRouteException("处理器类无法实例化: {$class}");
        }

        $constructor = $reflection->getConstructor();

        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new InvalidRouteException(
                "处理器类 {$class} 的构造函数需要参数，请注入 PSR-11 容器后再解析"
            );
        }

        return $reflection->newInstance();
    }

    /**
     * 按形参名绑定路径参数
     *
     * @param callable $callable 可调用体
     * @param array<string, mixed> $params 路径参数
     * @return list<mixed> 按顺序排好的实参
     */
    private function bindArguments(callable $callable, array $params): array
    {
        if ($params === []) {
            return [];
        }

        try {
            $reflection = self::reflect($callable);
        } catch (\ReflectionException) {
            return array_values($params);
        }

        $parameters = $reflection->getParameters();

        if ($parameters === []) {
            return [];
        }

        $positional = array_values($params);
        $args = [];
        $cursor = 0;

        foreach ($parameters as $parameter) {
            $name = $parameter->getName();

            if (array_key_exists($name, $params)) {
                $args[] = $params[$name];
                continue;
            }

            if (array_key_exists($cursor, $positional)) {
                $args[] = $positional[$cursor];
                $cursor++;
                continue;
            }

            if ($parameter->isDefaultValueAvailable()) {
                $args[] = $parameter->getDefaultValue();
                continue;
            }

            break;
        }

        return $args;
    }

    /**
     * 取得可调用体的反射对象
     *
     * @param callable $callable 可调用体
     * @return \ReflectionFunctionAbstract 反射对象
     *
     * @throws \ReflectionException 反射失败时抛出
     */
    private static function reflect(callable $callable): \ReflectionFunctionAbstract
    {
        if (is_array($callable)) {
            /** @var array{0: object|string, 1: string} $callable */
            return new \ReflectionMethod($callable[0], $callable[1]);
        }

        if (is_string($callable) && str_contains($callable, '::')) {
            return new \ReflectionMethod($callable);
        }

        if (is_object($callable) && !$callable instanceof \Closure) {
            return new \ReflectionMethod($callable, '__invoke');
        }

        /** @var \Closure|string $callable */
        return new \ReflectionFunction($callable);
    }
}
