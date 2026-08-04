<?php

declare(strict_types=1);

namespace Kode\Router\Exception;

/**
 * 路由缓存异常
 *
 * 路由表缓存的写入、读取或反序列化失败时抛出，例如缓存目录不可写、
 * 缓存文件结构损坏、或路由表中含有无法序列化的闭包处理器。
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
class RouteCacheException extends RouterException
{
}
