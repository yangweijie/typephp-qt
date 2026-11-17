<?php

declare(strict_types=1);

namespace TypePHP\Qt\Tests\AGUI;

use PHPUnit\Framework\TestCase;
use TypePHP\Qt\AGUI\Session;

/**
 * Session 单测：内联一条完整 fixture（与 42.9 示例同一事件序列的 PHP 数组版，
 * 喂的是**展开后**事件），逐条断言归约语义。数据全部内联，不依赖网络/桥接。
 */
final class SessionTest extends TestCase
{
    /** fixture 事件（42.9 示例的 PHP 数组版；不含 chunk —— 展开属于 Decoder 职责）。 */
    private static function fixtureEvents(): array
    {
        return [
            ['type' => 'RUN_STARTED', 'threadId' => 't1', 'runId' => 'r1'],
            ['type' => 'STEP_STARTED', 'stepId' => 's1', 'name' => 'plan'],
            ['type' => 'TEXT_MESSAGE_START', 'messageId' => 'm1', 'role' => 'assistant'],
            ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'm1', 'delta' => 'Hello '],
            ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'm1', 'delta' => 'world'],
            ['type' => 'TEXT_MESSAGE_END', 'messageId' => 'm1'],
            ['type' => 'TOOL_CALL_START', 'toolCallId' => 'c1', 'toolCallName' => 'search'],
            ['type' => 'TOOL_CALL_ARGS', 'toolCallId' => 'c1', 'delta' => '{"q":'],
            ['type' => 'TOOL_CALL_ARGS', 'toolCallId' => 'c1', 'delta' => '"php"}'],
            ['type' => 'TOOL_CALL_END', 'toolCallId' => 'c1'],
            ['type' => 'TOOL_CALL_RESULT', 'toolCallId' => 'c1', 'messageId' => 'mt1', 'content' => '42'],
            ['type' => 'REASONING_START'],
            ['type' => 'REASONING_MESSAGE_START', 'messageId' => 'rm1'],
            ['type' => 'REASONING_MESSAGE_CONTENT', 'messageId' => 'rm1', 'delta' => 'thinking…'],
            ['type' => 'REASONING_MESSAGE_END', 'messageId' => 'rm1'],
            ['type' => 'REASONING_END'],
            ['type' => 'STATE_SNAPSHOT', 'state' => ['a' => 1, 'b' => ['c' => 2]]],
            ['type' => 'STATE_DELTA', 'delta' => [
                ['op' => 'replace', 'path' => '/a', 'value' => 2],
                ['op' => 'add', 'path' => '/b/d', 'value' => 4],
            ]],
            ['type' => 'MESSAGES_SNAPSHOT', 'messages' => [
                ['id' => 'm1', 'role' => 'assistant', 'content' => 'Hello world'],
                ['id' => 'mt1', 'role' => 'tool', 'content' => '42'],
                ['id' => 'm2', 'role' => 'assistant', 'content' => 'second'],
            ]],
            ['type' => 'ACTIVITY_SNAPSHOT', 'id' => 'act1', 'data' => ['k' => 'v']],
            ['type' => 'ACTIVITY_SNAPSHOT', 'id' => 'act1', 'data' => ['k2' => 'v2'], 'replace' => false],
            ['type' => 'STEP_FINISHED', 'stepId' => 's1'],
            ['type' => 'RUN_FINISHED'],  // 缺 outcome = legacy 成功
        ];
    }

    public function testTextDeltasConcatenateInOrder(): void
    {
        $session = new Session();
        $events = self::fixtureEvents();
        foreach (array_slice($events, 0, 6) as $event) {
            $session->consume($event);
        }
        $view = $session->viewModel();
        $this->assertSame('Hello world', $view['messages'][0]['content'], 'delta 按 messageId 拼接、按到达序');
        $this->assertSame('closed', $view['messages'][0]['status']);
    }

    public function testFullFixtureRunsToTerminal(): void
    {
        $session = new Session();
        $events = self::fixtureEvents();

        // 中途断言：MESSAGES_SNAPSHOT 之前，reasoning 段已物化（REASONING 段断言）
        foreach (array_slice($events, 0, 16) as $event) {
            $session->consume($event);
        }
        $reasoning = null;
        foreach ($session->viewModel()['messages'] as $message) {
            if ($message['id'] === 'rm1') {
                $reasoning = $message;
            }
        }
        $this->assertNotNull($reasoning, 'REASONING_MESSAGE_* 段物化为 role=reasoning 消息');
        $this->assertSame('reasoning', $reasoning['role']);
        $this->assertSame('thinking…', $reasoning['content']);
        $this->assertSame('closed', $reasoning['status']);

        foreach (array_slice($events, 16) as $event) {
            $session->consume($event);
        }

        $view = $session->viewModel();

        // 消息数按 MESSAGES_SNAPSHOT 长度（全量替换语义，不是累计：此前流式建了 m1/mt1/rm1 共 3 条）
        $this->assertCount(3, $view['messages'], 'MESSAGES_SNAPSHOT = 全量替换');
        $this->assertSame(['m1', 'mt1', 'm2'], array_map(static fn ($m) => $m['id'], $view['messages']));

        // 工具调用 args 拼接 + result 落位
        $this->assertSame('{"q":"php"}', $session->toolCalls()['c1']['args']);
        $this->assertSame('42', $session->toolCalls()['c1']['result']);
        $this->assertSame('search', $session->toolCalls()['c1']['name']);

        // state 终值 = snapshot 之后 delta 的应用结果
        $this->assertSame(['a' => 2, 'b' => ['c' => 2, 'd' => 4]], $view['state']);

        // steps 配对
        $this->assertSame('closed', $view['steps']['s1']['status']);
        $this->assertSame('plan', $view['steps']['s1']['name']);

        // RUN_FINISHED 缺 outcome = legacy 成功
        $this->assertSame('finished', $view['run']['status']);
        $this->assertSame('legacy', $view['run']['outcome']);
        $this->assertSame('t1', $view['run']['threadId']);

        // ACTIVITY replace 语义：replace=false 的 snapshot 浅合并
        $this->assertSame(['k' => 'v', 'k2' => 'v2'], $view['activity']['act1']);

        // 全程零 warning（fixture 是规范流）
        $this->assertSame([], $view['warnings']);
    }

    /** TOOL_CALL_RESULT 新建一条 role=tool 消息（spec 语义）。 */
    public function testToolCallResultCreatesToolMessage(): void
    {
        $session = new Session();
        foreach (array_slice(self::fixtureEvents(), 6, 5) as $event) {
            $session->consume($event);
        }
        $messages = $session->viewModel()['messages'];
        $this->assertCount(1, $messages);
        $this->assertSame('tool', $messages[0]['role']);
        $this->assertSame('c1', $messages[0]['toolCallId']);
        $this->assertSame('42', $messages[0]['content']);
    }

    /** step 重开（同名已开）= 记 warning、不静默。 */
    public function testStepReopenWarns(): void
    {
        $session = new Session();
        $session->consume(['type' => 'STEP_STARTED', 'stepId' => 's1', 'name' => 'plan']);
        $session->consume(['type' => 'STEP_STARTED', 'stepId' => 's1', 'name' => 'plan']);
        $view = $session->viewModel();
        $this->assertCount(1, $view['warnings']);
        $this->assertStringContainsString('重开', $view['warnings'][0]);
        $this->assertSame('open', $view['steps']['s1']['status'], '重开不改状态（仍 open），只记账');
    }

    /** STATE_DELTA 缺省（无 delta 键）容忍：state 原样、warning 来自 JsonPatch 降级。 */
    public function testStateDeltaWithoutDeltaKeyIsTolerated(): void
    {
        $session = new Session();
        $session->consume(['type' => 'STATE_SNAPSHOT', 'state' => ['a' => 1]]);
        $session->consume(['type' => 'STATE_DELTA']);
        $view = $session->viewModel();
        $this->assertSame(['a' => 1], $view['state'], '缺 delta = 零 op，state 不变');
        $this->assertSame([], $view['warnings']);
    }

    /** SUBAGENT_* 记账（键名 subagents 拼写陷阱）+ 挂进 run 视图。 */
    public function testSubagentAccounting(): void
    {
        $session = new Session();
        $session->consume(['type' => 'SUBAGENT_STARTED', 'subagentId' => 'sub-a', 'name' => 'finder']);
        $session->consume(['type' => 'SUBAGENT_FINISHED', 'subagentId' => 'sub-a']);
        $view = $session->viewModel();
        $this->assertSame('finished', $view['run']['subagents']['sub-a']['status']);
        $this->assertSame('finder', $view['run']['subagents']['sub-a']['name']);
    }

    /** 已关消息再 CONTENT = 重开继续追加（宽容语义，Session 层接住）。 */
    public function testContentAfterClosedMessageAppends(): void
    {
        $session = new Session();
        $session->consume(['type' => 'TEXT_MESSAGE_START', 'messageId' => 'm1', 'role' => 'assistant']);
        $session->consume(['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'm1', 'delta' => 'one']);
        $session->consume(['type' => 'TEXT_MESSAGE_END', 'messageId' => 'm1']);
        $session->consume(['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'm1', 'delta' => ' two']);
        $view = $session->viewModel();
        $this->assertSame('one two', $view['messages'][0]['content']);
        $this->assertSame('open', $view['messages'][0]['status'], '重开后回到 open（等下一个 END）');
    }
}
