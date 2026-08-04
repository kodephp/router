# Changelog

所有重要的版本变更都将记录在此文件中。

本项目遵循 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.0.0/) 规范，
版本管理遵循 [语义化版本 2.0.0](https://semver.org/lang/zh-CN/)。

## [2.0.0] - 2026-08-04

面向常驻内存与并发时代的重构版本：**编译期与运行期彻底分离**，路由表一次编译、多进程共享，原生适配 Fiber 协程 / 多线程 / 多进程与分布式链路透传。

### Added

**编译期 / 运行期分离**

- `CompiledRoutes`：不可变、可序列化的编译产物。FastRoute 调度数据中注册的是 int 路由 ID 而非真实处理器，因此调度数据永远是标量结构，可 `var_export` / `json_encode` / 跨进程跨线程传递。
- `MatchResult`：不可变匹配结果，`withParams()` / `withMiddleware()` / `withHandler()` 派生新实例，可安全地在中间件之间传递。
- `RouteCollection`：可合并、可指纹、可编译的路由集合，实现 `IteratorAggregate` 与 `Countable`。
- `Router::compile()` / `fingerprint()`：产出编译结果与 sha256 路由表指纹，路由变更自动失效内部缓存。
- `Dispatcher::match()`：只匹配不执行的新入口，无状态，可被任意协程 / 线程共享。

**路由缓存层 `Cache\`**

- `RouteCacheInterface`：缓存契约，核心方法 `remember()`。
- `FileRouteCache`：`var_export` 落盘 + OPcache 常驻，写入走临时文件原子替换，可选写后 `opcache_invalidate()`。
- `ApcuRouteCache`：APCu 缓存，供 PHP-FPM 多进程共享路由表，支持键前缀隔离与 TTL。
- `NullRouteCache`：空实现，`Application` 默认值。
- `Application::setCache()` / `boot()`：设置缓存与 worker 预热，缓存键内嵌路由指纹，改路由自动 miss。

**并发层 `Concurrency\`**

- `Runtime`：运行时探测（Swoole / Swow / Fiber / 线程 / 进程 / FPM / CLI），提供 `executionId()`、`nodeId()`、`isLongRunning()` 等能力探针，结果内部缓存。
- `RouteContext`：协程安全的"当前路由"上下文，按 `executionId()` 分桶，提供 `run()` 作用域执行与 `flush()` / `count()` 泄漏排查。装有 `kode/context` 时自动改用其上下文容器。
- `ConcurrentMatcher`：基于 Fiber 的批量并发匹配与执行，支持 `matchAll()` / `handleAll()` / `mapConcurrently()` / 静态 `runTasks()`，键序稳定、并发可限流、异常语义可选，无 Fiber 环境自动降级为串行。

**分布式层 `Distributed\`**

- `TraceContext`：W3C Trace Context 完整实现，支持 `fromHeaders()` 恢复、`child()` 派生子 span、`toHeaders()` 注入下游，可与 OpenTelemetry / Jaeger / SkyWalking 对接。
- `RouteManifest`：路由表指纹清单，随响应头下发 `X-Kode-Route-Fingerprint` / `-Version` / `-Node`，用于灰度发布时的跨节点路由一致性校验。

**生态集成层 `Integration\`**

- `RoutingMiddleware`：PSR-15 路由中间件，匹配结果写入请求属性与 `RouteContext`，405 自动补 `Allow` 头，可选下发路由指纹响应头。
- `MiddlewareBridge`：`kode/middleware` 软桥接，全程 `class_exists` 探测，缺包时 `isAvailable()` 返回 `false` 而非致命错误。

**其他**

- `HandlerResolver`：统一解析闭包 / `Class@method` / `Class::method` / `[类, 方法]` / 可调用对象五种处理器写法，参数按"名称优先、位置兜底"绑定，支持鸭子类型的 PSR-11 容器装配。
- `UrlGenerator`：独立的 URL 生成器，校验 `where()` 约束与必填参数，支持可选段省略、多余参数转查询串、`basePath` 前缀。
- 异常体系：新增 `RouterException` 基类与 `RouteNotFoundException`、`MethodNotAllowedException`、`InvalidRouteException`、`UrlGenerationException`、`RouteCacheException`。
- `Route::where()` / `defaults()` / `getParameterNames()` / `getCompiledPath()` / `isCacheable()`。
- `Router::mount()`：挂载子模块路由集合，支持统一前缀与中间件。
- `Router::any()` / `map()` / `head()` / `options()`。
- 新增文档 `docs/CONCURRENCY.md`（并发与分布式实践）。

### Changed

- **`Dispatcher` 构造参数**改为 `CompiledRoutes|RouteCollector`。传 `RouteCollector` 进入 1.x 兼容模式，行为不变。
- **推荐入口从 `dispatch()` 改为 `match()`**，返回语义清晰的 `MatchResult`；`dispatch()` 保留，仍返回 FastRoute 原始三元组。
- **`Request` / `Response` 改为 `final`**，消除 `new static` 的类型不安全问题。
- **`Router::group()` 回调签名**扩展为 `fn(RouteGroup $group, Router $router)`，同时支持 1.x 与 2.x 两种注册风格；新增 `$middleware` 参数。
- `Application` 的分发器改为惰性构建并只读复用，不再每次请求重建。
- `RouteManifest::toHeaders()` 输出**完整** sha256 指纹（此前为截断值，会导致跨节点比对误判）；截断值改由 `getShortFingerprint()` 提供，仅供日志展示。
- 依赖升级：PHPUnit 支持 `^11.0 || ^12.0`；新增开发期依赖 `nyholm/psr7` 与 PSR HTTP 相关接口包。
- `composer.json` 的 `suggest` 全面补充 kode 生态与可选扩展说明；分支别名指向 `2.x-dev`。
- 文档全面重写：README、`docs/OVERVIEW.md`、`docs/API.md`、`docs/DEVELOPMENT.md`、`docs/FAQ.md`、`docs/DEPENDENCIES.md`。

### Removed

- 移除包内无用文件 `pkgr.yaml`、`php.ini`。
- `.gitignore` 新增忽略：`composer.lock`（库不锁版本）、本地开发规则与技能目录（`.workbuddy/`、`.cursor/`、`.claude/`、`CLAUDE.md`、`AGENTS.md` 等）、路由缓存产物、覆盖率与构建产物。

### Fixed

- 修复 `HandlerResolver` 中 `{$instance::class}` 字符串插值导致的语法错误。
- 修复 `TraceContext::randomHex()` 在长度参数小于 2 时 `random_bytes()` 收到非法参数的问题。
- 修复 `ConcurrentMatcher::drain()` 中 Fiber 终止态的重复检查与异常语义：`captureErrors` 为 `false` 时异常正确向上抛出，为 `true` 时作为结果值返回。
- 修复路由指纹截断导致的分布式一致性误判。

### Security

- 所有生态集成改为软探测，不再存在因可选依赖缺失而暴露内部错误的路径。

### 质量基线

- 158 个单元测试全部通过（3 个因可选依赖缺失而跳过）
- PHPStan level 8 零错误
- PSR-12 代码风格

### 升级指南

多数 1.x 代码无需修改即可运行。完整迁移说明见 [README](README.md#从-1x-迁移)。

```php
// 1.x 写法继续可用
$app = new Application();
$app->get('/users/{id}', $handler, 'user.show');
$app->send();

// 想吃到 2.0 的性能红利，加两行
$app->setCache(new FileRouteCache(__DIR__ . '/runtime/routes'));
$app->boot();
```

---

## [1.0.0] - 2026-08-03

### Added

- 初始版本发布
- 核心路由组件：`Router`、`Route`、`RouteGroup`、`Dispatcher`
- 应用入口：`Application`
- 请求对象：`Request`
- 响应对象：`Response`
- 中间件接口：`MiddlewareInterface`
- 命名路由与路由组（前缀、中间件、嵌套）
- 完整的中文注释和文档
- 32 个单元测试

---

## 版本说明

### 语义化版本

- **主版本号**：不兼容的 API 修改
- **次版本号**：向下兼容的功能性新增
- **修订号**：向下兼容的问题修正

### 变更记录类型

- **Added**：新增功能
- **Changed**：现有功能的修改
- **Deprecated**：即将废弃的功能
- **Removed**：已移除的功能
- **Fixed**：Bug 修复
- **Security**：安全相关修复

[2.0.0]: https://github.com/kodephp/router/releases/tag/v2.0.0
[1.0.0]: https://github.com/kodephp/router/releases/tag/v1.0.0
