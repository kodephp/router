# 开发指南

本文面向 `kode/router` 的贡献者与二次开发者。

## 环境要求

- PHP 8.3+
- Composer 2.0+
- Git

可选（用于跑完整测试矩阵）：`ext-apcu`、`ext-opcache`、Xdebug 或 PCOV。

```bash
git clone https://github.com/kodephp/router.git
cd router
composer install
```

## 目录结构

见 [OVERVIEW.md](OVERVIEW.md)。核心约定：

- `src/` 顶层放核心类，子目录按能力域划分（`Cache/`、`Concurrency/`、`Distributed/`、`Integration/`、`Exception/`）。
- `tests/` 目录结构镜像 `src/`。
- `tests/Fixtures/` 放测试用的控制器桩类，不参与覆盖率统计。

## 常用命令

```bash
composer test           # PHPUnit
composer test-coverage  # 覆盖率报告（需 Xdebug/PCOV）
composer phpstan        # PHPStan level 8
composer cs-fix         # 修复代码风格
composer cs-check       # 检查代码风格（不修改）
composer ci             # test + phpstan + cs-check
```

单独跑某个测试：

```bash
vendor/bin/phpunit tests/DispatcherTest.php
vendor/bin/phpunit --filter testMatchReturnsFoundResult
vendor/bin/phpunit tests/Concurrency/
```

> PHPStan 分析本包需要约 1G 内存，`composer phpstan` 已带 `--memory-limit=1G`。直接调用 `vendor/bin/phpstan` 时记得补上，否则会 OOM。

## 架构约束

改代码前先理解这三条，它们是 2.0 的设计根基：

**1. 编译期与运行期严格分离**

`Router` / `RouteCollection` 可变，属于定义期；`CompiledRoutes` / `MatchResult` 不可变，属于编译期与运行期。任何往运行期塞可变状态的改动都会破坏协程安全。

**2. 编译产物必须可序列化**

`CompiledRoutes::toArray()` 的输出必须能通过 `var_export` 往返。这意味着 FastRoute 调度数据里只能放标量——所以 handler 位置注册的是 **int 路由 ID**，真实处理器存在元数据表里。新增字段时务必保持这个性质，并补上 `fromArray()` 的校验。

**3. 生态集成一律软依赖**

对 `kode/*` 与可选扩展只用 `class_exists()` / `interface_exists()` / `extension_loaded()` / `function_exists()` 探测，**绝不 `require`**。探测结果要缓存，并提供 `resetIntegration()` 之类的方法供测试重置。不可用时必须有语义等价的降级实现。

## 编码规范

遵循 [CODE_STYLE.md](../CODE_STYLE.md)，要点：

- PSR-12，`declare(strict_types=1)`，全参数与返回值类型声明。
- 完整中文 DocBlock：类说明写"为什么这么设计"，方法说明写参数、返回、异常。
- 优先 `final class` 与 `readonly` 属性；确需继承时才开放。
- 数组类型用 PHPStan 泛型注解标注（`array<string, mixed>`、`list<string>`）。
- 异常一律继承 `RouterException`，消息用中文并带上下文（路由名、路径、类名）。

几个容易踩的点：

```php
// ✗ PHP 没有 (callable) 类型转换
return (callable) [$instance, $method];

// ✓ 用局部变量 + @var 注解，既合法又能让 PHPStan 收敛类型
/** @var callable $callable */
$callable = [$instance, $method];
return $callable;
```

```php
// ✗ 字符串插值里不能直接写 ::class
throw new InvalidRouteException("方法不存在: {$instance::class}::{$method}");

// ✓ 先取出来
$class = $instance::class;
throw new InvalidRouteException("方法不存在: {$class}::{$method}");
```

## 测试规范

- 每个公共方法至少一个用例，覆盖正常流程、边界、异常。
- 测试类命名 `*Test`，方法 `test*`，一个方法只断言一个行为。
- 不可变对象必须测"派生后原对象未变"。
- 可选依赖相关的测试用 `markTestSkipped()` 优雅跳过：

```php
protected function setUp(): void
{
    if (!ApcuRouteCache::isSupported()) {
        $this->markTestSkipped('APCu 扩展未安装');
    }
}
```

- 涉及全局状态的测试（`Runtime`、`RouteContext`）必须在 `tearDown()` 里复位：

```php
protected function tearDown(): void
{
    RouteContext::flush();
    Runtime::reset();
}
```

`phpunit.xml` 开启了随机执行顺序与 `failOnWarning` / `failOnRisky`，测试之间不能有隐式依赖。

### 两个易错点

**FastRoute 状态码与 MatchResult 状态码取值不同**

```php
// FastRoute:    NOT_FOUND = 0, FOUND = 1, METHOD_NOT_ALLOWED = 2
// MatchResult:  FOUND = 0, NOT_FOUND = 1, METHOD_NOT_ALLOWED = 2
```

写测试时用常量，别写字面量。

**PSR-7 消息不可变**

测 `RoutingMiddleware` 时，属性只在下游 handler 收到的 `$request` 上。要断言属性，得用一个能捕获下游请求的桩 handler：

```php
$captured = null;
$handler = new class ($captured) implements RequestHandlerInterface {
    // 在 handle() 里把 $request 存下来
};
```

## 开发流程

```bash
git checkout -b feature/your-feature

# 写代码 + 写测试
composer ci                     # 必须全绿

git commit -m "feat(cache): 支持 Redis 路由缓存"
git push origin feature/your-feature
```

提交信息遵循 [Conventional Commits](https://www.conventionalcommits.org/zh-hans/)：

| 类型 | 用途 |
|------|------|
| `feat` | 新功能 |
| `fix` | 缺陷修复 |
| `perf` | 性能优化 |
| `refactor` | 重构（无行为变化） |
| `test` | 测试相关 |
| `docs` | 文档 |
| `chore` | 构建、依赖、杂项 |

## 调试技巧

```php
// 看编译产物长什么样
$compiled = $router->compile();
print_r($compiled->toArray());

// 看某条路由为什么不可缓存
print_r($compiled->getUncacheableRoutes());

// 看匹配结果
$result = $dispatcher->match('GET', '/users/1');
print_r($result->toArray());     // 闭包处理器会被脱敏

// 看 FastRoute 原始输出
print_r($dispatcher->dispatch('GET', '/users/1'));

// 看当前运行时
echo Runtime::host(), ' / ', Runtime::detect(), PHP_EOL;
```

## 发布

见 [RELEASE.md](RELEASE.md)。简要流程：

1. 更新 `CHANGELOG.md`
2. `composer ci` 全绿
3. 提交并打 tag：`git tag -a v2.0.0 -m "Release v2.0.0"`
4. `git push origin main --tags`
5. Packagist 由 webhook 自动同步

## 参考资料

- [FastRoute](https://github.com/nikic/FastRoute)
- [PSR-15: HTTP Server Request Handlers](https://www.php-fig.org/psr/psr-15/)
- [W3C Trace Context](https://www.w3.org/TR/trace-context/)
- [PHP Fibers RFC](https://wiki.php.net/rfc/fibers)
- [PHPStan 文档](https://phpstan.org/user-guide/getting-started)
