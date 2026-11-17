<?php
declare(strict_types=1);

use TypePHP\Qt\AGUI\Decoder;
use TypePHP\Qt\AGUI\Session;
use TypePHP\Qt\QtApp;
use TypePHP\Qt\WidgetTree;

/**
 * agui-demo — AG-UI（Agent User Interaction Protocol）离线回放示例。
 *
 * 三层结构的示范（AGENTS.md）：业务逻辑/状态/校验全在 PHP（src/AGUI/ 协议层 +
 * 本文件的回放主线），C++ 薄桥只做 SSE 分帧与 Qt 对象创建，Qt 只显示、只上报。
 *
 * 三种模式（均不依赖外部网络）：
 *   --selftest  无头自检：① 纯 PHP 回放断言（fixture → 解码 → 展开 → 归约 →
 *               viewModel）② 控件树读回断言（viewModel → WidgetTree → value()）
 *               ③ 桥接 smoke（fixture 写临时文件 → file:// SSE 流走真实 C++
 *               分帧路径，150 帧上限防死循环）。失败一条即 exit(1)（F34 先例）。
 *   --bench [N] 纯 PHP 回放 N 事件（缺省 20000，fixture 循环复用）计时，
 *               stdout 打三行 METRIC 供 autoresearch 的 measure.sh 解析。
 *               在 Qt 创建**之前**完成并 exit(0)（惰性单例，不碰事件循环）。
 *   （无参数）   GUI 主循环：窗口显示回放后的界面，可交互查看。
 *
 * 配对规则（AGENTS.md）：project.yml 列了 src/AGUI/SseClient.php 就必须同时列
 * cpp-src/qt_sse.cc（类体引用 \qt_sse_*，缺 TU 则链接期 undefined symbol）。
 * 注意 AOT：全局作用域只放声明（本文件全部是函数声明 + use），闭包参数显式
 * 类型标注，跨文件桥接走 QtApp 方法（内部 \qt_* 全限定）。
 */

/**
 * 内置 AG-UI 事件 fixture（29 事件）：离线回放主线的数据源。
 *
 * 覆盖协议层的主干与容错路径：
 *   RUN_STARTED → STEP_STARTED → 文本消息（3 delta 拼接）→ 两个工具调用
 *   （args 双段拼接 + result 落位）→ STATE_SNAPSHOT（整体替换）→ STATE_DELTA
 *   （JsonPatch 两个 op）→ reasoning 段 → MESSAGES_SNAPSHOT（**全量替换**，
 *   消息数断言的基准）→ STEP_FINISHED → RUN_FINISHED（outcome=success）。
 * 故意混入：
 *   - 一条 legacy THINKING_TEXT_MESSAGE_CONTENT（spec 映射表名，映射为
 *     REASONING_MESSAGE_CONTENT + mapping warning，测解码器容错路径）；
 *   - 一条 CUSTOM（untrusted 纪律位）与一条未知 EXPERIMENTAL_EVENT
 *     （'unknown' 标记 → Session 记 warning 忽略，测未知事件透传）。
 * 用函数返回而不用全局 const：零 AOT 声明形态风险（42.9 钉死）。
 * 每条事件恰好一个 `data:` 行（帧数 == 事件数的断言基准）。
 */
function aguiFixtureEvents(): array
{
    return [
        ['type' => 'RUN_STARTED', 'threadId' => 'thread-1', 'runId' => 'run-1'],
        ['type' => 'STEP_STARTED', 'stepId' => 'step_search', 'name' => 'Searching'],
        ['type' => 'TEXT_MESSAGE_START', 'messageId' => 'm1', 'role' => 'assistant'],
        ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'm1', 'delta' => 'Hello '],
        ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'm1', 'delta' => 'from '],
        ['type' => 'TEXT_MESSAGE_CONTENT', 'messageId' => 'm1', 'delta' => 'AG-UI'],
        ['type' => 'TEXT_MESSAGE_END', 'messageId' => 'm1'],
        ['type' => 'TOOL_CALL_START', 'toolCallId' => 'tc1', 'toolCallName' => 'search'],
        ['type' => 'TOOL_CALL_ARGS', 'toolCallId' => 'tc1', 'delta' => '{"q":'],
        ['type' => 'TOOL_CALL_ARGS', 'toolCallId' => 'tc1', 'delta' => '"qt"}'],
        ['type' => 'TOOL_CALL_END', 'toolCallId' => 'tc1'],
        ['type' => 'TOOL_CALL_RESULT', 'toolCallId' => 'tc1', 'messageId' => 'tool_tc1', 'content' => '2 results'],
        ['type' => 'TOOL_CALL_START', 'toolCallId' => 'tc2', 'toolCallName' => 'summarize'],
        ['type' => 'TOOL_CALL_ARGS', 'toolCallId' => 'tc2', 'delta' => '{"max":3}'],
        ['type' => 'TOOL_CALL_END', 'toolCallId' => 'tc2'],
        ['type' => 'TOOL_CALL_RESULT', 'toolCallId' => 'tc2', 'messageId' => 'tool_tc2', 'content' => 'ok'],
        ['type' => 'STATE_SNAPSHOT', 'state' => ['phase' => 'search', 'count' => 0]],
        ['type' => 'STATE_DELTA', 'delta' => [
            ['op' => 'replace', 'path' => '/count', 'value' => 2],
            ['op' => 'add', 'path' => '/results', 'value' => ['Qt', 'AG-UI']],
        ]],
        ['type' => 'REASONING_START'],
        ['type' => 'REASONING_MESSAGE_START', 'messageId' => 'r1'],
        ['type' => 'REASONING_MESSAGE_CONTENT', 'messageId' => 'r1', 'delta' => 'weighing options'],
        ['type' => 'REASONING_MESSAGE_END', 'messageId' => 'r1'],
        ['type' => 'REASONING_END'],
        // legacy 容错：映射表名就是 THINKING_TEXT_MESSAGE_*（表内没有 THINKING_MESSAGE_*）
        ['type' => 'THINKING_TEXT_MESSAGE_CONTENT', 'messageId' => 'r2', 'delta' => 'legacy'],
        // 不可信 passthrough：解码器打 'untrusted' 纪律位，Session 不消费
        ['type' => 'CUSTOM', 'name' => 'telemetry', 'value' => ['seq' => 1]],
        // 未知 type：'unknown' 标记 → Session 记 warning 忽略（仓库兼容默认）
        ['type' => 'EXPERIMENTAL_EVENT', 'payload' => 'probe'],
        // MESSAGES_SNAPSHOT = 全量历史替换（不是追加）：消息数断言按 snapshot 长度
        ['type' => 'MESSAGES_SNAPSHOT', 'messages' => [
            ['id' => 'u1', 'role' => 'user', 'content' => 'hi'],
            ['id' => 'm1', 'role' => 'assistant', 'content' => 'Hello from AG-UI'],
            ['id' => 'u2', 'role' => 'user', 'content' => 'more'],
        ]],
        ['type' => 'STEP_FINISHED', 'stepId' => 'step_search', 'name' => 'Searching'],
        ['type' => 'RUN_FINISHED', 'threadId' => 'thread-1', 'runId' => 'run-1', 'outcome' => 'success'],
    ];
}

/** fixture 的 SSE 文本形态：每事件一个 `data:` 行 + 空行分帧。 */
function aguiFixture(): string
{
    $lines = [];
    foreach (aguiFixtureEvents() as $event) {
        $lines[] = 'data: ' . json_encode($event, JSON_UNESCAPED_UNICODE);
    }
    $text = '';
    foreach ($lines as $line) {
        $text .= $line . "\n\n";
    }
    return $text;
}

/**
 * 离线回放主线：fixture → Decoder（一次性 decodeSse，含 finish() flush）
 * → Session → viewModel。返回渲染与断言需要的全部材料。
 */
function aguiReplay(): array
{
    $decoded = Decoder::decodeSse(aguiFixture());
    $session = new Session();
    foreach ($decoded['events'] as $event) {
        $session->consume($event);
    }
    return [
        'view_model' => $session->viewModel(),
        'tool_calls' => $session->toolCalls(),
        'decode_warnings' => $decoded['warnings'],
    ];
}

/**
 * --bench 的计时主体：纯 PHP 回放 $events 个事件（fixture 循环复用，
 * 每个批次 = 一次完整「展开 + 归约」，Decoder/Session 按批新建 ——
 * 流域展开状态不跨批复用，测的是协议层稳态吞吐而非一次性初始化）。
 * 计时用 hrtime；本函数**不建 Qt 应用**（调用方在 new QtApp() 之前跑完并 exit）。
 */
function bench_replay(int $events): array
{
    $batch = aguiFixtureEvents();
    $size = count($batch);
    $start = hrtime(true);
    $fed = 0;
    while ($fed < $events) {
        $count = min($size, $events - $fed);
        $batchEvents = array_slice($batch, 0, $count);
        $decoder = new Decoder();
        $expanded = $decoder->feed($batchEvents);
        $expanded = array_merge($expanded, $decoder->finish());
        $session = new Session();
        foreach ($expanded as $event) {
            $session->consume($event);
        }
        $fed += $count;
    }
    $elapsedNs = hrtime(true) - $start;
    $perSecond = $elapsedNs > 0 ? intdiv($events * 1000000000, (int) $elapsedNs) : 0;
    return [
        'events' => $events,
        'total_ms' => $elapsedNs / 1000000.0,
        'per_s' => $perSecond,
    ];
}

/** --bench 的事件数：`--bench N`（缺省 20000，非法值回落缺省）。 */
function bench_event_count(array $argv): int
{
    $count = count($argv);
    for ($i = 1; $i < $count; $i++) {
        if ($argv[$i] === '--bench') {
            if ($i + 1 < $count && is_numeric((string) $argv[$i + 1]) && (int) $argv[$i + 1] > 0) {
                return (int) $argv[$i + 1];
            }
            return 20000;
        }
    }
    return 20000;
}

/**
 * 无头自检：三段判据全过才 passed，任何一条失败返回非零（main 里 exit(1)，
 * 判据走 rc = F34 先例）。
 */
function self_test(QtApp $app, array $replay): int
{
    $app->headless(true);

    $failed = 0;
    $check = function (string $label, bool $ok, string $got) use (&$failed): void {
        if ($ok) {
            echo "ok   {$label}\n";
            return;
        }
        echo "FAIL {$label} -> {$got}\n";
        $failed++;
    };

    $viewModel = $replay['view_model'];
    $toolCalls = $replay['tool_calls'];

    // ── ① 纯 PHP 段：fixture → 解码 → 展开 → 归约 → viewModel 断言 ──

    $messages = $viewModel['messages'];
    // MESSAGES_SNAPSHOT 替换语义：数 = snapshot 长度 3，不是累计（评审轮修订②）
    $check('消息数按 MESSAGES_SNAPSHOT 替换语义（3）', count($messages) === 3, (string) count($messages));
    $m1 = null;
    $roles = [];
    foreach ($messages as $message) {
        $roles[] = (string) ($message['role'] ?? '');
        if (($message['id'] ?? '') === 'm1') {
            $m1 = $message;
        }
    }
    $m1Content = $m1 !== null ? (string) ($m1['content'] ?? '') : '';
    $m1Role = $m1 !== null ? (string) ($m1['role'] ?? '') : '';
    $check('snapshot 里 m1 的文本与 role', $m1Content === 'Hello from AG-UI' && $m1Role === 'assistant', $m1Role . ': ' . $m1Content);
    $check('角色序（user/assistant/user）', $roles === ['user', 'assistant', 'user'], json_encode($roles));

    $tc1 = isset($toolCalls['tc1']) ? $toolCalls['tc1'] : null;
    $tc2 = isset($toolCalls['tc2']) ? $toolCalls['tc2'] : null;
    $tc1Args = $tc1 !== null ? (string) ($tc1['args'] ?? '') : '';
    $tc1Result = $tc1 !== null ? (string) ($tc1['result'] ?? '') : '';
    $check('工具调用 tc1 的 args 双段拼接 + result 落位', $tc1Args === '{"q":"qt"}' && $tc1Result === '2 results', $tc1Args . ' / ' . $tc1Result);
    $check('工具调用 tc2 的 name + result', $tc2 !== null && ($tc2['name'] ?? '') === 'summarize' && ($tc2['result'] ?? '') === 'ok', json_encode($tc2, JSON_UNESCAPED_UNICODE));

    $state = $viewModel['state'];
    $count = $state['count'] ?? null;
    $results = $state['results'] ?? null;
    $check('state 终值（STATE_DELTA 叠加在 SNAPSHOT 上）', $count === 2 && $results === ['Qt', 'AG-UI'] && ($state['phase'] ?? '') === 'search', json_encode($state, JSON_UNESCAPED_UNICODE));

    $step = isset($viewModel['steps']['step_search']) ? $viewModel['steps']['step_search'] : null;
    $check('steps 开/关配对（step_search closed）', $step !== null && ($step['status'] ?? '') === 'closed', json_encode($step, JSON_UNESCAPED_UNICODE));

    $run = $viewModel['run'];
    $check('RUN_FINISHED 终态 + outcome', ($run['status'] ?? '') === 'finished' && ($run['outcome'] ?? '') === 'success', json_encode($run, JSON_UNESCAPED_UNICODE));

    $decodeWarnings = $replay['decode_warnings'];
    $mapped = false;
    foreach ($decodeWarnings as $warning) {
        if (str_contains($warning, 'THINKING_TEXT_MESSAGE_CONTENT')) {
            $mapped = true;
        }
    }
    $check('legacy THINKING_TEXT_MESSAGE_CONTENT 映射 warning', $mapped, json_encode($decodeWarnings, JSON_UNESCAPED_UNICODE));
    $sessionWarnings = $viewModel['warnings'];
    $unknownNoted = false;
    foreach ($sessionWarnings as $warning) {
        if (str_contains($warning, 'EXPERIMENTAL_EVENT')) {
            $unknownNoted = true;
        }
    }
    $check('未知事件 EXPERIMENTAL_EVENT 记 warning 忽略', $unknownNoted, json_encode($sessionWarnings, JSON_UNESCAPED_UNICODE));

    // ── ② 控件渲染段：viewModel → WidgetTree → 读回断言（不靠像素）──
    // 读回走控件树（value()/find()），**不直读引用参数里的状态**（AOT 引用
    // 参数断链，AGENTS.md AOT 节 F53 条）：$replay 是 main() 按值传入的
    // 「渲染后界面该长什么样」的完整材料，读回值必须与它一致才算控件真收到数据。
    $app->runFrames(1);

    $expectedLabels = ['msg_u1', 'msg_m1', 'msg_u2'];
    foreach ($expectedLabels as $id) {
        $check('控件 ' . $id . ' 存在（动态孩子显式 id）', $app->find($id) !== null, 'find 返回 null');
    }
    $m1Text = (string) $app->value('msg_m1');
    $check('消息文本到达控件（value 读回 msg_m1）', $m1Text === '[assistant] Hello from AG-UI', $m1Text);
    $titleText = (string) $app->value('chat_title');
    $check('标题标签读回', $titleText === 'AG-UI 离线回放 · 消息列表', $titleText);

    $tableValue = $app->value('tools_table');
    $tableRow = is_array($tableValue) ? ($tableValue['row'] ?? null) : null;
    $tableSelected = is_array($tableValue) ? ($tableValue['value'] ?? null) : null;
    $check('工具表选中行 id 读回（row_ids 落进 Qt::UserRole）', $tableRow === 0 && $tableSelected === 'tc1', json_encode($tableValue, JSON_UNESCAPED_UNICODE));

    // ── ③ 桥接 smoke 段：file:// 离线流走真实 C++ SSE 分帧路径 ──
    // QNetworkAccessManager 原生支持 file: scheme，零外部网络；帧分帧逻辑
    // 与真实 SSE 同一条 C++ 代码路径。泵帧设 150 帧上限（≈2.4s，qmlclock
    // main.php:159 先例；评审轮修订①：超时即 FAIL，防无头 selftest 死循环）。
    $tmpFile = tempnam(sys_get_temp_dir(), 'agui-sse-');
    $tmpPath = (string) $tmpFile;
    file_put_contents($tmpPath, aguiFixture());
    $url = 'file://' . $tmpPath;
    $stream = $app->sseOpen(['url' => $url, 'method' => 'GET']);
    if ($stream < 0) {
        $check('sseOpen(file://) 成功', false, '返回 ' . (string) $stream);
    } else {
        $state = 'open';
        $pumped = 0;
        for ($i = 0; $i < 150; $i++) {
            $app->runFrames(1);
            $pumped++;
            $status = $app->sseStatus($stream);
            $state = (string) ($status['state'] ?? '');
            if ($state === 'done' || $state === 'error') {
                break;
            }
        }
        $check('SSE 流 150 帧内到达 done（超时 = FAIL）', $state === 'done', 'state=' . $state . ' pumped=' . (string) $pumped);
        $frames = $app->sseRead($stream, 512);
        $expectedFrames = count(aguiFixtureEvents());
        $check('帧数 == fixture 事件数', count($frames) === $expectedFrames, count($frames) . ' != ' . (string) $expectedFrames);
        $lastFrame = [];
        if (count($frames) > 0) {
            $lastFrame = $frames[count($frames) - 1];
        }
        $lastData = (string) ($lastFrame['data'] ?? '');
        $lastEvent = json_decode($lastData, true);
        $check('最后一帧 JSON 可解码且是 RUN_FINISHED', $lastEvent !== null && ($lastEvent['type'] ?? '') === 'RUN_FINISHED', $lastData);
        $app->sseClose($stream);
        $closeStatus = $app->sseStatus($stream);
        $check('close 后状态为 unknown（幂等/摘流语义）', ($closeStatus['state'] ?? '') === 'unknown', json_encode($closeStatus, JSON_UNESCAPED_UNICODE));
    }
    @unlink($tmpPath);

    echo $failed === 0 ? "selftest passed\n" : "selftest failed: $failed\n";
    return $failed;
}

/** viewModel + 工具调用表 → 控件树（v1 用现有控件组合，不新增控件类型）。 */
function aguiView(array $viewModel, array $toolCalls): array
{
    $labels = [WidgetTree::label('AG-UI 离线回放 · 消息列表', ['id' => 'chat_title'])];
    foreach ($viewModel['messages'] as $message) {
        $messageId = (string) ($message['id'] ?? '');
        if ($messageId === '') {
            continue;
        }
        // 消息列表是动态容器（MESSAGES_SNAPSHOT 全量替换）：每个孩子必须显式
        // id（AGENTS.md 渲染坑），否则前面兄弟数量一变，后面无 id 节点的路径
        // id 全换、每帧重建。
        $labels[] = WidgetTree::label(
            '[' . (string) ($message['role'] ?? '') . '] ' . (string) ($message['content'] ?? ''),
            ['id' => 'msg_' . $messageId]
        );
    }

    $rows = [];
    $rowIds = [];
    foreach ($toolCalls as $toolCallId => $toolCall) {
        $result = $toolCall['result'] ?? null;
        $resultText = is_scalar($result) ? (string) $result : json_encode($result, JSON_UNESCAPED_UNICODE);
        $rows[] = [
            (string) ($toolCall['name'] ?? ''),
            (string) ($toolCall['args'] ?? ''),
            $resultText,
        ];
        $rowIds[] = (string) $toolCallId;
    }
    $tableProps = [
        'id' => 'tools_table',
        'row_ids' => $rowIds,
        'size' => [480, 90],
    ];
    if ($rowIds !== []) {
        // current 传行 id（不是下标）：按 row_ids 落进 Qt::UserRole 的行匹配选中，
        // value() 读回的就是行 id 而不是下标 —— 42.9 的「行 id = toolCallId」判据。
        $tableProps['current'] = $rowIds[0];
    }

    return WidgetTree::scroll([
        WidgetTree::vbox($labels),
        WidgetTree::table(['工具', '参数', '结果'], $rows, $tableProps),
    ], ['id' => 'chat', 'grow' => 1]);
}

/** 命令行里是否存在某个布尔开关。 */
function has_flag(array $argv, string $flag): bool
{
    $count = count($argv);
    for ($i = 1; $i < $count; $i++) {
        if ($argv[$i] === $flag) {
            return true;
        }
    }
    return false;
}

/**
 * 全局作用域只放声明（AOT）：$replay 在 main() 里。
 * --bench 段在 new QtApp()（惰性单例）之前完成并 exit(0)：QApplication 不建、
 * 不碰 Qt 事件循环，计时不混入 GUI 噪声（42.9 钉死）。
 */
function main(int $argc, array $argv): void
{
    if (has_flag($argv, '--bench')) {
        $report = bench_replay(bench_event_count($argv));
        echo 'METRIC agui_replay_events_per_s=' . (string) $report['per_s'] . "\n";
        echo 'METRIC agui_replay_total_ms=' . (string) $report['total_ms'] . "\n";
        echo 'METRIC agui_bench_events=' . (string) $report['events'] . "\n";
        exit(0);
    }

    $app = new QtApp();
    $app->create(['name' => 'AguiDemo', 'version' => '1.0', 'organization' => 'TypePHP']);
    $app->createWindow('AG-UI 离线回放', [
        'width' => 640,
        'height' => 520,
        'min_width' => 420,
        'min_height' => 320,
        'centered' => true,
    ]);

    // 离线回放主线（零外部网络）：fixture → 解码展开 → 归约 → 视图模型。
    $replay = aguiReplay();
    $viewModel = $replay['view_model'];
    $toolCalls = $replay['tool_calls'];

    $app->view(function () use ($viewModel, $toolCalls): array {
        return aguiView($viewModel, $toolCalls);
    });

    $run = $viewModel['run'];
    $app->setStatus([
        'AG-UI 回放：' . count($viewModel['messages']) . ' 条消息 · ' . count($toolCalls) . ' 个工具调用 · run=' . (string) ($run['status'] ?? 'idle'),
    ]);

    if (has_flag($argv, '--selftest')) {
        $failed = self_test($app, $replay);
        $app->destroy();
        if ($failed > 0) {
            exit(1);
        }
        return;
    }

    try {
        while ($app->isOpen()) {
            $app->runFrames(1);
        }
    } finally {
        $app->destroy();
    }
}
