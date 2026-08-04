# 项目总览

## 目录结构

```
router/
├── composer.json                    # 包定义
├── README.md                        # 项目说明
├── CHANGELOG.md                     # 版本变更记录
├── CODE_STYLE.md                    # 代码风格规范
├── CONTRIBUTING.md                  # 贡献指南
├── SECURITY.md                      # 安全政策
├── LICENSE                          # MIT 许可证
├── phpunit.xml                      # PHPUnit 配置
├── phpstan.neon                     # PHPStan 配置（level 8）
├── .php-cs-fixer.dist.php           # PHP-CS-Fixer 配置
├── .editorconfig                    # 编辑器配置
├── .gitignore                       # Git 忽略规则
├── src/                             # 源代码
│   ├── Application.php              # 应用入口
│   ├── Router.php                   # 路由定义与注册
│   ├── RouteCollection.php          # 路由集合（可合并 / 可指纹 / 可编译）
│   ├── Route.php                    # 单条路由
│   ├── RouteGroup.php               # 路由组
│   ├── CompiledRoutes.php           # 编译产物（不可变 / 可序列化）
│   ├── Dispatcher.php               # 运行期匹配与执行（无状态）
│   ├── MatchResult.php              # 匹配结果（不可变）
│   ├── UrlGenerator.php             # 命名路由 URL 生成
│   ├── HandlerResolver.php          # 处理器解析与参数绑定
│   ├── Request.php                  # HTTP 请求对象
│   ├── Response.php                 # HTTP 响应对象
│   ├── MiddlewareInterface.php      # 中间件接口
│   ├── Cache/                       # 路由缓存层
│   │   ├── RouteCacheInterface.php  # 缓存契约
│   │   ├── NullRouteCache.php       # 空实现（默认）
│   │   ├── FileRouteCache.php       # 文件缓存（OPcache 友好）
│   │   └── ApcuRouteCache.php       # APCu 缓存（FPM 多进程共享）
│   ├── Concurrency/                 # 并发层
│   │   ├── Runtime.php              # 运行时探测与执行单元标识
│   │   ├── RouteContext.php         # 协程安全的路由上下文
│   │   └── ConcurrentMatcher.php    # Fiber 批量并发匹配 / 执行
│   ├── Distributed/                 # 分布式层
│   │   ├── TraceContext.php         # W3C Trace Context 链路透传
│   │   └── RouteManifest.php        # 路由指纹与跨节点一致性校验
│   ├── Integration/                 # 生态集成层（全部软探测）
│   │   ├── RoutingMiddleware.php    # PSR-15 路由中间件
│   │   └── MiddlewareBridge.php     # 对接 kode/middleware
│   └── Exception/                   # 异常体系
│       ├── RouterException.php
│       ├── RouteNotFoundException.php
│       ├── MethodNotAllowedException.php
│       ├── InvalidRouteException.php
│       ├── UrlGenerationException.php
│       └── RouteCacheException.php
├── tests/                           # 测试代码（158 个测试）
│   ├── ApplicationTest.php
│   ├── RouterTest.php
│   ├── RouteTest.php
│   ├── RouteGroupTest.php
│   ├── RouteCollectionTest.php
│   ├── CompiledRoutesTest.php
│   ├── DispatcherTest.php
│   ├── MatchResultTest.php
│   ├── UrlGeneratorTest.php
│   ├── HandlerResolverTest.php
│   ├── RequestTest.php
│   ├── ResponseTest.php
│   ├── Cache/
│   ├── Concurrency/
│   ├── Distributed/
│   ├── Integration/
│   └── Fixtures/
├── example/                         # 示例代码
│   └── index.php
└── docs/                            # 文档
    ├── OVERVIEW.md                  # 项目总览（本文）
    ├── API.md                       # 完整 API 参考
    ├── CONCURRENCY.md               # 并发与分布式
    ├── DEVELOPMENT.md               # 开发指南
    ├── FAQ.md                       # 常见问题
    ├── RELEASE.md                   # 发布流程
    ├── DEPENDENCIES.md              # 依赖管理
    ├── phpstan.md                   # 静态分析说明
    └── php-cs-fixer.md              # 代码风格说明
```

## 架构分层

```
定义期            编译期                     运行期
Router      →     CompiledRoutes      →      Dispatcher
RouteCollection   （不可变 / 可序列化）       （只读复用 / 无状态）
                        ↓
                  RouteCache（文件 / APCu）
                  跨进程、跨 worker 共享
```

| 阶段 | 产物 | 生命周期 | 可变性 |
|------|------|----------|--------|
| 定义期 | `Router` / `RouteCollection` / `Route` | 启动阶段 | 可变 |
| 编译期 | `CompiledRoutes` | 进程级，可落盘 | **不可变** |
| 运行期 | `MatchResult` | 单次请求 | **不可变** |

这套分层是所有高级能力的基础：编译产物可序列化 → 可缓存、可跨进程、可跨线程；运行期无状态 → 可并发、免加锁。

## 核心组件

| 组件 | 职责 |
|------|------|
| `Application` | 应用入口，整合路由 / 缓存 / 上下文 / 请求响应 |
| `Router` | 路由定义与注册，产出编译产物 |
| `RouteCollection` | 路由集合，支持合并、指纹、编译 |
| `Route` | 单条路由（方法、路径、处理器、约束、默认值） |
| `RouteGroup` | 路由组（前缀、中间件、无限嵌套） |
| `CompiledRoutes` | 不可变编译产物，可 `var_export` / `json_encode` |
| `Dispatcher` | 运行期匹配与执行，不持有请求级状态 |
| `MatchResult` | 不可变匹配结果，`with*()` 派生新实例 |
| `UrlGenerator` | 命名路由 URL 生成，校验约束与必填参数 |
| `HandlerResolver` | 多形态处理器解析，支持 PSR-11 容器装配 |

## 扩展组件

| 命名空间 | 职责 | 硬依赖 |
|----------|------|--------|
| `Cache\` | 路由编译产物的多级缓存 | 无（APCu 可选） |
| `Concurrency\` | 运行时探测、上下文隔离、Fiber 并发 | 无 |
| `Distributed\` | 链路透传、路由表一致性校验 | 无 |
| `Integration\` | PSR-15 / kode 生态对接 | 无（软探测） |

所有扩展组件都做了软依赖处理：相关包或扩展没安装时静默降级，不会产生致命错误。

## 配置文件

| 文件 | 用途 |
|------|------|
| `composer.json` | Composer 包定义与脚本 |
| `phpunit.xml` | PHPUnit 测试配置（随机顺序、严格模式） |
| `phpstan.neon` | PHPStan level 8 静态分析配置 |
| `.php-cs-fixer.dist.php` | PSR-12 代码风格配置 |
| `.editorconfig` | 编辑器统一配置 |
| `.gitignore` | 忽略依赖、缓存产物与本地开发规则文件 |

## 文档索引

| 文档 | 内容 |
|------|------|
| `README.md` | 项目简介、快速上手、迁移指南 |
| `docs/API.md` | 完整 API 参考 |
| `docs/CONCURRENCY.md` | 多进程 / 多线程 / 协程 / 分布式实践 |
| `docs/DEVELOPMENT.md` | 本地开发与测试 |
| `docs/FAQ.md` | 常见问题 |
| `docs/DEPENDENCIES.md` | 依赖管理 |
| `docs/RELEASE.md` | 发布流程 |
| `CODE_STYLE.md` | 代码风格规范 |
| `CONTRIBUTING.md` | 贡献指南 |
| `SECURITY.md` | 安全政策 |
| `CHANGELOG.md` | 版本变更记录 |
