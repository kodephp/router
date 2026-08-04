# 依赖管理

`kode/router` 坚持**最小硬依赖**：核心功能只依赖 PHP 与 FastRoute，其余全部为可选增强，缺失时自动降级。

## 运行时依赖

| 包名 | 版本 | 说明 |
|------|------|------|
| `php` | ^8.3 | 需要 readonly 属性、枚举、Fiber 等特性 |
| `nikic/fast-route` | ^1.3 | 底层路由匹配引擎 |

就这两个。没有 PSR 包、没有容器、没有日志组件的硬依赖。

## 开发依赖

| 包名 | 版本 | 说明 |
|------|------|------|
| `phpunit/phpunit` | ^11.0 \|\| ^12.0 | 测试框架 |
| `phpstan/phpstan` | ^1.10 | 静态分析（level 8） |
| `friendsofphp/php-cs-fixer` | ^3.0 | 代码风格 |
| `nyholm/psr7` | ^1.8 | 测试 PSR-15 集成用的 PSR-7 实现 |
| `psr/http-message` | ^1.1 \|\| ^2.0 | PSR-7 接口 |
| `psr/http-server-handler` | ^1.0 | PSR-15 请求处理器接口 |
| `psr/http-server-middleware` | ^1.0 | PSR-15 中间件接口 |

PSR 相关包只在开发期用于测试 `Integration\RoutingMiddleware`。生产环境如果要用这个中间件，需要自己引入 `psr/http-server-middleware`。

## 可选增强（suggest）

装了就自动启用，不装静默降级：

| 可选项 | 作用 | 检测方式 |
|--------|------|----------|
| `ext-apcu` | `ApcuRouteCache`，FPM 多进程共享路由表 | `extension_loaded('apcu')` |
| `ext-opcache` | 让 `FileRouteCache` 的缓存文件常驻共享内存 | 无需检测，透明生效 |
| `kode/context` | 协程 / 线程 / 进程上下文隔离与链路透传 | `class_exists()` |
| `kode/middleware` | PSR-15 中间件管道，用 `MiddlewareBridge` 对接 | `class_exists()` |
| `kode/di` | 控制器构造函数自动装配 | 传入容器即可，鸭子类型 |
| `kode/fibers` | Fiber 协程运行器 | `class_exists()` |
| `kode/parallel` | 多线程运行器（需 `ext-parallel`） | `extension_loaded('parallel')` |
| `kode/process` | 多进程运行器与 worker 管理 | `function_exists('pcntl_fork')` |
| `kode/http` | PSR-7 消息实现与 HTTP 服务端集成 | — |
| `psr/http-server-middleware` | 使用 `RoutingMiddleware` 时必需 | `interface_exists()` |

### 软依赖的实现原则

所有可选集成遵循同一套规则：

1. 只用 `class_exists()` / `interface_exists()` / `extension_loaded()` / `function_exists()` 探测，**绝不 `require`**。
2. 探测结果缓存，避免每请求重复检查。
3. 不可用时走内置的降级实现，行为语义保持一致。
4. 提供显式的可用性查询方法，例如 `MiddlewareBridge::isAvailable()`、`ApcuRouteCache::isSupported()`。

因此本包可以在一个只装了 FastRoute 的最小环境里完整运行。

## 常用命令

```bash
composer install          # 安装依赖
composer update           # 更新全部依赖
composer update nikic/fast-route   # 更新单个依赖
composer outdated         # 查看过时依赖
composer audit            # 安全漏洞检查
composer why <package>    # 查看某个包为何被安装
```

## 关于 composer.lock

本包是**库**（library）而非应用，因此 `composer.lock` 已加入 `.gitignore`，不提交到版本控制。

理由：库需要在使用者声明的各种依赖版本组合下都能工作，锁定文件反而会掩盖版本兼容性问题。CI 中应针对 `--prefer-lowest` 与最新版本分别跑一遍测试：

```bash
composer update --prefer-lowest --prefer-stable && composer test
composer update && composer test
```

应用项目则相反，必须提交 `composer.lock`。

## 版本策略

遵循[语义化版本 2.0.0](https://semver.org/lang/zh-CN/)：

- **主版本号**：不兼容的 API 变更（如 1.x → 2.0）
- **次版本号**：向下兼容的功能新增
- **修订号**：向下兼容的问题修复

`composer.json` 中声明了分支别名 `dev-main` → `2.x-dev`，便于依赖开发版。
