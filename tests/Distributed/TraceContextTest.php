<?php

namespace Kode\Router\Tests\Distributed;

use Kode\Router\Distributed\TraceContext;
use PHPUnit\Framework\TestCase;

class TraceContextTest extends TestCase
{
    private const TRACE_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const SPAN_ID = 'bbbbbbbbbbbbbbbb';

    public function testStartIsRootAndValid(): void
    {
        $ctx = TraceContext::start();

        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $ctx->getTraceId());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $ctx->getSpanId());
        $this->assertTrue($ctx->isRoot());
        $this->assertTrue($ctx->isSampled());
        $this->assertNull($ctx->getParentSpanId());
    }

    public function testParseValid(): void
    {
        $traceparent = '00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01';
        $ctx = TraceContext::parse($traceparent);

        $this->assertNotNull($ctx);
        $this->assertSame(self::TRACE_ID, $ctx->getTraceId());
        $this->assertNotSame(self::SPAN_ID, $ctx->getSpanId());
        $this->assertSame(self::SPAN_ID, $ctx->getParentSpanId());
        $this->assertTrue($ctx->isSampled());
        $this->assertFalse($ctx->isRoot());
    }

    public function testParseRejectsMalformed(): void
    {
        $this->assertNull(TraceContext::parse('not-a-traceparent'));
        $this->assertNull(TraceContext::parse('00-' . str_repeat('a', 31) . '-' . self::SPAN_ID . '-01'));
        $this->assertNull(TraceContext::parse('00-' . str_repeat('a', 32) . '-' . str_repeat('0', 16) . '-01'));
    }

    public function testFromHeadersParsesOrStarts(): void
    {
        $with = TraceContext::fromHeaders([TraceContext::HEADER => '00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01']);
        $this->assertSame(self::TRACE_ID, $with->getTraceId());

        $without = TraceContext::fromHeaders([]);
        $this->assertTrue($without->isRoot());
    }

    public function testChildKeepsTraceAndSetsParent(): void
    {
        $parent = TraceContext::parse('00-' . self::TRACE_ID . '-' . self::SPAN_ID . '-01');
        $child = $parent->child();

        $this->assertSame(self::TRACE_ID, $child->getTraceId());
        $this->assertSame($parent->getSpanId(), $child->getParentSpanId());
        $this->assertNotSame($parent->getSpanId(), $child->getSpanId());
    }

    public function testToTraceparentFormat(): void
    {
        $ctx = TraceContext::start(false);
        $this->assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-0[0-2]$/', $ctx->toTraceparent());
    }

    public function testToHeaders(): void
    {
        $ctx = TraceContext::start();
        $headers = $ctx->toHeaders();

        $this->assertArrayHasKey(TraceContext::HEADER, $headers);
        $this->assertSame($ctx->toTraceparent(), $headers[TraceContext::HEADER]);
    }

    public function testPublishReturnsFalseWithoutContext(): void
    {
        $ctx = TraceContext::start();
        $this->assertFalse($ctx->publish());
    }

    public function testToArrayIncludesNodeAndPid(): void
    {
        $ctx = TraceContext::start();
        $array = $ctx->toArray();

        $this->assertArrayHasKey('trace_id', $array);
        $this->assertArrayHasKey('node', $array);
        $this->assertArrayHasKey('pid', $array);
    }
}
