<?php

namespace Kode\Router;

/**
 * 中间件接口
 *
 * 实现此接口可创建自定义中间件。
 */
interface MiddlewareInterface
{
    /**
     * 处理请求
     *
     * @param array $args     路由回调的参数
     * @param callable $next   下一个中间件或路由回调
     * @return mixed 处理结果
     */
    public function handle(array $args, callable $next);
}
