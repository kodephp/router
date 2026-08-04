<?php

declare(strict_types=1);

/**
 * 示例：kode/router 2.0 的编译缓存、并发匹配与分布式能力
 *
 * 运行：php example/advanced.php
 */

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/Handlers.php';

use Kode\Router\Cache\FileRouteCache;
use Kode\Router\CompiledRoutes;
use Kode\Router\Concurrency\ConcurrentMatcher;
use Kode\Router\Concurrency\RouteContext;
use Kode\Router\Concurrency\Runtime;
use Kode\Router\Dispatcher;
use Kode\Router\Distributed\RouteManifest;
use Kode\Router\Distributed\TraceContext;
use Kode\Router\MatchResult;
use Kode\Router\Router;

function section(string $title): void
{
    echo PHP_EOL, str_repeat('─', 60), PHP_EOL, $title, PHP_EOL, str_repeat('─', 60), PHP_EOL;
}

// ============================================================
section('1. 运行时探测');
// ============================================================

printf("运行时      : %s\n", Runtime::detect());
printf("宿主        : %s\n", Runtime::host());
printf("常驻内存    : %s\n", Runtime::isLongRunning() ? '是' : '否');
printf("支持 Fiber  : %s\n", Runtime::supportsFibers() ? '是' : '否');
printf("支持线程    : %s\n", Runtime::supportsThreads() ? '是' : '否');
printf("执行单元 ID : %s\n", Runtime::executionId());
printf("节点标识    : %s\n", Runtime::nodeId());

// ============================================================
section('2. 定义路由（全部用可缓存的字符串处理器）');
// ============================================================

$router = new Router();
$router->get('/', 'Kode\Router\Example\Handlers::home', 'home');
$router->get('/users/{id:\d+}', 'Kode\Router\Example\Handlers::showUser', 'user.show');
$router->post('/users', 'Kode\Router\Example\Handlers::createUser', 'user.create');
$router->get('/posts/{slug}[/{page:\d+}]', 'Kode\Router\Example\Handlers::post', 'post.show');

printf("路由数量: %d\n", count($router->getRoutes()));
printf("路由指纹: %s\n", substr($router->fingerprint(), 0, 32) . '...');

// ============================================================
section('3. 编译期：一次编译，产出不可变结果');
// ============================================================

$compiled = $router->compile();

printf("编译条数  : %d\n", $compiled->count());
printf("可缓存    : %s\n", $compiled->isCacheable() ? '是' : '否');
printf("编译时间  : %s\n", date('Y-m-d H:i:s', $compiled->getCompiledAt()));

if (!$compiled->isCacheable()) {
    echo "不可缓存的路由:\n";
    foreach ($compiled->getUncacheableRoutes() as $description) {
        echo "  - {$description}\n";
    }
}

// 编译产物可以序列化后跨进程、跨线程传递
$payload = $compiled->toArray();
$restored = CompiledRoutes::fromArray($payload);
printf("序列化往返: %s\n", $restored->getFingerprint() === $compiled->getFingerprint() ? '一致' : '不一致');

// ============================================================
section('4. 路由缓存：多进程 worker 共享');
// ============================================================

$cacheDir = sys_get_temp_dir() . '/kode-router-example';
$cache = new FileRouteCache($cacheDir);

$cache->clear();

$t0 = hrtime(true);
$first = $cache->remember('app.routes', static fn (): CompiledRoutes => $router->compile());
$coldMs = (hrtime(true) - $t0) / 1e6;

$t0 = hrtime(true);
$second = $cache->remember('app.routes', static fn (): CompiledRoutes => $router->compile());
$warmMs = (hrtime(true) - $t0) / 1e6;

printf("缓存目录  : %s\n", $cache->getDirectory());
printf("冷启动    : %.3f ms（编译 + 落盘）\n", $coldMs);
printf("热启动    : %.3f ms（读缓存）\n", $warmMs);
printf("指纹一致  : %s\n", $first->getFingerprint() === $second->getFingerprint() ? '是' : '否');

// ============================================================
section('5. 运行期：无状态匹配');
// ============================================================

$dispatcher = new Dispatcher($compiled);

foreach ([['GET', '/users/42'], ['POST', '/users'], ['DELETE', '/users'], ['GET', '/nope']] as [$method, $path]) {
    $result = $dispatcher->match($method, $path);

    $status = match (true) {
        $result->isFound()            => sprintf('200 %s %s', $result->getName(), json_encode($result->getParams())),
        $result->isMethodNotAllowed() => sprintf('405 允许: %s', implode(', ', $result->getAllowedMethods())),
        default                       => '404',
    };

    printf("%-6s %-14s → %s\n", $method, $path, $status);
}

// ============================================================
section('6. URL 生成');
// ============================================================

printf("home       → %s\n", $dispatcher->url('home'));
printf("user.show  → %s\n", $dispatcher->url('user.show', ['id' => 42]));
printf("post.show  → %s\n", $dispatcher->url('post.show', ['slug' => 'hello-world']));
printf("带查询串   → %s\n", $dispatcher->url('user.show', ['id' => 42], ['tab' => 'profile']));

// ============================================================
section('7. Fiber 并发匹配');
// ============================================================

$matcher = new ConcurrentMatcher($dispatcher);

$requests = [
    'a' => ['GET',  '/users/1'],
    'b' => ['GET',  '/users/2'],
    'c' => ['POST', '/users'],
    'd' => ['GET',  '/posts/hello/3'],
];

$results = $matcher->matchAll($requests);

foreach ($results as $key => $result) {
    printf(
        "%s: %s %s\n",
        $key,
        $result->isFound() ? $result->getName() : '未命中',
        $result->isFound() ? json_encode($result->getParams(), JSON_UNESCAPED_UNICODE) : ''
    );
}

// 匹配后并发执行自定义回调
$names = $matcher->mapConcurrently(
    $requests,
    static fn (MatchResult $r): string => $r->isFound() ? strtoupper((string) $r->getName()) : '-',
    concurrency: 2
);
printf("并发映射   : %s\n", implode(', ', $names));

// ============================================================
section('8. 协程安全的上下文');
// ============================================================

$result = $dispatcher->match('GET', '/users/42');

$inside = RouteContext::run($result, static function (): string {
    // 业务代码任意深度都能读到当前路由，且不会与其他协程串扰
    return sprintf('%s id=%s', (string) RouteContext::name(), (string) RouteContext::param('id'));
});

printf("作用域内   : %s\n", $inside);
printf("作用域外   : %s\n", RouteContext::current() === null ? '已自动清理' : '残留');
printf("存活上下文 : %d\n", RouteContext::count());

// ============================================================
section('9. 分布式：链路透传');
// ============================================================

// 模拟上游传来的请求头
$incoming = ['traceparent' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01'];

$trace = TraceContext::fromHeaders($incoming);
printf("traceId    : %s\n", $trace->getTraceId());
printf("spanId     : %s\n", $trace->getSpanId());
printf("parentSpan : %s\n", $trace->getParentSpanId() ?? '（无）');
printf("已采样     : %s\n", $trace->isSampled() ? '是' : '否');
printf("是根节点   : %s\n", $trace->isRoot() ? '是' : '否');

$child = $trace->child();
printf("子 span    : %s\n", $child->toTraceparent());

// ============================================================
section('10. 分布式：路由表一致性校验');
// ============================================================

$manifest = RouteManifest::fromCompiled($compiled, '2.0.0');

printf("节点       : %s\n", $manifest->getNode());
printf("路由数     : %d\n", $manifest->getRouteCount());
printf("短指纹     : %s\n", $manifest->getShortFingerprint());

echo "响应头:\n";
foreach ($manifest->toHeaders() as $name => $value) {
    printf("  %s: %s\n", $name, $value);
}

// 网关侧：从响应头还原并比对
$upstream = RouteManifest::fromHeaders($manifest->toHeaders());
printf("与本地一致 : %s\n", $upstream?->matchesCompiled($compiled) === true ? '是' : '否');

// 模拟一个路由表变更的节点
$staleRouter = clone $router;
$staleRouter->get('/extra', 'Kode\Router\Example\Handlers::home', 'extra');
$staleManifest = RouteManifest::fromCompiled($staleRouter->compile(), '2.0.0');
printf("与旧节点一致: %s\n", $staleManifest->matchesCompiled($compiled) ? '是' : '否（需重载）');

$cache->clear();
echo PHP_EOL, '完成。', PHP_EOL;
