<?php

declare(strict_types=1);

namespace Kode\Router\Tests\Fixtures;

/**
 * 构造函数需要参数的控制器
 *
 * 用于验证"没有容器时应当明确报错，而不是静默失败"。
 *
 * @package Kode\Router
 */
class NeedsDependency
{
    /**
     * @param string $dependency 必填依赖
     */
    public function __construct(private readonly string $dependency)
    {
    }

    /**
     * 处理请求
     *
     * @return string 结果文本
     */
    public function handle(): string
    {
        return $this->dependency;
    }
}
