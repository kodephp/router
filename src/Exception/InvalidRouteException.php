<?php

declare(strict_types=1);

namespace Kode\Router\Exception;

/**
 * 非法路由定义异常
 *
 * 路由注册阶段（编译期）发现定义错误时抛出，例如：
 * - HTTP 方法列表为空
 * - 路由名称重复
 * - 路径不以 `/` 开头
 * - where 约束引用了路径中不存在的参数
 *
 * 这类问题属于开发期错误，应当在启动时立刻暴露而不是留到运行期。
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
class InvalidRouteException extends RouterException
{
}
