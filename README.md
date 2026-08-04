# kode/router

[![PHP Version](https://img.shields.io/packagist/php-v/kode/router.svg?style=flat-square)](https://packagist.org/packages/kode/router)
[![Latest Version](https://img.shields.io/packagist/v/kode/router.svg?style=flat-square)](https://packagist.org/packages/kode/router)
[![License](https://img.shields.io/packagist/l/kode/router.svg?style=flat-square)](LICENSE)
[![PHPStan Level](https://img.shields.io/badge/phpstan-level%208-brightgreen.svg?style=flat-square)](phpstan.neon)
[![Tests](https://img.shields.io/badge/tests-158%20passed-brightgreen.svg?style=flat-square)](tests/)

高性能路由组件：**编译期与运行期彻底分离**，路由表一次编译、多进程共享，原生适配 Fiber 协程 / 多线程 / 多进程与分布式链路透传。

底层基于 [FastRoute](https://github.com/nikic/FastRoute)，上层补齐了常驻内存时代真正需要的东西：不可变匹配结果、可序列化的编译产物、协程安全的上下文隔离、跨节点路由一致性校验。

> 名称 `kode` 简洁好记，不与 PHP 关键字冲突，发音类似 "code"。

---

## 为什么是 2.0

1.x 是一个"能用"的路由器：每次请求都要重新遍历路由定义、重新构建 FastRoute 数据。这在 PHP-FPM 下勉强可以接受，但在 Swoole / Swow / ReactPHP 这类常驻进程里就是纯粹的浪费；而在协程环境下，把"当前路由"塞进类静态属性更是会直接串数据。

2.0 重构了整条链路：

```
定义期            编译期                     运行期
Router      →     CompiledRoutes      →      Dispatcher
RouteCollection   （不可变 / 可序列化）       （只读复用 / 无状态）
                        ↓
                  RouteCache（文件 / APCu）
                  跨进程、跨 worker 共享
```

- **编译一次**：`Router::compile()` 产出不可变的 `CompiledRoutes`，可 `var_export` 落盘、可 OPcache 常驻、可 APCu 跨 FPM 进程共享。
- **运行无状态**：`Dispatcher::match()` 返回不可变的 `MatchResult`，不写任何全局状态，天然可并发。
- **上下文隔离**：`RouteContext` 按 executionId 分桶，Fiber / 协程 / 线程之间互不串扰。
- **可观测**：路由表有 sha256 指纹，配合 `RouteManifest` 可在网关与节点之间校验路由表是否一致。

---

## 特性

| 特性 | 说明 |
|------|------|
| ⚡ **编译期分离** | 路由一次编译成不可变产物，运行期零重建开销 |
| 💾 **多级缓存** | 文件缓存（OPcache 友好）/ APCu 缓存（FPM 多进程共享）/ 空实现 |
| 🧵 **并发原生** | Fiber 批量并发匹配、协程安全上下文、多进程 worker 预热 |
| 🌐 **分布式就绪** | W3C Trace Context 链路透传 + 路由指纹一致性校验 |
| 🏷️ **命名路由** | 名称反查路径、生成 URL、正则约束、可选段、默认值 |
| 📁 **路由组** | 前缀、中间件、无限嵌套、跨 Router 挂载 |
| 🔌 **PSR-15** | 提供 `RoutingMiddleware`，可直接插入任意 PSR-15 管道 |
| 🧩 **零硬依赖** | 生态集成全部软探测，缺包自动降级，不装也能跑 |
| 🐘 **现代 PHP** | PHP 8.3+，PHPStan level 8 全绿，158 个单元测试 |

---

## 安装

```bash
composer require kode/router
```

要求 PHP 8.3+ 与 Composer 2.0+。唯一硬依赖是 `nikic/fast-route`。

可选扩展与配套包（装了就自动启用，不装静默降级）：

| 可选项 | 作用 |
|--------|------|
| `ext-apcu` | `ApcuRouteCache`，在 PHP-FPM 多进程之间共享路由表 |
| `ext-opcache` | 让 `FileRouteCache` 生成的缓存文件常驻共享内存 |
| `kode/context` | 协程 / 线程 / 进程上下文隔离与链路透传 |
| `kode/middleware` | PSR-15 中间件管道，用 `MiddlewareBridge` 对接 |
| `kode/di` | 控制器构造函数自动装配 |
| `psr/http-server-middleware` | 使用 `RoutingMiddleware` 时需要 |

---

## 快速开始

```php
<?php

require __DIR__ . '/vendor/autoload.php';

use Kode\Router\Application;
use Kode\Router\Response;

$app = new Application();

$app->get('/', fn () => Response::html('<h1>Hello kode/router</h1>'), 'home');
$app->get('/hello/{name}', fn (string $name) => "你好，{$name}！", 'hello');
$app->get('/users/{id:\d+}', fn (int $id) => Response::json(['id' => $id]), 'user.show');

$app->send();
```

参数按**名称优先、位置兜底**绑定：处理器形参名与路径参数同名时按名传入，否则按顺序传入。

### 路由定义的几种写法

```php
$router = new Kode\Router\Router();

// 闭包
$router->get('/ping', fn () => 'pong');

// Class@method（推荐：可缓存）
$router->get('/users/{id}', 'App\Controller\UserController@show', 'user.show');

// 静态方法
$router->get('/health', 'App\Health::check');

// 数组形式
$router->post('/users', [App\Controller\UserController::class, 'store']);

// 可调用对象
$router->get('/stats', App\Action\Stats::class);

// 多方法 / 任意方法
$router->map(['GET', 'POST'], '/form', $handler);
$router->any('/catch-all/{path:.+}', $handler);
```

> **可缓存性**：只有字符串或 `[类名, 方法名]` 形式的处理器才能被 `var_export` 落盘。闭包路由无法缓存，`CompiledRoutes::isCacheable()` 会返回 `false`，`getUncacheableRoutes()` 会告诉你是哪几条。

### 约束、可选段与默认值

```php
$router->get('/posts/{slug}/{page}', $handler, 'post.page')
    ->where('slug', '[a-z0-9\-]+')
    ->where(['page' => '\d+'])
    ->defaults(['page' => 1]);

// 可选段（FastRoute 原生语法）
$router->get('/archive[/{year:\d{4}}]', $handler, 'archive');
```

### 路由组与挂载

```php
$router->group('api', function ($group) {
    $group->prefix('/api/v1');
    $group->middleware(AuthMiddleware::class);

    $group->route(['GET'], '/users', 'App\Api\UserController@index', 'api.users');

    $group->group('admin', function ($sub) {
        $sub->prefix('/admin');
        $sub->route(['GET'], '/stats', 'App\Api\AdminController@stats', 'api.admin.stats');
    });
});

// 把另一个 Router / RouteCollection 挂载进来（模块化拆分）
$router->mount($moduleRouter, '/module', [ModuleMiddleware::class]);
```

---

## 编译期与运行期

这是 2.0 的核心。所有高级能力都建立在这一层之上。

```php
use Kode\Router\Router;
use Kode\Router\Dispatcher;

$router = new Router();
$router->get('/users/{id:\d+}', 'App\Controller\UserController@show', 'user.show');

// —— 编译期：一次性 ——
$compiled = $router->compile();          // CompiledRoutes，不可变
$compiled->count();                      // 路由条数
$compiled->getFingerprint();             // sha256 路由表指纹
$compiled->isCacheable();                // 是否全部可序列化
$payload = $compiled->toArray();         // 纯数组，可 var_export / json_encode

// —— 运行期：每请求 ——
$dispatcher = new Dispatcher($compiled);
$result = $dispatcher->match('GET', '/users/42');

if ($result->isFound()) {
    $result->getName();        // 'user.show'
    $result->getParams();      // ['id' => '42']
    $result->getMiddleware();  // 该路由的中间件栈
    $result->getRouteId();     // 编译期分配的 int 路由 ID
}
```

`MatchResult` 完全不可变，`withParams()` / `withMiddleware()` / `withHandler()` 都返回新实例，可以放心在中间件之间传递。

三种状态：

```php
$result->isFound();             // 命中
$result->isNotFound();          // 404
$result->isMethodNotAllowed();  // 405，getAllowedMethods() 拿到允许的方法
```

如果只想要"匹配 + 执行"一步到位：

```php
$body = $dispatcher->handle('GET', '/users/42');   // 未命中抛 RouteNotFoundException
```

---

## 路由缓存（多进程 / worker 预热）

编译产物可以直接落盘复用，避免每个 worker 重复编译。

### 文件缓存（配合 OPcache，推荐）

```php
use Kode\Router\Application;
use Kode\Router\Cache\FileRouteCache;

$app = new Application();
$app->setCache(new FileRouteCache(__DIR__ . '/runtime/routes'));

require __DIR__ . '/routes/web.php';   // 定义路由

// 在 worker 启动回调里预热，首个请求就不用承担编译开销
$app->boot();
```

缓存文件由 `var_export` 生成，OPcache 会把它编译进共享内存，跨请求、跨 worker 零解析成本。

### APCu 缓存（PHP-FPM 多进程共享）

```php
use Kode\Router\Cache\ApcuRouteCache;

if (ApcuRouteCache::isSupported()) {
    $app->setCache(new ApcuRouteCache('kode.router.'));
}
```

### 手动控制

```php
$cache = new FileRouteCache(__DIR__ . '/runtime/routes');

$compiled = $cache->remember('app.routes', fn () => $router->compile());

$cache->has('app.routes');
$cache->forget('app.routes');
$cache->clear();
```

> 路由定义变了怎么办？`Application` 用的缓存键里带了路由表指纹（`Router::fingerprint()`），路由一变指纹就变，自动 miss 重编译，不需要手工清缓存。

---

## 并发：Fiber / 协程 / 多线程 / 多进程

### 运行时探测

```php
use Kode\Router\Concurrency\Runtime;

Runtime::detect();            // swoole / swow / fiber / thread / process / fpm / cli
Runtime::host();              // 宿主环境描述
Runtime::inCoroutine();       // 当前是否在协程里
Runtime::supportsFibers();    // PHP 8.1+ 恒为 true
Runtime::isLongRunning();     // 是否常驻内存（决定要不要预热缓存）
Runtime::executionId();       // 当前执行单元 ID（协程/线程/进程唯一）
Runtime::nodeId();            // 节点标识，用于分布式
```

### 批量并发匹配

`ConcurrentMatcher` 用 Fiber 轮转驱动一批请求，适合网关侧的批量预匹配、压测、路由预热。

```php
use Kode\Router\Concurrency\ConcurrentMatcher;

$matcher = new ConcurrentMatcher($dispatcher);

// 批量匹配（纯匹配，不执行处理器）
$results = $matcher->matchAll([
    'a' => ['GET', '/users/1'],
    'b' => ['POST', '/users'],
    'c' => ['GET', '/not-exist'],
]);
// ['a' => MatchResult, 'b' => MatchResult, 'c' => MatchResult]

// 批量执行，限制并发数，异常作为结果值返回
$responses = $matcher->handleAll(
    requests: $requests,
    concurrency: 16,
    captureErrors: true
);

// 匹配后并发执行自定义回调（批量鉴权、批量预取等）
$mapped = $matcher->mapConcurrently(
    $requests,
    fn (MatchResult $result) => $result->isFound() ? $result->getName() : null,
    concurrency: 8
);

// 通用并发工具（任意任务）
$out = ConcurrentMatcher::runTasks([
    'x' => fn () => doSomething(),
    'y' => fn () => doSomethingElse(),
], concurrency: 4, captureErrors: true);
```

结果始终按入参键顺序返回。无 Fiber 环境自动退化为串行执行，行为一致。

### 协程安全的上下文

不要用静态属性存"当前路由"——协程会串。用 `RouteContext`：

```php
use Kode\Router\Concurrency\RouteContext;

// 框架侧写入（Application 默认已开启，可用 trackContext(false) 关闭）
RouteContext::set($matchResult);

// 业务侧任意深度读取
RouteContext::current();         // ?MatchResult
RouteContext::name();            // 当前路由名
RouteContext::param('id');       // 当前路径参数
RouteContext::has();

// 作用域执行，退出自动还原
$value = RouteContext::run($matchResult, function () {
    return RouteContext::name();
});

// 协程退出时清理，避免长生命周期进程内存堆积
RouteContext::clear();
```

内部按 `Runtime::executionId()` 分桶存储；若安装了 `kode/context`，会自动改用它的上下文容器，与框架其他组件共用同一份协程上下文。

### 多进程模型的推荐姿势

```php
// master：编译一次，落盘
$router = require __DIR__ . '/routes/web.php';
(new FileRouteCache($dir))->set('app.routes', $router->compile());

// worker onStart：读缓存，不编译
$compiled = (new FileRouteCache($dir))->get('app.routes');
$dispatcher = new Dispatcher($compiled);

// worker onRequest：只 match，无状态、无锁
$result = $dispatcher->match($method, $path);
```

---

## 分布式

### W3C Trace Context 链路透传

```php
use Kode\Router\Distributed\TraceContext;

// 入口：从上游请求头恢复，没有就新建
$trace = TraceContext::fromHeaders($request->getHeaders());

$trace->getTraceId();       // 32 位 hex，全链路唯一
$trace->getSpanId();        // 16 位 hex，当前跨度
$trace->getParentSpanId();  // 上游跨度
$trace->isSampled();
$trace->isRoot();

// 调用下游：派生子 span 并注入请求头
$child = $trace->child();
$headers = $child->toHeaders();   // ['traceparent' => '00-...', 'tracestate' => '...']

// 写入当前上下文，供日志组件读取
$trace->publish();
```

完全遵循 W3C Trace Context 规范，可与 OpenTelemetry、Jaeger、SkyWalking 等直接对接。

### 路由表一致性校验

多节点部署时，最难查的问题是"某台机器路由表是旧的"。`RouteManifest` 把路由指纹随响应头带出来：

```php
use Kode\Router\Distributed\RouteManifest;

$manifest = RouteManifest::fromCompiled($compiled, version: '2.0.0');

$manifest->getFingerprint();        // 完整 sha256
$manifest->getShortFingerprint();   // 前 16 位，日志友好
$manifest->getRouteCount();
$manifest->getNode();

// 随响应下发
foreach ($manifest->toHeaders() as $name => $value) {
    $response = $response->withHeader($name, $value);
}
// X-Kode-Route-Fingerprint / X-Kode-Route-Version / X-Kode-Route-Node

// 网关侧比对
$upstream = RouteManifest::fromHeaders($responseHeaders);

if ($upstream !== null && !$upstream->matches($localManifest)) {
    // 路由表不一致：该节点需要重载
}

// 或直接和本地编译产物比对
$upstream?->matchesCompiled($compiled);
```

---

## PSR-15 集成

```php
use Kode\Router\Integration\RoutingMiddleware;

$middleware = new RoutingMiddleware($dispatcher);

// 插入任意 PSR-15 管道最前面
$pipeline->pipe($middleware);
```

它只做匹配，把 `MatchResult` 写进请求属性后交给下游：

```php
final class ControllerHandler implements RequestHandlerInterface
{
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $result = RoutingMiddleware::fromRequest($request);

        if ($result === null || !$result->isFound()) {
            return new Response(404);
        }

        // 执行处理器 ...
    }
}
```

> PSR-7 消息是不可变的：`withAttribute()` 返回**新对象**，所以属性只能在下游拿到的那个 `$request` 上读取。

中间件本身不产生 404/405 响应，只负责匹配并透传结果，由下游决定怎么响应；405 时会自动补上 `Allow` 响应头。构造参数还可以控制两件事：

```php
new RoutingMiddleware(
    dispatcher: $dispatcher,
    exposeParams: true,                              // 路径参数逐个写进请求属性
    manifest: RouteManifest::fromCompiled($compiled) // 自动下发路由指纹响应头
);
```

它同时会把匹配结果写入 `RouteContext`，请求结束自动清理，因此协程环境下也能在任意深度读到当前路由。

### 对接 kode/middleware

```php
use Kode\Router\Integration\MiddlewareBridge;

if (MiddlewareBridge::isAvailable()) {
    $pipeline->setMatcher(MiddlewareBridge::matcher($dispatcher));
}
```

`MiddlewareBridge` 全程软探测，`kode/middleware` 没装时 `isAvailable()` 返回 `false`，不会有任何致命错误。

---

## URL 生成

```php
$url = $dispatcher->url('user.show', ['id' => 42]);
// /users/42

$url = $router->url('post.page', ['slug' => 'hello'], ['ref' => 'home']);
// /posts/hello/1?ref=home   （page 走了 defaults）

// 带 basePath
$generator = $router->createUrlGenerator('/app');
$generator->generate('user.show', ['id' => 42]);   // /app/users/42
```

参数不满足 `where()` 约束、缺少必填参数、路由名不存在时，均抛出 `UrlGenerationException`。

---

## 异常

| 异常 | 触发时机 |
|------|----------|
| `RouterException` | 所有路由异常的基类 |
| `RouteNotFoundException` | `handle()` 未匹配到路由 |
| `MethodNotAllowedException` | 路径匹配但 HTTP 方法不允许（带 `getAllowedMethods()`） |
| `InvalidRouteException` | 路由定义非法、处理器无法解析 |
| `UrlGenerationException` | URL 生成失败 |
| `RouteCacheException` | 缓存读写失败、路由不可序列化 |

---

## API 速查

### 核心

| 类 | 职责 |
|----|------|
| `Application` | 应用入口，整合路由 / 缓存 / 上下文 / 请求响应 |
| `Router` | 路由定义与注册，产出 `CompiledRoutes` |
| `RouteCollection` | 路由集合，可合并、可指纹、可编译 |
| `Route` | 单条路由（方法、路径、处理器、约束、默认值） |
| `RouteGroup` | 路由组（前缀、中间件、嵌套） |
| `CompiledRoutes` | **不可变**编译产物，可序列化 |
| `Dispatcher` | 运行期匹配与执行，无状态 |
| `MatchResult` | **不可变**匹配结果 |
| `UrlGenerator` | 命名路由 URL 生成 |
| `HandlerResolver` | 处理器解析与参数绑定，支持 PSR-11 容器 |

### 并发 / 缓存 / 分布式 / 集成

| 类 | 职责 |
|----|------|
| `Concurrency\Runtime` | 运行时探测与执行单元标识 |
| `Concurrency\RouteContext` | 协程安全的当前路由上下文 |
| `Concurrency\ConcurrentMatcher` | Fiber 批量并发匹配与执行 |
| `Cache\RouteCacheInterface` | 路由缓存契约 |
| `Cache\FileRouteCache` | 文件缓存（OPcache 友好） |
| `Cache\ApcuRouteCache` | APCu 缓存（FPM 多进程共享） |
| `Cache\NullRouteCache` | 空实现（默认，不缓存） |
| `Distributed\TraceContext` | W3C Trace Context 链路透传 |
| `Distributed\RouteManifest` | 路由表指纹与跨节点一致性校验 |
| `Integration\RoutingMiddleware` | PSR-15 路由中间件 |
| `Integration\MiddlewareBridge` | 对接 `kode/middleware` 的软桥接 |

完整签名见 [docs/API.md](docs/API.md)。

---

## 从 1.x 迁移

2.0 保留了 1.x 的全部常用入口，`Application` / `Router` / `Dispatcher` 的旧写法基本可以直接跑。需要注意的差异：

| 变化 | 说明 | 处理方式 |
|------|------|----------|
| `Dispatcher` 构造参数 | 现在接受 `CompiledRoutes` 或 `RouteCollector` | 传 `RouteCollector` 会进入 1.x 兼容模式，行为不变 |
| 推荐入口从 `dispatch()` 改为 `match()` | `match()` 返回 `MatchResult`，语义更清晰 | `dispatch()` 仍保留，返回 FastRoute 原始三元组 |
| `Request` / `Response` 变为 `final` | 避免 `new static` 的类型不安全 | 用组合替代继承 |
| 当前路由不再存静态属性 | 协程安全要求 | 改用 `RouteContext::current()` |
| 新增编译步骤 | `Router::compile()` | 不显式调用也可以，`Application` 会惰性编译 |

最小改动升级：

```php
// 1.x 写法，2.0 继续可用
$app = new Application();
$app->get('/users/{id}', $handler, 'user.show');
$app->send();

// 想吃到 2.0 的性能红利，加两行
$app->setCache(new FileRouteCache(__DIR__ . '/runtime/routes'));
$app->boot();
```

---

## 开发

```bash
composer install

composer test           # 运行测试
composer test-coverage  # 覆盖率报告
composer phpstan        # 静态分析（level 8）
composer cs-fix         # 修复代码风格
composer cs-check       # 检查代码风格
composer ci             # 完整 CI 流程
```

当前质量基线：**158 个测试全部通过，PHPStan level 8 零错误**。

代码规范：PSR-12、PHP 8.3+ 特性、完整中文注释、严格类型声明。详见 [CODE_STYLE.md](CODE_STYLE.md)。

---

## 文档

- [概览](docs/OVERVIEW.md)
- [API 参考](docs/API.md)
- [并发与分布式](docs/CONCURRENCY.md)
- [开发指南](docs/DEVELOPMENT.md)
- [常见问题](docs/FAQ.md)
- [依赖说明](docs/DEPENDENCIES.md)
- [发布流程](docs/RELEASE.md)

---

## 许可证

MIT License，详见 [LICENSE](LICENSE)。

## 贡献

欢迎贡献，请先阅读 [CONTRIBUTING.md](CONTRIBUTING.md)。安全问题请按 [SECURITY.md](SECURITY.md) 的流程私下反馈。
