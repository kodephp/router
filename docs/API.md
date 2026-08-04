# API 参考

`kode/router` 2.0 完整 API 文档。命名空间统一为 `Kode\Router`。

- [核心](#核心)
  - [Application](#application)
  - [Router](#router)
  - [RouteCollection](#routecollection)
  - [Route](#route)
  - [RouteGroup](#routegroup)
  - [CompiledRoutes](#compiledroutes)
  - [Dispatcher](#dispatcher)
  - [MatchResult](#matchresult)
  - [UrlGenerator](#urlgenerator)
  - [HandlerResolver](#handlerresolver)
- [缓存 `Cache\`](#缓存-cache)
- [并发 `Concurrency\`](#并发-concurrency)
- [分布式 `Distributed\`](#分布式-distributed)
- [集成 `Integration\`](#集成-integration)
- [HTTP 对象](#http-对象)
- [异常 `Exception\`](#异常-exception)

---

## 核心

### Application

应用入口，把路由、缓存、上下文、请求响应串起来。适合快速上手；需要精细控制时直接用 `Router` + `Dispatcher`。

```php
public function __construct(
    ?Router $router = null,
    ?RouteCacheInterface $cache = null,
    ?HandlerResolver $resolver = null
)
```

| 方法 | 返回 | 说明 |
|------|------|------|
| `getRouter()` | `Router` | 获取路由器 |
| `getDispatcher()` | `Dispatcher` | 获取分发器（惰性编译，构建后只读复用） |
| `boot()` | `self` | 预热：立即完成编译并构建分发器 |
| `setCache(RouteCacheInterface $cache)` | `self` | 设置路由缓存，会重置已构建的分发器 |
| `trackContext(bool $enabled)` | `self` | 开关"当前路由写入协程上下文" |
| `middleware(callable $middleware)` | `self` | 添加全局中间件，签名 `fn(Request, Response): void` |
| `run(?Request $request = null)` | `Response` | 运行应用，返回响应对象 |
| `send(?Response $response = null)` | `void` | 运行并输出响应 |

`Application` 通过 `__call` 把 `get/post/put/delete/patch/head/options/any/map/group/mount` 等方法转发给内部 `Router`，所以可以直接写：

```php
$app = new Application();
$app->get('/users/{id}', 'App\UserController@show', 'user.show');
```

缓存键包含路由指纹，路由一变自动失效：`'kode.router.' . substr($router->fingerprint(), 0, 16)`。

---

### Router

路由定义与注册，产出 `CompiledRoutes`。

```php
public function __construct(?RouteCollector $collector = null)
```

#### 注册路由

| 方法 | 说明 |
|------|------|
| `get(string $path, mixed $handler, ?string $name = null): Route` | 注册 GET |
| `post(...)` / `put(...)` / `delete(...)` / `patch(...)` / `head(...)` / `options(...)` | 同上，对应各方法 |
| `any(string $path, mixed $handler, ?string $name = null): Route` | 注册全部常用方法 |
| `map(array $methods, string $path, mixed $handler, ?string $name = null): Route` | 注册指定方法集合 |
| `addRoute(array $methods, string $path, mixed $handler, ?string $name = null): Route` | 底层注册入口 |

处理器支持五种写法：

```php
fn () => 'x'                                  // 闭包（不可缓存）
'App\Controller\UserController@show'          // Class@method（推荐）
'App\Health::check'                           // 静态方法
[App\Controller\UserController::class, 'show'] // 数组
App\Action\Stats::class                        // 可调用对象（__invoke）
```

#### 分组与挂载

```php
public function group(
    string $name,
    callable $callback,
    string $prefix = '',
    array $middleware = []
): RouteGroup

public function mount(RouteCollection|Router $routes, string $prefix = '', array $middleware = []): self
```

回调同时收到 `(RouteGroup $group, Router $router)`，两种风格都支持：

```php
// 1.x 风格：在 RouteGroup 上注册
$router->group('api', function (RouteGroup $group) {
    $group->prefix('/api/v1');
    $group->route(['GET'], '/users', 'App\Api\UserController@index', 'api.users');
});

// 2.x 风格：在 Router 上注册，前缀与中间件自动继承
$router->group('api', function ($group, Router $r) {
    $r->get('/users', 'App\Api\UserController@index', 'api.users');
}, '/api/v1', [AuthMiddleware::class]);
```

#### 查询与编译

| 方法 | 返回 | 说明 |
|------|------|------|
| `getCollection()` | `RouteCollection` | 获取路由集合 |
| `getRoutes()` | `array<int, Route>` | 全部路由 |
| `getGroups()` | `array<string, RouteGroup>` | 全部分组 |
| `getGroup(string $name)` | `?RouteGroup` | 按名取分组 |
| `resolveRoute(string $name)` | `?string` | 名称 → 路径模板 |
| `resolveAll()` | `array<string, string>` | 全部命名路由 |
| `compile()` | `CompiledRoutes` | **编译**，结果内部缓存，路由变更自动失效 |
| `createDispatcher(?HandlerResolver $resolver = null)` | `Dispatcher` | 基于编译产物创建分发器 |
| `createUrlGenerator(string $basePath = '')` | `UrlGenerator` | 创建 URL 生成器 |
| `url(string $name, array $params = [], array $query = [])` | `string` | 直接生成 URL |
| `fingerprint()` | `string` | 路由表 sha256 指纹 |
| `getRouteCollector()` | `RouteCollector` | FastRoute 原始收集器（1.x 兼容） |

---

### RouteCollection

不可变语义的路由集合，实现 `IteratorAggregate` 与 `Countable`。

| 方法 | 返回 | 说明 |
|------|------|------|
| `add(Route $route)` | `Route` | 添加路由，重名抛 `InvalidRouteException` |
| `addRoute(array $methods, string $path, mixed $handler, ?string $name = null)` | `Route` | 便捷添加 |
| `all()` | `array<int, Route>` | 全部路由 |
| `get(int $id)` | `?Route` | 按编译期 ID 取 |
| `getByName(string $name)` | `?Route` | 按名称取 |
| `hasName(string $name)` | `bool` | 名称是否存在 |
| `names()` | `array<string, int>` | 名称 → ID 映射 |
| `merge(self $other, string $prefix = '', array $middleware = [])` | `self` | 合并另一个集合 |
| `fingerprint()` | `string` | sha256 指纹，内容相同则指纹相同 |
| `compile()` | `CompiledRoutes` | 编译，结果内部缓存 |
| `clear()` | `self` | 清空 |
| `count()` | `int` | 路由数量 |

---

### Route

单条路由定义，支持链式配置。

| 方法 | 返回 | 说明 |
|------|------|------|
| `name(string $name)` | `self` | 设置名称 |
| `prefix(string $prefix)` | `self` | 追加前缀 |
| `middleware(mixed ...$middleware)` | `self` | 追加中间件 |
| `where(string\|array $name, ?string $pattern = null)` | `self` | 参数正则约束 |
| `defaults(array $defaults)` | `self` | 参数默认值 |
| `getName()` / `getPath()` / `getMethods()` / `getHandler()` | — | 读取定义 |
| `getCallback()` | `mixed` | `getHandler()` 的别名（1.x 兼容） |
| `getMiddlewares()` / `getWheres()` / `getDefaults()` | `array` | 读取配置 |
| `getParameterNames()` | `list<string>` | 路径中的参数名 |
| `getCompiledPath()` | `string` | 应用约束后的 FastRoute 路径 |
| `matchesMethod(string $method)` | `bool` | 是否支持该方法 |
| `isCacheable()` | `bool` | 处理器与中间件是否全部可序列化 |
| `toFastRoute()` | `array` | 转 FastRoute 定义 |
| `toArray(?int $id = null)` | `array` | 转数组 |
| `Route::normalizePath(string $path)` | `string` | 静态：路径规范化 |

```php
$router->get('/posts/{slug}/{page}', $handler, 'post.page')
    ->where('slug', '[a-z0-9\-]+')
    ->where(['page' => '\d+'])
    ->defaults(['page' => 1])
    ->middleware(CacheMiddleware::class);
```

约束会被编译进路径：`/posts/{slug:[a-z0-9\-]+}/{page:\d+}`。

---

### RouteGroup

| 方法 | 返回 | 说明 |
|------|------|------|
| `__construct(string $name, string $prefix = '')` | — | 构造 |
| `prefix(string $prefix)` | `self` | 设置前缀 |
| `middleware(mixed ...$middleware)` | `self` | 追加中间件 |
| `route(array $methods, string $path, mixed $handler, ?string $name = null)` | `Route` | 注册路由 |
| `addRoute(Route $route)` | `self` | 添加已构造的路由 |
| `group(string $name, callable $callback, string $prefix = '')` | `RouteGroup` | 嵌套子组 |
| `addGroup(RouteGroup $group)` | `self` | 添加已构造的子组 |
| `getRoutes()` | `array<int, Route>` | 含子组的全部路由 |
| `getOwnRoutes()` | `array<int, Route>` | 仅本组路由 |
| `getGroups()` | `array<string, RouteGroup>` | 子组 |
| `flatten(string $parentPrefix = '', array $parentMiddleware = [])` | `array<int, Route>` | 展开为扁平路由列表 |
| `count()` | `int` | 路由数量（含子组） |

---

### CompiledRoutes

**不可变**编译产物，2.0 的核心。可 `var_export`、可 `json_encode`、可跨进程跨线程传递。

| 方法 | 返回 | 说明 |
|------|------|------|
| `CompiledRoutes::fromCollection(RouteCollection $collection)` | `self` | 静态：从集合编译 |
| `CompiledRoutes::fromArray(array $data)` | `self` | 静态：从数组还原，结构损坏抛 `RouteCacheException` |
| `getDispatchData()` | `array` | FastRoute 调度数据 |
| `getDispatcher()` | `FastRoute\Dispatcher` | 底层分发器 |
| `getRoutes()` | `array<int, array>` | 路由元数据（键为编译期 int ID） |
| `getRoute(int $id)` | `?array` | 按 ID 取元数据 |
| `getRouteByName(string $name)` | `?array` | 按名称取元数据 |
| `getNamed()` | `array<string, int>` | 名称 → ID 映射 |
| `getFingerprint()` | `string` | sha256 指纹 |
| `getCompiledAt()` | `int` | 编译时间戳 |
| `count()` | `int` | 路由条数 |
| `isCacheable()` | `bool` | 是否全部可序列化 |
| `getUncacheableRoutes()` | `list<string>` | 不可缓存路由描述，形如 `"GET /users/{id}"` |
| `toArray()` | `array` | 导出为纯数组，不可缓存时抛 `RouteCacheException` |

> **设计要点**：FastRoute 的 handler 位置注册的是 **int 路由 ID** 而非真实处理器，真实处理器存在 `getRoutes()` 元数据里。这样调度数据永远是标量结构，闭包也不会阻断 FastRoute 数据本身的序列化。

```php
$compiled = $router->compile();

if (!$compiled->isCacheable()) {
    print_r($compiled->getUncacheableRoutes());
}

$payload = $compiled->toArray();
$restored = CompiledRoutes::fromArray($payload);
```

---

### Dispatcher

运行期匹配与执行。**不持有请求级状态**，可被任意协程 / 线程共享。

```php
public function __construct(
    CompiledRoutes|RouteCollector $source,
    array $namedRoutes = [],
    ?HandlerResolver $resolver = null,
    string $basePath = ''
)
```

传 `CompiledRoutes` 走 2.x 路径；传 `RouteCollector` 进入 1.x 兼容模式。

| 方法 | 返回 | 说明 |
|------|------|------|
| `match(string $method, string $path)` | `MatchResult` | **推荐入口**，只匹配不执行 |
| `handle(string $method, string $path, mixed ...$extra)` | `mixed` | 匹配并执行，未命中抛异常 |
| `execute(string $method, string $path)` | `mixed` | `handle()` 的别名（1.x 兼容） |
| `dispatch(string $method, string $path)` | `array` | FastRoute 原始三元组（1.x 兼容） |
| `url(string $name, array $params = [], array $query = [])` | `string` | 生成 URL |
| `getUrlGenerator()` | `UrlGenerator` | URL 生成器 |
| `getResolver()` | `HandlerResolver` | 处理器解析器 |
| `getCompiled()` | `?CompiledRoutes` | 编译产物，1.x 兼容模式下为 `null` |
| `getFingerprint()` | `string` | 路由指纹 |
| `getRouteCollector()` | `?RouteCollector` | 1.x 兼容模式下的收集器 |
| `getDispatcher()` | `FastRoute\Dispatcher` | 底层 FastRoute 分发器 |

`match()` 会自动大写 HTTP 方法、剥离查询字符串、规范化路径。

`handle()` 的异常：未命中抛 `RouteNotFoundException`，方法不允许抛 `MethodNotAllowedException`（可 `getAllowedMethods()`）。

---

### MatchResult

**不可变**匹配结果。

#### 常量

```php
MatchResult::FOUND;                // 0
MatchResult::NOT_FOUND;            // 1
MatchResult::METHOD_NOT_ALLOWED;   // 2
```

> 注意：这套常量与 FastRoute 自身的状态码（`NOT_FOUND=0, FOUND=1, METHOD_NOT_ALLOWED=2`）取值不同，不要混用。判断状态请用 `isFound()` 等方法，不要直接比较数字。

#### 工厂方法

```php
MatchResult::found(mixed $handler, array $params = [], array $middleware = [], ?string $name = null, ?string $path = null, ?int $routeId = null): self
MatchResult::notFound(): self
MatchResult::methodNotAllowed(array $allowedMethods): self
```

#### 读取

| 方法 | 返回 | 说明 |
|------|------|------|
| `isFound()` / `isNotFound()` / `isMethodNotAllowed()` | `bool` | 状态判断 |
| `getStatus()` | `int` | 原始状态码 |
| `getHandler()` | `mixed` | 处理器 |
| `getParams()` | `array<string, mixed>` | 路径参数 |
| `getParam(string $key, mixed $default = null)` | `mixed` | 单个参数 |
| `getMiddleware()` | `array` | 中间件栈 |
| `getName()` | `?string` | 路由名 |
| `getPath()` | `?string` | 路径模板 |
| `getRouteId()` | `?int` | 编译期路由 ID |
| `getAllowedMethods()` | `array<int, string>` | 405 时允许的方法 |
| `toArray()` | `array` | 转数组（闭包处理器会被脱敏） |

#### 派生（返回新实例）

```php
$result->withParams(['id' => 1]);
$result->withMiddleware([AuthMiddleware::class]);
$result->withHandler($newHandler);
```

---

### UrlGenerator

```php
// $namedPaths：名称 => 编译后路径
public function __construct(array $namedPaths = [], string $basePath = '')
public static function fromCompiled(CompiledRoutes $compiled, string $basePath = ''): self
```

| 方法 | 返回 | 说明 |
|------|------|------|
| `generate(string $name, array $params = [], array $query = [])` | `string` | 生成 URL |
| `has(string $name)` | `bool` | 路由名是否存在 |
| `all()` | `array<string, string>` | 全部命名路由 |

行为约定：

- 缺少必填参数 → `UrlGenerationException`
- 参数不满足 `where()` 约束 → `UrlGenerationException`
- 路由名不存在 → `UrlGenerationException`
- 多余参数自动转为查询字符串
- 可选段（`[/{page}]`）未提供参数时整段省略
- 参数值自动 `rawurlencode`

```php
$generator->generate('user.show', ['id' => 42]);                  // /users/42
$generator->generate('user.show', ['id' => 42, 'tab' => 'info']); // /users/42?tab=info
$generator->generate('user.show', ['id' => 42], ['ref' => 'nav']);// /users/42?ref=nav
```

---

### HandlerResolver

把各种写法的处理器统一解析成可调用体，并按形参名绑定路径参数。

```php
public function __construct(?object $container = null)
```

容器用鸭子类型判断（需实现 `has()` / `get()`），因此不强依赖 `psr/container`，`kode/di` 或任意 PSR-11 实现都能直接传入。

| 方法 | 返回 | 说明 |
|------|------|------|
| `resolve(mixed $handler)` | `callable` | 解析为可调用体 |
| `call(mixed $handler, array $params = [], array $extra = [])` | `mixed` | 解析并立即调用 |
| `HandlerResolver::isStatic(mixed $handler)` | `bool` | 静态：判断是否为可缓存形式 |

参数绑定规则：**名称优先、位置兜底**。处理器形参名与路径参数同名时按名传入，否则按顺序传入；都不满足时用默认值。

```php
$router->get('/posts/{slug}/{page}', function (int $page, string $slug) { ... });
// slug / page 都能正确对上，与形参顺序无关
```

无法解析时抛 `InvalidRouteException`：类不存在、方法不存在、类不可实例化、构造函数需要参数但未注入容器、未实现 `__invoke()`。

---

## 缓存 `Cache\`

### RouteCacheInterface

```php
interface RouteCacheInterface
{
    public function get(string $key): ?CompiledRoutes;
    public function set(string $key, CompiledRoutes $routes): bool;
    public function remember(string $key, callable $compiler): CompiledRoutes;
    public function has(string $key): bool;
    public function forget(string $key): bool;
    public function clear(): bool;
}
```

`remember()` 是主要入口：命中返回缓存，未命中执行 `$compiler` 并尝试写入。

> `set()` 在路由不可缓存（含闭包）时**返回 `false` 而非抛异常**，属于静默降级；此时 `remember()` 仍会正常返回编译结果，只是每次都要重新编译。

### FileRouteCache

```php
public function __construct(string $directory, bool $invalidateOpcache = true)
```

`var_export` 生成纯 PHP 数组文件，配合 OPcache 常驻共享内存。写入使用临时文件 + 原子替换，避免并发下读到半截文件。`invalidateOpcache` 为真时写入后主动调用 `opcache_invalidate()`，常驻进程建议保持开启。

额外方法：`getDirectory()`、`path(string $key)`。

目录不可写、创建失败、写入失败时抛 `RouteCacheException`。

### ApcuRouteCache

```php
public function __construct(string $prefix = 'kode.router.', int $ttl = 0)
public static function isSupported(): bool
```

适合 PHP-FPM：所有进程共享同一份 APCu 内存。`isSupported()` 会同时检查扩展是否加载与是否启用。

### NullRouteCache

空实现，`Application` 的默认值。`remember()` 直接执行编译器，其余方法返回 `null` / `false`。

---

## 并发 `Concurrency\`

### Runtime

运行时探测。所有结果内部缓存，`reset()` 可清空（测试用）。

#### 常量

```php
Runtime::SWOOLE;  Runtime::SWOW;   Runtime::FIBER;
Runtime::THREAD;  Runtime::PROCESS; Runtime::FPM;  Runtime::CLI;
```

| 方法 | 返回 | 说明 |
|------|------|------|
| `detect()` | `string` | 运行时标识 |
| `host()` | `string` | 人类可读的宿主描述 |
| `inCoroutine()` | `bool` | 当前是否在协程中 |
| `supportsFibers()` | `bool` | 是否可用 Fiber |
| `supportsThreads()` | `bool` | 是否可用 `ext-parallel` |
| `supportsProcesses()` | `bool` | 是否可用进程能力 |
| `isLongRunning()` | `bool` | 是否常驻内存 |
| `executionId()` | `string` | 执行单元唯一 ID |
| `processId()` | `int` | 进程 ID |
| `nodeId()` | `string` | 节点标识 |
| `reset()` | `void` | 清空探测缓存 |

### RouteContext

协程安全的"当前路由"上下文，按 `Runtime::executionId()` 分桶。安装 `kode/context` 后自动改用其上下文容器。

| 方法 | 返回 | 说明 |
|------|------|------|
| `set(MatchResult $result)` | `void` | 写入当前执行单元 |
| `current()` | `?MatchResult` | 读取 |
| `has()` | `bool` | 是否已写入 |
| `name()` | `?string` | 当前路由名 |
| `param(string $key, mixed $default = null)` | `mixed` | 当前路径参数 |
| `clear()` | `void` | 清理当前执行单元 |
| `flush()` | `void` | 清理全部（进程退出 / 测试） |
| `count()` | `int` | 存活上下文数量 |
| `run(MatchResult $result, callable $callback)` | `mixed` | 作用域执行，退出自动还原 |
| `resetIntegration()` | `void` | 强制重新探测 `kode/context`（测试用） |

常量：`RouteContext::KEY = 'kode.router.match'`。

### ConcurrentMatcher

```php
public function __construct(Dispatcher $dispatcher)
```

| 方法 | 返回 | 说明 |
|------|------|------|
| `matchAll(array $requests)` | `array<int\|string, MatchResult>` | 批量匹配 |
| `handleAll(array $requests, int $concurrency = 0, bool $captureErrors = false)` | `array` | 批量匹配并执行 |
| `mapConcurrently(array $requests, callable $callback, int $concurrency = 0)` | `array` | 匹配后并发执行回调，回调签名 `fn(MatchResult): mixed` |
| `getDispatcher()` | `Dispatcher` | 底层分发器 |
| `ConcurrentMatcher::runTasks(array $tasks, int $concurrency = 0, bool $captureErrors = false)` | `array` | 静态：通用并发工具 |

请求格式为 `['键' => ['METHOD', '/path'], ...]`，返回值与入参**同键同序**。

`concurrency = 0` 不限并发；`captureErrors = true` 时异常作为结果值返回，为 `false` 时首个异常向上抛出。无 Fiber 环境自动退化为串行，语义不变。

详见 [CONCURRENCY.md](CONCURRENCY.md)。

---

## 分布式 `Distributed\`

### TraceContext

W3C Trace Context 实现。

#### 常量

```php
TraceContext::HEADER;        // 'traceparent'
TraceContext::STATE_HEADER;  // 'tracestate'
TraceContext::VERSION;       // '00'
TraceContext::FLAG_SAMPLED;  // 0x01
```

| 方法 | 返回 | 说明 |
|------|------|------|
| `TraceContext::start(bool $sampled = true)` | `self` | 新建根链路 |
| `TraceContext::fromHeaders(array $headers)` | `self` | 从请求头恢复，缺失或非法则新建 |
| `TraceContext::parse(string $traceparent, string $state = '')` | `?self` | 严格解析，失败返回 `null` |
| `child()` | `self` | 派生子 span |
| `getTraceId()` / `getSpanId()` / `getParentSpanId()` | `string` / `?string` | 链路标识 |
| `isSampled()` / `isRoot()` | `bool` | 采样与根节点判断 |
| `getState()` | `string` | `tracestate` 原值 |
| `toTraceparent()` | `string` | `00-{32hex}-{16hex}-{2hex}` |
| `toHeaders()` | `array<string, string>` | 可直接注入下游请求 |
| `toArray()` | `array` | 转数组 |
| `publish()` | `bool` | 写入 `kode/context`，未安装返回 `false` |

### RouteManifest

路由表指纹清单，用于跨节点一致性校验。

#### 常量

```php
RouteManifest::HEADER_FINGERPRINT;  // 'X-Kode-Route-Fingerprint'
RouteManifest::HEADER_VERSION;      // 'X-Kode-Route-Version'
RouteManifest::HEADER_NODE;         // 'X-Kode-Route-Node'
```

| 方法 | 返回 | 说明 |
|------|------|------|
| `RouteManifest::fromCompiled(CompiledRoutes $compiled, string $version = '', ?string $node = null)` | `self` | 从编译产物构造 |
| `RouteManifest::fromHeaders(array $headers)` | `?self` | 从响应头解析，缺少指纹返回 `null` |
| `RouteManifest::fromArray(array $data)` | `self` | 从数组还原 |
| `getFingerprint()` | `string` | 完整 sha256 |
| `getShortFingerprint()` | `string` | 前 16 位，仅供日志展示 |
| `getVersion()` / `getNode()` / `getRouteCount()` / `getGeneratedAt()` | — | 元信息 |
| `matches(self $other)` | `bool` | 与另一清单比对 |
| `matchesCompiled(CompiledRoutes $compiled)` | `bool` | 与本地编译产物比对 |
| `toHeaders()` | `array<string, string>` | 响应头 |
| `toArray()` | `array` | 转数组 |

> `toHeaders()` 输出**完整**指纹。截断指纹会让跨节点比对出现误判，`getShortFingerprint()` 只用于日志。

---

## 集成 `Integration\`

### RoutingMiddleware

PSR-15 中间件，需要 `psr/http-server-middleware`。

```php
public function __construct(
    Dispatcher $dispatcher,
    bool $exposeParams = true,
    ?RouteManifest $manifest = null
)
```

常量：`RoutingMiddleware::ATTRIBUTE = 'kode.router.match'`。

| 方法 | 返回 | 说明 |
|------|------|------|
| `process(ServerRequestInterface $request, RequestHandlerInterface $handler)` | `ResponseInterface` | PSR-15 入口 |
| `RoutingMiddleware::fromRequest(ServerRequestInterface $request)` | `?MatchResult` | 静态：从请求属性取出匹配结果 |

行为：

1. 匹配后把 `MatchResult` 写进请求属性 `kode.router.match`。
2. `exposeParams` 为真时，路径参数逐个写入同名请求属性。
3. 用 `RouteContext::run()` 包裹下游调用，请求结束自动清理上下文。
4. 405 且下游未设置 `Allow` 时，自动补上 `Allow` 响应头。
5. 传入 `manifest` 时，自动追加路由指纹响应头。
6. **不产生 404/405 响应本身**，交给下游决定。

> PSR-7 消息不可变：`withAttribute()` 返回新对象，属性只能在下游 handler 收到的 `$request` 上读取。

### MiddlewareBridge

对接 `kode/middleware` 的软桥接，全程 `class_exists` 探测。

| 方法 | 返回 | 说明 |
|------|------|------|
| `MiddlewareBridge::isAvailable()` | `bool` | `kode/middleware` 是否可用 |
| `MiddlewareBridge::toRouteResult(MatchResult $result)` | `object` | 转换为 kode/middleware 的路由结果对象 |
| `MiddlewareBridge::matcher(Dispatcher $dispatcher)` | `\Closure` | 生成可直接挂到管道上的匹配器 |

不可用时 `isAvailable()` 返回 `false`，其余方法抛 `RouterException`，不会产生致命错误。

---

## HTTP 对象

内置的轻量 HTTP 对象，用于快速上手。生产项目建议直接用 PSR-7 + `RoutingMiddleware`。

两者均为 `final`（避免 `new static` 的类型不安全），需要扩展请用组合。

### Request

```php
public function __construct(
    string $method = 'GET',
    string $path = '/',
    array $params = [],
    array $headers = []
)
public static function fromGlobals(): self
```

| 方法 | 说明 |
|------|------|
| `getMethod()` / `getPath()` | 方法与路径 |
| `param(?string $key = null, $default = null)` | 取参数，不传 key 返回全部 |
| `header(?string $key = null, $default = null)` | 取请求头，不传 key 返回全部 |

### Response

```php
public function __construct($content = null, int $statusCode = 200, array $headers = [])
```

| 方法 | 说明 |
|------|------|
| `getStatusCode()` / `withStatus(int)` | 状态码 |
| `getHeader(?string $key = null)` / `withHeader(string, string)` | 响应头 |
| `getContent()` | 响应体 |
| `send()` | 输出响应 |
| `Response::json($data, int $statusCode = 200)` | JSON 响应 |
| `Response::html(string $html, int $statusCode = 200)` | HTML 响应 |
| `Response::text(string $text, int $statusCode = 200)` | 纯文本响应 |
| `Response::redirect(string $url, int $statusCode = 302)` | 重定向 |

### MiddlewareInterface

内置中间件契约（非 PSR-15）：

```php
interface MiddlewareInterface
{
    public function handle(array $args, callable $next);
}
```

---

## 异常 `Exception\`

```
RouterException                 （基类，继承 \RuntimeException）
├── RouteNotFoundException      404
├── MethodNotAllowedException   405，带 getAllowedMethods()
├── InvalidRouteException       路由定义或处理器非法
├── UrlGenerationException      URL 生成失败
└── RouteCacheException         缓存读写失败 / 路由不可序列化
```

统一捕获：

```php
try {
    $body = $dispatcher->handle($method, $path);
} catch (MethodNotAllowedException $e) {
    return new Response('', 405, ['Allow' => implode(', ', $e->getAllowedMethods())]);
} catch (RouteNotFoundException) {
    return new Response('Not Found', 404);
} catch (RouterException $e) {
    // 其余路由异常
}
```
