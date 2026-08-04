<?php

declare(strict_types=1);

namespace Kode\Router\Tests\Fixtures;

/**
 * 测试用控制器
 *
 * 用字符串形式的处理器（"UserController@show"）是路由表可缓存的前提，
 * 这个 fixture 专门用来覆盖那条路径。
 *
 * @package Kode\Router
 */
class UserController
{
    /**
     * 展示用户
     *
     * @param string $id 用户 ID
     * @return string 结果文本
     */
    public function show(string $id): string
    {
        return "user:{$id}";
    }

    /**
     * 用户列表
     *
     * @return array<string, mixed> 结果数据
     */
    public function index(): array
    {
        return ['users' => []];
    }

    /**
     * 静态方法处理器
     *
     * @param string $id 用户 ID
     * @return string 结果文本
     */
    public static function statik(string $id): string
    {
        return "static:{$id}";
    }

    /**
     * 按名称绑定参数：形参顺序与路径顺序刻意相反
     *
     * @param string $page 页码
     * @param string $slug 别名
     * @return string 结果文本
     */
    public function reversed(string $page, string $slug): string
    {
        return "{$slug}#{$page}";
    }
}
