<?php

declare(strict_types=1);

namespace Kode\Router\Tests\Fixtures;

/**
 * 测试用可调用控制器
 *
 * @package Kode\Router
 */
class InvokableController
{
    /**
     * 处理请求
     *
     * @param string $id 资源 ID
     * @return string 结果文本
     */
    public function __invoke(string $id): string
    {
        return "invoke:{$id}";
    }
}
