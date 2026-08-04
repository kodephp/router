# 代码风格规范

本包遵循以下 PHP 编码标准：

## PSR 标准

- **PSR-1**: 基本编码规范
- **PSR-2**: 编码风格规范
- **PSR-4**: 自动加载规范
- **PSR-12**: 扩展编码风格规范

## 命名规范

### 类名
- 使用 PascalCase
- 示例：`RouteCollector`, `Dispatcher`

### 方法名
- 使用 camelCase
- 示例：`getRoute()`, `dispatch()`

### 常量
- 使用 UPPER_SNAKE_CASE
- 示例：`NOT_FOUND`, `HTTP_METHOD`

### 变量名
- 使用 camelCase
- 示例：`$routeCollector`, `$dispatcher`

## 注释规范

### 文件头注释
```php
<?php

namespace Kode\Router;

/**
 * 路由分发器
 *
 * 根据请求方法和路径匹配路由，执行对应的回调。
 */
class Dispatcher
{
```

### 类注释
```php
/**
 * 类名
 *
 * 类的功能描述。
 *
 * @author 作者名 <email@example.com>
 */
class ClassName
{
```

### 方法注释
```php
/**
 * 方法功能描述
 *
 * @param string $param 参数说明
 * @return mixed 返回值说明
 * @throws \RuntimeException 异常说明
 */
public function methodName(string $param)
{
```

### 属性注释
```php
/**
 * @var string 属性说明
 */
private string $property;

/**
 * @var array<string, Route> 属性说明
 */
private array $routes = [];
```

## 代码风格

### 缩进
- 使用 4 个空格缩进
- 不使用 Tab

### 行长度
- 最大行长度：120 字符
- 超长语句应换行

### 花括号
```php
// 类定义
class ClassName
{
    public function method()
    {
        // 代码
    }
}

// 条件语句
if ($condition) {
    // 代码
} elseif ($other) {
    // 代码
} else {
    // 代码
}

// 循环
foreach ($items as $item) {
    // 代码
}
```

## PHP 8.3+ 特性

### 类型声明
```php
public function getRoute(string $name): ?Route
{
    return $this->routes[$name] ?? null;
}
```

### 构造函数属性提升
```php
class Route
{
    public function __construct(
        private array $methods,
        private string $path,
        private callable $callback,
        private ?string $name = null
    ) {
    }
}
```

### 匹配表达式
```php
switch ($result[0]) {
    case FastRouteDispatcher::NOT_FOUND:
        throw new \RuntimeException("路由未找到");
    case FastRouteDispatcher::FOUND:
        return $result[1](...$result[2]);
    default:
        throw new \RuntimeException("路由分发失败");
}
```

## 错误处理

### 使用自定义异常
```php
class RouteNotFoundException extends \RuntimeException
{
}
```

### 异常层次
```
Kode\Router\Exception
├── RouteNotFoundException
├── MethodNotAllowedException
└── MiddlewareException
```

## 测试规范

### 测试类命名
- 使用 `*Test` 后缀
- 示例：`RouterTest`, `DispatcherTest`

### 测试方法命名
- 使用 `test` 前缀
- 示例：`testBasicRoute()`, `testNotFound()`

### 测试覆盖
- 每个公共方法都要有测试
- 测试边界情况
- 测试异常场景

## 提交规范

使用 [Conventional Commits](https://www.conventionalcommits.org/) 格式：

```
<type>(<scope>): <subject>

<body>

<footer>
```

### Type 类型

- `feat`: 新功能
- `fix`: 修复 bug
- `docs`: 文档变更
- `style`: 代码格式（不影响功能）
- `refactor`: 重构
- `test`: 测试相关
- `chore`: 构建/工具相关

### 示例

```
feat(router): 添加路由组嵌套支持

- 支持 RouteGroup 嵌套
- 更新测试用例

Closes #123
```
