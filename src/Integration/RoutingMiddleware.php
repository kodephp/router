<?php

declare(strict_types=1);

namespace Kode\Router\Integration;

use Kode\Router\Concurrency\RouteContext;
use Kode\Router\Dispatcher;
use Kode\Router\Distributed\RouteManifest;
use Kode\Router\MatchResult;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface as PsrMiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 路由中间件
 *
 * 放在管道最前面：匹配路由 → 把结果写进请求属性和协程上下文 → 继续往下走。
 * 它本身不执行处理器，也不生成 404 / 405 响应，那是下游中间件的职责。
 * 这样做的好处是路由结果对整条管道都可见，鉴权、限流、日志都能读到路由名。
 *
 * 本类依赖 PSR-7 / PSR-15 接口。这些接口只在 require-dev 与 suggest 中声明，
 * 用不到 PSR 管道的项目不会被迫安装它们；用到时类才会被加载。
 *
 * @package Kode\Router
 * @author  Kode Team <382601296@qq.com>
 * @license MIT
 */
final class RoutingMiddleware implements PsrMiddlewareInterface
{
    /** @var string 请求属性名：匹配结果 */
    public const ATTRIBUTE = 'kode.router.match';

    /**
     * @param Dispatcher $dispatcher 分发器（只读，可跨协程共享）
     * @param bool $exposeParams 是否把路径参数逐个写进请求属性
     * @param RouteManifest|null $manifest 路由清单，非 null 时会在结果里携带
     */
    public function __construct(
        private readonly Dispatcher $dispatcher,
        private readonly bool $exposeParams = true,
        private readonly ?RouteManifest $manifest = null
    ) {
    }

    /**
     * 处理请求
     *
     * @param ServerRequestInterface $request 请求
     * @param RequestHandlerInterface $handler 下一个处理器
     * @return ResponseInterface 响应
     */
    #[\Override]
    public function process(
        ServerRequestInterface $request,
        RequestHandlerInterface $handler
    ): ResponseInterface {
        $result = $this->dispatcher->match(
            $request->getMethod(),
            $request->getUri()->getPath()
        );

        $request = $request->withAttribute(self::ATTRIBUTE, $result);

        if ($this->exposeParams) {
            foreach ($result->getParams() as $name => $value) {
                $request = $request->withAttribute($name, $value);
            }
        }

        // 协程安全：结果写入当前执行流的上下文，请求结束后清除
        return RouteContext::run($result, function () use ($request, $handler, $result): ResponseInterface {
            $response = $handler->handle($request);

            if ($result->isMethodNotAllowed() && !$response->hasHeader('Allow')) {
                $response = $response->withHeader('Allow', implode(', ', $result->getAllowedMethods()));
            }

            if ($this->manifest !== null) {
                foreach ($this->manifest->toHeaders() as $name => $value) {
                    $response = $response->withHeader($name, $value);
                }
            }

            return $response;
        });
    }

    /**
     * 从请求属性中取出匹配结果
     *
     * @param ServerRequestInterface $request 请求
     * @return MatchResult|null 匹配结果，尚未路由时返回 null
     */
    public static function fromRequest(ServerRequestInterface $request): ?MatchResult
    {
        $result = $request->getAttribute(self::ATTRIBUTE);

        return $result instanceof MatchResult ? $result : null;
    }
}
