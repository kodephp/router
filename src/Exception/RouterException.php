<?php

declare(strict_types=1);

namespace Kode\Router\Exception;

/**
 * 路由组件异常基类
 *
 * 所有 kode/router 抛出的异常都继承自本类，调用方可以用一个 catch
 * 精准捕获路由层错误，而不会误吞业务代码抛出的 RuntimeException。
 *
 * 继承 \RuntimeException 是为了兼容 2.0 之前依赖
 * `catch (\RuntimeException $e)` 的调用代码。
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
class RouterException extends \RuntimeException
{
}
