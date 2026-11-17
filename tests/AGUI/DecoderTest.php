<?php

declare(strict_types=1);

namespace TypePHP\Qt\Tests\AGUI;

use PHPUnit\Framework\TestCase;
use TypePHP\Qt\AGUI\Decoder;
use TypePHP\Qt\AGUI\SseClient;

use function qt_app_create;
use function qt_sse_close;
use function qt_sse_open;
use function qt_sse_read;
use function qt_sse_status;
use function qt_window_create;
use function test_reset;
use function test_set_sse_stream;
use function test_sse_requests;

/**
 * Decoder 单测：数据全部内联数组，不依赖网络/Qt/桥接函数。
 * 末尾的 SseClientTest 段依赖 FakeBridge 全局函数（strict 命名空间下必须
 * use function 导入，漏一个 = undefined function Fatal）。
 */
final class DecoderTest extends TestCase
{
    private function types(array $events): array
    {
        return array_map(static fn (array $e): string => $e['type'], $events);
    }

    // ── 三类紧凑展开（展开事件序列逐个断言）──

    public function testTextChunkExpandsToStartContentEnd(): void
    {
        $d = new Decoder();
        $out = $d->feed([
            ['type' => 'TEXT_MESSAGE_CHUNK', 'messageId' => 'm1', 'delta' => 'Hi '],
            ['type' => 'TEXT_MESSAGE_CHUNK', 'messageId' => 'm1', 'delta' => 'there'],
        ]);
        $this->assertSame([
            ['type' => 'TEXT_MESSAGE_START', 'messageId' => 'm1', 'role' => 'assistant', 'threadId' => null, 'runId' => null],
            ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'm1', 'delta' => 'Hi '],
            ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'm1', 'delta' => 'there'],
        ], $out, 'END 是挂起的隐式事件：finish() 才 flush');
        $this->assertSame([['type' => 'TEXT_MESSAGE_END', 'messageId' => 'm1']], $d->finish());
    }

    public function testToolChunkExpandsToStartArgsEnd(): void
    {
        $d = new Decoder();
        $out = $d->feed([
            ['type' => 'TOOL_CALL_CHUNK', 'toolCallId' => 't1', 'toolCallName' => 'search', 'argsDelta' => '{"q"'],
        ]);
        $this->assertSame([
            ['type' => 'TOOL_CALL_START', 'toolCallId' => 't1', 'toolCallName' => 'search'],
            ['type' => 'TOOL_CALL_ARGS', 'toolCallId' => 't1', 'delta' => '{"q"'],
        ], $out);
        $this->assertSame([['type' => 'TOOL_CALL_END', 'toolCallId' => 't1']], $d->finish());
    }

    public function testReasoningChunkExpandsToStartContentEnd(): void
    {
        $d = new Decoder();
        $out = $d->feed([
            ['type' => 'REASONING_MESSAGE_CHUNK', 'messageId' => 'r1', 'delta' => 'hmm'],
        ]);
        $this->assertSame([
            ['type' => 'REASONING_MESSAGE_START', 'messageId' => 'r1'],
            ['type' => 'REASONING_MESSAGE_CONTENT', 'messageId' => 'r1', 'delta' => 'hmm'],
        ], $out);
        $this->assertSame([['type' => 'REASONING_MESSAGE_END', 'messageId' => 'r1']], $d->finish());
    }

    /** 跨批 feed：同一 chunk 序列拆两批 —— 第二批不重发 START，挂起 END 只在 finish() flush。 */
    public function testCrossBatchFeedKeepsState(): void
    {
        $d = new Decoder();
        $first = $d->feed([['type' => 'TEXT_MESSAGE_CHUNK', 'messageId' => 'm1', 'delta' => 'a']]);
        $second = $d->feed([['type' => 'TEXT_MESSAGE_CHUNK', 'messageId' => 'm1', 'delta' => 'b']]);
        $this->assertSame('TEXT_MESSAGE_START', $first[0]['type']);
        $this->assertSame(['TEXT_MESSAGE_CONTENT'], $this->types($second), '续接批不得重发 START');
        $this->assertSame([], $this->types($second === [] ? [] : array_slice($second, 1)), '无挂起 END 提前 flush');
        $tail = $d->finish();
        $this->assertSame([['type' => 'TEXT_MESSAGE_END', 'messageId' => 'm1']], $tail);
    }

    /** 同 lane 换 item：新 toolCallId 到来先补发旧调用的隐式 END（触发①）。 */
    public function testItemSwitchClosesPreviousItemFirst(): void
    {
        $d = new Decoder();
        $out = $d->feed([
            ['type' => 'TOOL_CALL_CHUNK', 'toolCallId' => 't1', 'toolCallName' => 'search', 'argsDelta' => '1'],
            ['type' => 'TOOL_CALL_CHUNK', 'toolCallId' => 't2', 'toolCallName' => 'find', 'argsDelta' => '2'],
        ]);
        $this->assertSame([
            'TOOL_CALL_START', 'TOOL_CALL_ARGS', 'TOOL_CALL_END',  // t1 打开 → 换 item 补 END
            'TOOL_CALL_START', 'TOOL_CALL_ARGS',
        ], $this->types($out));
        $this->assertSame('t1', $out[2]['toolCallId']);
        $this->assertSame([['type' => 'TOOL_CALL_END', 'toolCallId' => 't2']], $d->finish());
    }

    // ── 关闭触发（spec「Closing a chunk stream」）──

    /** 触发②：reasoning chunk 打开中、lane 内到达 STATE 事件 → 先合成 REASONING_MESSAGE_END。 */
    public function testStateEventClosesOpenReasoningChunkStream(): void
    {
        $d = new Decoder();
        $out = $d->feed([
            ['type' => 'REASONING_MESSAGE_CHUNK', 'messageId' => 'r1', 'delta' => 'hmm'],
            ['type' => 'STATE_SNAPSHOT', 'state' => ['x' => 1]],
        ]);
        $this->assertSame([
            'REASONING_MESSAGE_START', 'REASONING_MESSAGE_CONTENT', 'REASONING_MESSAGE_END', 'STATE_SNAPSHOT',
        ], $this->types($out));
        $this->assertSame([], $d->finish(), 'END 已被触发②消耗，finish 无挂起项');
    }

    /** 触发③：MESSAGES_SNAPSHOT / RUN_FINISHED 关所有 lane、flush 全部挂起 END。 */
    public function testRunLevelEventsCloseAllLanes(): void
    {
        $d = new Decoder();
        $out = $d->feed([
            ['type' => 'TEXT_MESSAGE_CHUNK', 'messageId' => 'm1', 'delta' => 'x'],
            ['type' => 'TOOL_CALL_CHUNK', 'toolCallId' => 't1', 'toolCallName' => 'search', 'argsDelta' => '{}'],
            ['type' => 'MESSAGES_SNAPSHOT', 'messages' => []],
        ]);
        $this->assertSame([
            'TEXT_MESSAGE_START', 'TEXT_MESSAGE_CONTENT',
            'TOOL_CALL_START', 'TOOL_CALL_ARGS',
            'TEXT_MESSAGE_END', 'TOOL_CALL_END',  // 挂起 END 在 run 级事件前 flush（族序 text→tool）
            'MESSAGES_SNAPSHOT',
        ], $this->types($out), 'run 级事件先 flush 全部挂起 END 再透传');

        $d2 = new Decoder();
        $out2 = $d2->feed([
            ['type' => 'REASONING_MESSAGE_CHUNK', 'messageId' => 'r1', 'delta' => 'h'],
            ['type' => 'RUN_FINISHED'],
        ]);
        $this->assertSame('REASONING_MESSAGE_END', $out2[2]['type']);
        $this->assertSame([], $d2->finish());
    }

    /** 负向守卫①：stand-aside 例外（RAW / ACTIVITY_* / REASONING_ENCRYPTED_VALUE）不关闭打开项。 */
    public function testStandAsideEventsDoNotCloseOpenItems(): void
    {
        $d = new Decoder();
        $out = $d->feed([
            ['type' => 'TEXT_MESSAGE_CHUNK', 'messageId' => 'm1', 'delta' => 'x'],
            ['type' => 'RAW', 'rawEvent' => ['whatever' => 1]],
            ['type' => 'ACTIVITY_SNAPSHOT', 'id' => 'a1', 'data' => ['k' => 1]],
            ['type' => 'ACTIVITY_DELTA', 'id' => 'a1', 'delta' => ['k2' => 2]],
            ['type' => 'REASONING_ENCRYPTED_VALUE', 'messageId' => 'm1', 'encryptedValue' => 'enc'],
        ]);
        $this->assertNotContains('TEXT_MESSAGE_END', $this->types($out), 'stand-aside 事件不得关闭打开项');
        $this->assertSame([['type' => 'TEXT_MESSAGE_END', 'messageId' => 'm1']], $d->finish());
    }

    /** 负向守卫②：metadata-only 续接（无 delta）合法且不关闭（spec「Chunk metadata」）。 */
    public function testMetadataOnlyContinuationKeepsItemOpen(): void
    {
        $d = new Decoder();
        $out = $d->feed([
            ['type' => 'TEXT_MESSAGE_CHUNK', 'messageId' => 'm1', 'delta' => 'x'],
            ['type' => 'TEXT_MESSAGE_CHUNK', 'messageId' => 'm1', 'metadata' => ['usage' => ['tokens' => 5]]],
        ]);
        $this->assertCount(2, $out, 'metadata-only chunk 不产事件、不关闭（只产首 chunk 的 START + CONTENT）');
        $this->assertSame([['type' => 'TEXT_MESSAGE_END', 'messageId' => 'm1']], $d->finish());
    }

    /** TOOL_CALL_CHUNK 后续重复同名冲突 = 终止该调用展开 + warning（spec fatal 的 v1 宽容落地，不断整条流）。 */
    public function testToolCallChunkNameConflictTerminatesThatCallOnly(): void
    {
        $d = new Decoder();
        $out = $d->feed([
            ['type' => 'TOOL_CALL_CHUNK', 'toolCallId' => 't1', 'toolCallName' => 'search', 'argsDelta' => 'a'],
            ['type' => 'TOOL_CALL_CHUNK', 'toolCallId' => 't1', 'toolCallName' => 'find', 'argsDelta' => 'b'],
        ]);
        $this->assertSame(['TOOL_CALL_START', 'TOOL_CALL_ARGS', 'TOOL_CALL_END'], $this->types($out));
        $this->assertCount(1, $d->warnings());
        $this->assertStringContainsString('toolCallName', $d->warnings()[0]);
        $this->assertSame([], $d->finish(), '冲突后该项已终止，无挂起 END');
    }

    /** legacy THINKING_TEXT_MESSAGE_* 容错映射（映射表名没有 THINKING_MESSAGE_*，三个映射名各一例）。 */
    public function testThinkingEventsMapToReasoningWithWarnings(): void
    {
        $d = new Decoder();
        $out = $d->feed([
            ['type' => 'THINKING_TEXT_MESSAGE_START', 'messageId' => 'r1'],
            ['type' => 'THINKING_TEXT_MESSAGE_CONTENT', 'messageId' => 'r1', 'delta' => 'hmm'],
            ['type' => 'THINKING_TEXT_MESSAGE_END', 'messageId' => 'r1'],
        ]);
        $this->assertSame([
            'REASONING_MESSAGE_START', 'REASONING_MESSAGE_CONTENT', 'REASONING_MESSAGE_END',
        ], $this->types($out));
        $this->assertCount(3, $d->warnings());
        $this->assertStringContainsString('THINKING_TEXT_MESSAGE_START', $d->warnings()[0]);
        $this->assertSame([], $d->finish());
    }

    /** 未知 type 原样透传 + 'unknown'=>true（META_EVENT 是 draft，不硬依赖）。 */
    public function testUnknownTypesPassThroughWithMarker(): void
    {
        $d = new Decoder();
        $out = $d->feed([
            ['type' => 'META_EVENT', 'meta' => 1],
            ['type' => 'SOME_NEW_EVENT', 'x' => 1],
        ]);
        $this->assertSame(2, count($out));
        $this->assertTrue($out[0]['unknown']);
        $this->assertTrue($out[1]['unknown']);
        $this->assertSame('META_EVENT', $out[0]['type']);
    }

    /** RAW / CUSTOM 原样保留 + 'untrusted'=>true（消费方纪律位：不得由 RAW 推导协议行为）。 */
    public function testRawAndCustomCarryUntrustedMarker(): void
    {
        $d = new Decoder();
        $out = $d->feed([
            ['type' => 'RAW', 'rawEvent' => ['a' => 1]],
            ['type' => 'CUSTOM', 'name' => 'vendor.x', 'value' => 1],
        ]);
        $this->assertTrue($out[0]['untrusted']);
        $this->assertTrue($out[1]['untrusted']);
        $this->assertSame(['a' => 1], $out[0]['rawEvent']);
        $this->assertFalse($out[0]['unknown'] ?? false);
    }

    /** 宽容容忍：STATE_DELTA 缺 delta / RUN_FINISHED 缺 outcome 都不产 warning（Session 侧按缺省语义兜）。 */
    public function testMissingOptionalsAreTolerated(): void
    {
        $d = new Decoder();
        $out = $d->feed([
            ['type' => 'STATE_DELTA'],
            ['type' => 'RUN_FINISHED'],
        ]);
        $this->assertSame(['STATE_DELTA', 'RUN_FINISHED'], $this->types($out));
        $this->assertSame([], $d->warnings());
    }

    // ── SSE 文本解码（离线回放入口）──

    public function testDecodeSseMultiLineDataIsJoined(): void
    {
        // 一条 JSON 拆成两条 data: 行（在逗号后的空白处切，拼接后仍是合法 JSON）
        $line1 = '{"type": "RUN_STARTED", "threadId": "t1",';
        $line2 = '"runId": "r1"}';
        $text = "data: {$line1}\ndata: {$line2}\n\n";
        $result = Decoder::decodeSse($text);
        $this->assertSame('RUN_STARTED', $result['events'][0]['type']);
        $this->assertSame('t1', $result['events'][0]['threadId']);
        $this->assertSame([], $result['warnings']);
    }

    public function testDecodeSseIgnoresEventIdRetryAndCommentLines(): void
    {
        $text = "event: message\nid: 42\nretry: 5000\n: a comment\n"
            . 'data: ' . json_encode(['type' => 'RUN_FINISHED']) . "\n\n";
        $result = Decoder::decodeSse($text);
        $this->assertSame(['RUN_FINISHED'], $this->types($result['events']));
        $this->assertSame([], $result['warnings']);
    }

    public function testDecodeSseBadJsonFrameGoesToWarningsWithoutStopping(): void
    {
        // 双引号字符串：\n 是真换行（单引号里是字面反斜杠 n，帧解析会拿不到分帧）
        $text = "data: {not json at all}\n\ndata: " . json_encode(['type' => 'RUN_FINISHED']) . "\n\n";
        $result = Decoder::decodeSse($text);
        $this->assertCount(1, $result['warnings']);
        $this->assertStringContainsString('JSON', $result['warnings'][0]);
        $this->assertSame(['RUN_FINISHED'], $this->types($result['events']), '坏帧不中断后续帧');
    }

    public function testDecodeSseFlushesPendingEndsAtEndOfStream(): void
    {
        $text = 'data: ' . json_encode(['type' => 'TEXT_MESSAGE_CHUNK', 'messageId' => 'm1', 'delta' => 'x']) . "\n\n";
        $result = Decoder::decodeSse($text);
        $this->assertSame([
            'TEXT_MESSAGE_START', 'TEXT_MESSAGE_CONTENT', 'TEXT_MESSAGE_END',
        ], $this->types($result['events']));
    }

    // ── SseClientTest（依赖 FakeBridge 全局函数；零真实网络）──

    /** 预置帧 + open/read → poll 到终态：挂起 END 已被 finish() flush 进 Session。 */
    public function testSseClientPollsToTerminalAndFlushesPendingEnds(): void
    {
        test_reset();
        qt_app_create(['name' => 'SseClientTest']);
        qt_window_create('w');  // 测试先建窗（假状态的地基，真桥口径表先例）
        test_set_sse_stream([
            ['data' => json_encode(['type' => 'RUN_STARTED', 'threadId' => 't1', 'runId' => 'r1'])],
            ['data' => json_encode(['type' => 'TEXT_MESSAGE_CHUNK', 'messageId' => 'm1', 'delta' => 'hi '])],
            ['data' => json_encode(['type' => 'TEXT_MESSAGE_CHUNK', 'messageId' => 'm1', 'delta' => 'there'])],
        ], 200, '');

        $client = new SseClient();
        $stream = $client->run('http://example.local/agui/run', ['threadId' => 't1', 'runId' => 'r1', 'messages' => []]);
        $this->assertSame(1, $stream);

        // spec 原样透传（AG-UI run = POST + JSON 头 + json 化的 RunAgentInput）
        $request = test_sse_requests()[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame(['Content-Type' => 'application/json'], $request['headers']);
        $this->assertSame('t1', json_decode($request['body'], true)['threadId']);

        $first = $client->poll();
        $this->assertContainsEquals(['type' => 'TEXT_MESSAGE_START', 'messageId' => 'm1', 'role' => 'assistant', 'threadId' => null, 'runId' => null], $first);
        $this->assertNotContains('TEXT_MESSAGE_END', $this->types($first), '预置流（无 error）state=open：终态未到，挂起 END 不 flush');

        qt_sse_close($stream);  // 假桥：摘出流表，之后 status = unknown = 终态
        $tail = $client->poll();
        $this->assertSame([['type' => 'TEXT_MESSAGE_END', 'messageId' => 'm1']], $tail, '终态时 decoder->finish() flush 挂起 END');
        $this->assertSame([], $client->poll(), '终态后恒 []');

        $view = $client->viewModel();
        $this->assertSame('hi there', $view['messages'][0]['content']);
        $this->assertSame('running', $view['run']['status']);
        $this->assertSame([], $view['warnings']);
    }

    /** run() 必填键校验：缺键提前抛 \InvalidArgumentException（不等到开流失败才报）。 */
    public function testSseClientRunValidatesRequiredInputKeys(): void
    {
        test_reset();
        $client = new SseClient();
        $this->expectException(\InvalidArgumentException::class);
        $client->run('http://example.local/agui/run', ['threadId' => 't1']);
    }

    /** -1（假桥：url 空）时记结构化错误，后续 poll/status 可读、不抛异常。 */
    public function testSseClientOpenFailureIsReadableNotThrown(): void
    {
        test_reset();
        $client = new SseClient();
        $stream = $client->run('', ['threadId' => 't1', 'runId' => 'r1', 'messages' => []]);
        $this->assertSame(-1, $stream);
        $this->assertSame([], $client->poll());
        $status = $client->status();
        $this->assertSame('unknown', $status['state']);
        $this->assertStringContainsString('qt_sse_open 返回 -1', (string) $status['error']);
    }

    /** 直接走桥接原语：open 取预置队列 + read 截断/不重发 + status 四态 + close 幂等。 */
    public function testSseBridgePrimitivesDirect(): void
    {
        test_reset();
        test_set_sse_stream([['data' => 'a'], ['data' => 'b'], ['data' => 'c']], 200, '');
        $id = qt_sse_open(['url' => 'http://example.local/stream']);
        $this->assertSame(1, $id);

        $this->assertSame([['data' => 'a'], ['data' => 'b']], qt_sse_read($id, 2), '按 $max 截断');
        $this->assertSame([['data' => 'c']], qt_sse_read($id, 64), '游标前进，重复 read 不重发');
        $this->assertSame([], qt_sse_read($id, 64));
        $this->assertSame('open', qt_sse_status($id)['state']);

        qt_sse_close($id);
        qt_sse_close($id);  // 幂等
        $this->assertSame('unknown', qt_sse_status($id)['state'], '关流 = 流不存在（与真桥注册表摘除同口径）');
        $this->assertSame('unknown', qt_sse_status(999)['state']);
    }
}
