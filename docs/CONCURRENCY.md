# 并发与分布式

本文说明 `kode/router` 2.0 在多进程、多线程、Fiber 协程与分布式部署下的设计取舍和使用方式。

---

## 1. 设计前提：把状态赶出运行期

传统 PHP 路由器是"请求级"的：每个请求 new 一个路由器、遍历路由定义、构建匹配表、匹配、销毁。这在 PHP-FPM 的"一请求一进程"模型下没什么问题，因为进程本身就是隔离边界。

但一旦进入常驻内存（Swoole / Swow / ReactPHP / RoadRunner）或协程模型，两个问题会立刻暴露：

1. **重复构建**：路由表其实一辈子都不变，却每个请求重建一次。
2. **状态串扰**：把"当前路由"存进类静态属性，在协程里会被其他协程覆盖。

2.0 的解法是把生命周期切成三段，让状态只存在于最短的那一段：

| 阶段 | 产物 | 生命周期 | 是否可变 |
|------|------|----------|----------|
| 定义期 | `Router` / `RouteCollection` | 启动阶段 | 可变 |
| 编译期 | `CompiledRoutes` | 进程级 / 可落盘 | **不可变** |
| 运行期 | `MatchResult` | 单次请求 | **不可变** |

`Dispatcher` 持有 `CompiledRoutes`，自身不写任何状态，因此可以被任意数量的协程、线程共享，不需要加锁。

---

## 2. 运行时探测

`Runtime` 负责回答"我现在跑在什么环境里"，所有并发相关的行为分支都基于它。

```php
use Kode\Router\Concurrency\Runtime;

Runtime::detect();
// swoole | swow | fiber | thread | process | fpm | cli
```

| 方法 | 用途 |
|------|------|
| `detect()` | 返回当前运行时标识 |
| `host()` | 人类可读的宿主描述，用于日志 |
| `inCoroutine()` | 当前是否处在协程中 |
| `supportsFibers()` | 是否可用 Fiber（PHP 8.1+ 恒真） |
| `supportsThreads()` | 是否可用 `ext-parallel` |
| `supportsProcesses()` | 是否可用 `pcntl` / `ext-swoole` 进程能力 |
| `isLongRunning()` | 是否常驻内存 |
| `executionId()` | 当前执行单元唯一 ID（协程 / 线程 / 进程） |
| `processId()` | 进程 ID |
| `nodeId()` | 节点标识，用于分布式场景 |
| `reset()` | 清空探测缓存（测试用） |

`executionId()` 是上下文隔离的基石：它在 Swoole 下取协程 ID，在 Fiber 下取 Fiber 对象哈希，在多线程下取线程 ID，退化环境下取进程 ID。

典型用法是决定要不要预热缓存：

```php
if (Runtime::isLongRunning()) {
    $app->setCache(new FileRouteCache($dir))->boot();
}
```

---

## 3. 多进程模型

### 3.1 推荐架构

```
master 进程
  └── 编译一次 → CompiledRoutes → 落盘 (FileRouteCache)
        ├── worker #1 → 读缓存 → Dispatcher（只读复用）
        ├── worker #2 → 读缓存 → Dispatcher
        └── worker #N → 读缓存 → Dispatcher
```

关键点：**编译发生在 fork 之前或 worker 启动回调里，绝不发生在请求处理中**。

### 3.2 Swoole 示例

```php
use Kode\Router\Application;
use Kode\Router\Cache\FileRouteCache;

$server = new Swoole\Http\Server('0.0.0.0', 9501);

$app = null;

$server->on('workerStart', function () use (&$app) {
    $app = new Application();
    $app->setCache(new FileRouteCache(__DIR__ . '/runtime/routes'));

    require __DIR__ . '/routes/web.php';   // 定义路由

    $app->boot();   // 命中缓存则零编译，未命中编译一次并写盘
});

$server->on('request', function ($req, $res) use (&$app) {
    $result = $app->getDispatcher()->match(
        $req->server['request_method'],
        $req->server['request_uri']
    );

    // ... 按 $result 处理
});
```

### 3.3 PHP-FPM

FPM 没有 `workerStart`，用 APCu 让所有进程共享同一份编译产物：

```php
use Kode\Router\Cache\ApcuRouteCache;
use Kode\Router\Cache\FileRouteCache;

$cache = ApcuRouteCache::isSupported()
    ? new ApcuRouteCache('kode.router.')
    : new FileRouteCache(__DIR__ . '/runtime/routes');

$app->setCache($cache);
```

`FileRouteCache` 生成的是 `var_export` 出来的纯 PHP 数组文件，开启 OPcache 后同样能被所有 FPM 进程共享，且不占 APCu 内存。两者可按部署环境二选一。

### 3.4 缓存失效

不需要手工清缓存。`Application` 使用的缓存键包含路由表指纹：

```php
'kode.router.' . substr($router->fingerprint(), 0, 16)
```

指纹由所有路由的方法、路径、名称、约束计算而来（sha256）。改一条路由，键就变了，自动 miss 重编译。旧缓存文件可以定期清理，或部署时 `$cache->clear()`。

---

## 4. Fiber 协程

### 4.1 批量并发匹配

`ConcurrentMatcher` 把每个任务包进一个 `Fiber`，先全部 `start()` 跑到第一次挂起，再轮转 `resume()` 直到全部结束。

```php
use Kode\Router\Concurrency\ConcurrentMatcher;

$matcher = new ConcurrentMatcher($dispatcher);

$results = $matcher->matchAll([
    'a' => ['GET',  '/users/1'],
    'b' => ['POST', '/users'],
]);
```

| 方法 | 说明 |
|------|------|
| `matchAll(array $requests)` | 纯匹配，返回 `MatchResult` 数组 |
| `handleAll(array $requests, int $concurrency = 0, bool $captureErrors = false)` | 匹配并执行处理器 |
| `mapConcurrently(array $requests, callable $callback, int $concurrency = 0)` | 匹配后并发执行自定义回调 |
| `runTasks(array $tasks, int $concurrency = 0, bool $captureErrors = false)` | 静态通用并发工具，跑任意 callable |

三条约定：

- **键序稳定**：返回值的键和顺序与入参完全一致，方便直接 `array_combine` 回原数据。
- **并发上限**：`concurrency` 为 0 表示不限制；大于 0 时按 `array_chunk` 分批 drain。
- **异常语义**：`captureErrors = true` 时异常作为结果值返回（`$results[$key] instanceof Throwable`）；为 `false` 时第一个异常直接向上抛出。

### 4.2 无 Fiber 环境的降级

`runTasks()` 会先问 `Runtime::supportsFibers()`。不支持时逐个串行调用，`captureErrors` 语义保持一致。也就是说**同一份业务代码在任何环境下行为都相同**，只是并发度不同。

### 4.3 什么时候真的有收益

Fiber 本身不会让 CPU 密集的匹配变快——它靠的是任务内部有挂起点（IO）。所以：

- ✅ 有收益：批量匹配后**并发执行**处理器，而处理器里有数据库、HTTP、Redis 等 IO。
- ✅ 有收益：网关侧对一批请求做并发鉴权、并发预取。
- ❌ 无收益：单纯 `matchAll()` 一批路径。匹配是纯 CPU 操作，串行反而更快（少了 Fiber 创建开销）。

---

## 5. 协程安全的上下文

### 5.1 问题

```php
// 反面教材：协程环境下会串数据
final class Router
{
    public static ?MatchResult $current = null;
}
```

协程 A 写入后让出，协程 B 写入，A 恢复时读到的是 B 的路由。

### 5.2 解法

`RouteContext` 按 `Runtime::executionId()` 分桶：

```php
use Kode\Router\Concurrency\RouteContext;

RouteContext::set($result);       // 写入当前执行单元
RouteContext::current();          // ?MatchResult
RouteContext::name();             // 当前路由名
RouteContext::param('id');        // 当前路径参数
RouteContext::has();
RouteContext::clear();            // 清理当前执行单元
RouteContext::flush();            // 清理全部（进程退出 / 测试）
RouteContext::count();            // 当前存活的上下文数量
```

推荐用作用域方式，异常也能保证还原：

```php
$response = RouteContext::run($result, function () {
    return $controller->handle();   // 内部任意深度都能读到 RouteContext::current()
});
```

`RoutingMiddleware` 内部就是这么做的，所以走 PSR-15 管道时不需要自己管理上下文。

### 5.3 与 kode/context 的关系

如果安装了 `kode/context`，`RouteContext` 会自动改用它的上下文容器（通过 `class_exists` 软探测），这样路由上下文和框架其他组件（日志 traceId、DB 事务、用户身份）共用同一份协程上下文，协程退出时统一回收。

没安装也完全可用，退化为内部的 executionId 分桶数组。`resetIntegration()` 用于测试时强制重新探测。

### 5.4 内存注意事项

常驻进程里，如果只 `set()` 不 `clear()`，桶会持续增长。三条建议：

1. 优先用 `RouteContext::run()`，自动清理。
2. 手写请求循环时，在 `finally` 里 `RouteContext::clear()`。
3. 用 `RouteContext::count()` 做兜底监控，数值持续上涨说明有泄漏。

---

## 6. 多线程

`ext-parallel` 的线程之间不共享对象，只能传递可序列化的值。`CompiledRoutes` 正是为此设计的：

```php
use Kode\Router\CompiledRoutes;
use Kode\Router\Dispatcher;

$payload = $router->compile()->toArray();   // 纯数组，可跨线程传递

$runtime = new parallel\Runtime();
$future = $runtime->run(static function (array $payload) {
    $compiled = CompiledRoutes::fromArray($payload);
    $dispatcher = new Dispatcher($compiled);

    return $dispatcher->match('GET', '/users/1')->getName();
}, [$payload]);
```

前提是路由全部可缓存（`isCacheable()` 为 `true`）——闭包处理器无法跨线程传递。用 `getUncacheableRoutes()` 定位问题路由，改成 `Class@method` 形式即可。

---

## 7. 分布式

### 7.1 链路透传（W3C Trace Context）

```php
use Kode\Router\Distributed\TraceContext;

// 入口：恢复或新建
$trace = TraceContext::fromHeaders($request->getHeaders());

// 下游调用：派生子 span
$headers = $trace->child()->toHeaders();
// ['traceparent' => '00-{32hex}-{16hex}-01', 'tracestate' => '...']

// 写入当前上下文，供日志组件读取
$trace->publish();
```

| 方法 | 说明 |
|------|------|
| `start(bool $sampled = true)` | 新建根链路 |
| `fromHeaders(array $headers)` | 从请求头恢复，缺失或非法则新建 |
| `parse(string $traceparent, string $state = '')` | 严格解析，失败返回 `null` |
| `child()` | 派生子 span，`traceId` 不变、`spanId` 重新生成 |
| `toTraceparent()` / `toHeaders()` / `toArray()` | 序列化 |
| `publish()` | 写入 `kode/context`（未安装则返回 `false`） |

格式严格遵循 `00-<32 hex traceId>-<16 hex spanId>-<2 hex flags>`，可直接与 OpenTelemetry / Jaeger / SkyWalking 对接。

### 7.2 路由表一致性

灰度发布时最难排查的问题是"某个节点的路由表还是旧的"。`RouteManifest` 把路由指纹随响应头带出来：

```php
use Kode\Router\Distributed\RouteManifest;

$manifest = RouteManifest::fromCompiled($compiled, version: '2.0.0');

$response = $response
    ->withHeader('X-Kode-Route-Fingerprint', $manifest->getFingerprint())
    ->withHeader('X-Kode-Route-Version', $manifest->getVersion())
    ->withHeader('X-Kode-Route-Node', $manifest->getNode());
```

或者直接交给 `RoutingMiddleware`：

```php
new RoutingMiddleware($dispatcher, true, $manifest);
```

网关侧比对：

```php
$upstream = RouteManifest::fromHeaders($responseHeaders);

if ($upstream !== null && !$upstream->matchesCompiled($localCompiled)) {
    $logger->warning('路由表不一致', [
        'node'     => $upstream->getNode(),
        'upstream' => $upstream->getShortFingerprint(),
        'local'    => substr($localCompiled->getFingerprint(), 0, 16),
    ]);
}
```

> **注意**：`toHeaders()` 输出的是**完整** sha256 指纹，不是截断值。截断指纹会让跨节点比对出现误判，`getShortFingerprint()` 仅供日志展示使用。

---

## 8. 性能清单

| 措施 | 收益 |
|------|------|
| 常驻进程开启路由缓存 + `boot()` 预热 | 消除每 worker 首次请求的编译开销 |
| FPM 环境启用 APCu 或 OPcache | 跨进程共享路由表，避免 N 份重复内存 |
| 路由处理器全部写成 `Class@method` | 路由表可序列化，缓存与跨线程传递才能生效 |
| 高频路径避免闭包路由 | 闭包让整张表变得不可缓存 |
| 关闭不需要的上下文追踪 `trackContext(false)` | 省下少量写入开销 |
| 只需要匹配时用 `match()` 而非 `handle()` | 跳过处理器解析与参数绑定 |

---

## 9. 常见陷阱

**1. 在请求处理中调用 `compile()`**
编译是启动期动作。放进请求里等于每次重建，缓存完全失效。

**2. 用静态属性存当前路由**
协程环境必串。一律用 `RouteContext`。

**3. 期待 `matchAll()` 比串行快**
匹配是纯 CPU 操作，Fiber 只在有 IO 挂起点时才有意义。

**4. 忘记 `clear()` 导致上下文堆积**
常驻进程里用 `RouteContext::run()` 或 `finally` 兜底。

**5. 混用闭包路由却期待缓存生效**
`isCacheable()` 为 `false` 时，`$cache->set()` 会**静默返回 `false`**（不报错，但也没缓存上），而 `CompiledRoutes::toArray()` 会抛 `RouteCacheException`。部署前用下面这段自检：

```php
$compiled = $router->compile();

if (!$compiled->isCacheable()) {
    // list<string>，形如 "GET /users/{id}"
    foreach ($compiled->getUncacheableRoutes() as $description) {
        echo "不可缓存: {$description}\n";
    }
}
```

**6. 在 PSR-7 原始请求上读路由属性**
`withAttribute()` 返回新对象，属性只在下游 handler 收到的那个 `$request` 上。
