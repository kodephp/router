# 常见问题

## 安装

### Q: 安装时报 PHP 版本错误

需要 PHP 8.4+：

```bash
php -v
```

2.0 用到了 readonly 属性、`new` in initializers、`$object::class` 等特性，无法向下兼容 8.2。如果暂时无法升级，请继续使用 1.x。

### Q: Composer 无法解析依赖

```bash
composer clear-cache
composer self-update
rm -rf vendor composer.lock
composer install
```

### Q: 必须装 PSR 相关的包吗

不需要。核心只依赖 `nikic/fast-route`。只有使用 `Integration\RoutingMiddleware` 时才需要自行引入 `psr/http-server-middleware`。

---

## 路由定义

### Q: 路由匹配不上 / 返回 404

按顺序排查：

```php
// 1. 看路由到底注册了没有
foreach ($router->getRoutes() as $route) {
    echo implode('|', $route->getMethods()), ' ', $route->getPath(), PHP_EOL;
}

// 2. 看编译后的实际路径（约束会被编译进去）
echo $route->getCompiledPath();

// 3. 直接看匹配结果的状态
$result = $dispatcher->match('GET', '/users/1');
var_dump($result->getStatus(), $result->getAllowedMethods());
```

常见原因：分组前缀重复叠加、HTTP 方法不对、`where()` 约束太严、路径少了或多了尾部斜杠。

### Q: 405 和 404 怎么区分

```php
$result = $dispatcher->match($method, $path);

if ($result->isMethodNotAllowed()) {
    // 路径匹配上了，但方法不允许
    $allow = $result->getAllowedMethods();
}
```

用 `handle()` 时则分别抛 `MethodNotAllowedException` 与 `RouteNotFoundException`。

### Q: 路由名重复会怎样

`RouteCollection::add()` 检测到重名直接抛 `InvalidRouteException`。这是刻意的：命名路由重复会让 URL 生成出现难以定位的错误。

### Q: 可选参数怎么写

用 FastRoute 原生的方括号语法：

```php
$router->get('/archive[/{year:\d{4}}[/{month:\d{2}}]]', $handler, 'archive');
```

配合 `defaults()` 给未提供的段设默认值。

---

## 缓存

### Q: 缓存好像没生效

先确认路由可缓存：

```php
$compiled = $router->compile();

var_dump($compiled->isCacheable());
print_r($compiled->getUncacheableRoutes());
```

**闭包路由无法缓存**。只要有一条闭包路由，整张表就写不进缓存（`set()` 静默返回 `false`）。把处理器改成 `'App\Controller\UserController@show'` 这类字符串形式即可。

### Q: 改了路由但缓存没更新

正常情况下不会发生：`Application` 的缓存键里带路由指纹，改动会自动 miss。如果你手写了固定缓存键，需要自己处理失效：

```php
$cache->forget('app.routes');
// 或
$cache->clear();
```

### Q: 文件缓存和 APCu 缓存选哪个

| 场景 | 推荐 |
|------|------|
| Swoole / Swow / RoadRunner 等常驻进程 | `FileRouteCache` + OPcache |
| PHP-FPM 且装了 APCu | `ApcuRouteCache` |
| PHP-FPM 未装 APCu | `FileRouteCache` + OPcache |
| 容器化、只读文件系统 | `ApcuRouteCache` |

`FileRouteCache` 生成的是纯 PHP 数组文件，OPcache 会把它编译进共享内存，性能与 APCu 相当且不额外占用 APCu 内存。

---

## 并发与协程

### Q: 协程环境下拿到的当前路由是别的请求的

说明用了静态属性存状态。改用 `RouteContext`，它按执行单元分桶：

```php
RouteContext::run($result, fn () => $controller->handle());
```

走 `RoutingMiddleware` 时内部已经处理好了，不用管。

### Q: `matchAll()` 为什么没有变快

匹配是纯 CPU 操作，没有 IO 挂起点，Fiber 帮不上忙，反而多了创建开销。Fiber 的收益在于**处理器内部有 IO** 时的并发执行，用 `handleAll()` 或 `mapConcurrently()`。

### Q: 没有 Swoole，Fiber 能用吗

能。Fiber 是 PHP 8.1+ 的语言特性，不需要任何扩展。`Runtime::supportsFibers()` 在 8.3 上恒为 `true`。

### Q: 常驻进程内存持续上涨

检查上下文是否清理：

```php
// 监控存活上下文数量，持续上涨说明泄漏
Runtime::isLongRunning() && error_log('ctx=' . RouteContext::count());
```

优先用 `RouteContext::run()`（自动清理），手写循环时在 `finally` 里 `RouteContext::clear()`。

### Q: 多线程（ext-parallel）下怎么共享路由表

线程之间不共享对象，传数组：

```php
$payload = $router->compile()->toArray();
// 在线程里 CompiledRoutes::fromArray($payload)
```

前提是 `isCacheable()` 为 `true`。

---

## 分布式

### Q: `toHeaders()` 输出的指纹为什么这么长

因为是完整 sha256。截断指纹在跨节点比对时会产生误判，所以响应头里必须是完整值。日志展示用 `getShortFingerprint()`。

### Q: traceparent 从上游传过来但解析失败

`fromHeaders()` 遇到非法格式会静默新建一条链路（保证可用性）。想知道是否解析成功，用严格版本：

```php
$trace = TraceContext::parse($headerValue);

if ($trace === null) {
    // 上游给的 traceparent 不合法
}
```

格式必须是 `00-<32位hex>-<16位hex>-<2位hex>`。

---

## PSR-15 集成

### Q: 在中间件里 `$request->getAttribute()` 拿不到路由

PSR-7 消息是**不可变**的，`withAttribute()` 返回新对象。属性只存在于下游 handler 收到的那个 `$request` 上：

```php
// ✗ 错误：在原始 request 上读
$middleware->process($request, $handler);
$request->getAttribute(RoutingMiddleware::ATTRIBUTE);   // null

// ✓ 正确：在下游 handler 里读
public function handle(ServerRequestInterface $request): ResponseInterface
{
    $result = RoutingMiddleware::fromRequest($request);
}
```

也可以直接用 `RouteContext::current()`，不依赖请求对象。

### Q: `RoutingMiddleware` 为什么不直接返回 404

它只负责匹配，不做响应决策——错误页长什么样、要不要降级到静态资源、要不要走 SPA fallback，都是应用层的事。405 时会自动补 `Allow` 头，其余交给下游。

---

## 性能

### Q: 怎么让路由匹配更快

按收益排序：

1. 常驻进程开启路由缓存并 `boot()` 预热
2. FPM 环境启用 OPcache 或 APCu
3. 路由处理器全部写成 `Class@method`（否则缓存失效）
4. 只需要匹配时用 `match()` 而非 `handle()`
5. 不需要上下文追踪时 `trackContext(false)`

### Q: 路由很多（上千条）会慢吗

FastRoute 用的是分组正则，匹配复杂度接近 O(1)，路由数量不是瓶颈。真正的开销在**每请求重复编译**——这正是 2.0 用缓存解决的问题。

---

## 测试

### Q: 覆盖率报告为空

需要 Xdebug 或 PCOV：

```bash
pecl install xdebug
# 或
pecl install pcov
```

### Q: 跑测试时某些用例被跳过

正常。APCu 相关测试在扩展未安装时跳过，`kode/middleware` 集成测试在包未安装时跳过。这些都是可选依赖。

---

## 升级

### Q: 从 1.x 升级要改哪些代码

多数情况下不用改。`Application` / `Router` 的旧写法完全兼容。需要注意：

| 变化 | 处理 |
|------|------|
| `Request` / `Response` 变为 `final` | 用组合替代继承 |
| 静态属性存当前路由 | 改用 `RouteContext::current()` |
| `Dispatcher` 构造参数 | 传 `RouteCollector` 仍走 1.x 兼容模式 |

完整迁移说明见 [README](../README.md#从-1x-迁移)。

### Q: 1.x 还维护吗

只做安全修复，不再增加新功能。建议升级到 2.0。

---

## 其他

### Q: 支持 PHP 8.2 吗

不支持，需要 8.4+。

### Q: 怎么报告 Bug

在 [GitHub Issues](https://github.com/kodephp/router/issues) 提交，附上：问题描述、复现步骤、期望行为、实际行为、PHP 版本与运行环境。

安全问题请不要走公开 Issue，按 [SECURITY.md](../SECURITY.md) 的流程私下反馈。

### Q: 怎么贡献代码

阅读 [CONTRIBUTING.md](../CONTRIBUTING.md)。提交前请确保 `composer ci` 全绿。
