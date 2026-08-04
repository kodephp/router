<?php

declare(strict_types=1);

namespace Kode\Router\Exception;

/**
 * URL 生成异常
 *
 * 反向生成 URL 失败时抛出，例如命名路由不存在、必填参数缺失、
 * 或参数值不满足路由的正则约束。
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
class UrlGenerationException extends RouterException
{
}
