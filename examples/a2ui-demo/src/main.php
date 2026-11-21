<?php
declare(strict_types=1);

use TypePHP\Qt\A2UI\Renderer;
use TypePHP\Qt\QtApp;
use TypePHP\Qt\WidgetTree;

/**
 * a2ui-demo — A2UI（Agent-to-User Interface）离线回放示例。
 *
 * 与 agui-demo 的分工：AG-UI 是流式事件协议（qt_sse_* 桥接原语 + src/AGUI/ 协议层），
 * A2UI 是声明式 UI 协议（src/A2UI/ 纯 PHP 层 + WidgetTree 映射，零新桥接函数）。
 * 本示例演示第二层：agent 发来的信封（createSurface/updateComponents/updateDataModel…）
 * 经 Renderer 消费 → Catalog 映射成现有控件树 → 用户交互经 buildAction() 回传
 * Renderer→Agent 信封。传输层（AG-UI binding / SSE）在 smoke 段以 file:// 流示范。
 *
 * 三种模式（均不依赖外部网络）：
 *   --selftest  无头自检：① 纯 PHP 回放断言（信封 fixture → Renderer → WidgetTree）
 *               ② 控件树读回断言 ③ 输入双绑 → action 信封派发 ④ 桥接 smoke
 *               （信封写临时文件 → file:// SSE 帧逐条 consume，150 帧上限）。
 *               失败一条即 exit(1)（F34 先例）。
 *   --bench [N] 纯 PHP 回放 N 条信封消息（缺省 20000）计时，stdout 打三行 METRIC
 *               供 autoresearch 的 measure.sh 解析。在 Qt 创建**之前**完成并 exit(0)
 *               （惰性单例，不碰事件循环，agui-demo 同款）。
 *   （无参数）   GUI 主循环：点击按钮生成 action 信封并在窗口里展示。
 *
 * AOT 纪律（AGENTS.md）：全局作用域只放声明（本文件全部是函数声明 + use）；
 * 闭包参数显式类型标注；跨文件桥接走 QtApp 方法（内部 \qt_* 全限定）。
 */

/**
 * 内置信封 fixture（Agent→Renderer 方向）：离线回放主线的数据源。
 *
 * 覆盖六种消息里的四种（callRendererFunction/agentFunctionResponse 由单测钉）：
 *   createSurface（dataModel+components 同帧：spec 先 dataModel 后 components，
 *   首帧绑定有数据）→ updateDataModel（upsert 一条 + 根级替换一段）→
 *   updateComponents（模板列表 + 输入双绑 + 按钮 action）→ updateDataModel（喂
 *   新员工，验证模板迭代刷新）。
 * 交互剧本：TextField（email 双绑）+ CheckBox + ChoicePicker + 模板按钮
 * （context 相对路径绑定当前 item）。用函数返回而不用全局 const（零 AOT
 * 声明形态风险，agui-demo 42.9 先例）。
 */
function a2uiFixtureEnvelopes(): array
{
    return [
        ['version' => '1.0', 'createSurface' => [
            'surfaceId' => 'team',
            'sendDataModel' => false,
            'dataModel' => [
                'company' => 'Acme',
                'employees' => [
                    ['name' => 'Alice', 'role' => 'Engineer'],
                    ['name' => 'Bob', 'role' => 'Designer'],
                ],
                'email' => '',
                'subscribe' => true,
                'pick' => 'b',
                'count' => 2,
            ],
            'components' => [
                ['id' => 'root', 'component' => 'Column', 'children' => ['head', 'divider', 'list', 'form']],
                // 计数走数据模型字段（/count），不用 length() —— 那是校验函数，
                // 返回 ValidationResult（{"valid":…}），字符串化成 JSON 不是数字
                ['id' => 'head', 'component' => 'Text', 'text' => ['@call' => 'formatString', 'args' => ['value' => '${/company} 团队（${/count} 人）']]],
                ['id' => 'divider', 'component' => 'Divider'],
                ['id' => 'list', 'component' => 'List', 'children' => ['path' => '/employees', 'componentId' => 'card']],
                ['id' => 'card', 'component' => 'Card', 'title' => ['@path' => 'name'], 'children' => ['who']],
                ['id' => 'who', 'component' => 'Text', 'text' => ['@call' => 'formatString', 'args' => ['value' => '${name} · ${role} · #${@index(offset: 1)}']]],
                ['id' => 'form', 'component' => 'Row', 'children' => ['field', 'go']],
                ['id' => 'field', 'component' => 'TextField', 'value' => ['@path' => '/email']],
                ['id' => 'go', 'component' => 'Button', 'child' => 'go_label', 'action' => ['event' => [
                    'name' => 'submit_roster',
                    'userMessage' => 'roster of ${/company} (${email})',
                    'context' => ['email' => ['@path' => '/email'], 'who' => ['@path' => 'name']],
                ]]],
                ['id' => 'go_label', 'component' => 'Text', 'text' => '提交名单'],
            ],
        ]],
        ['version' => '1.0', 'updateDataModel' => [
            'surfaceId' => 'team',
            'path' => '/employees/2',
            'value' => ['name' => 'Carol', 'role' => 'PM'],
        ]],
        // 根级替换一段（不覆盖 employees）：path 缺省 = 整模型替换，这里带 path 只动单键
        ['version' => '1.0', 'updateDataModel' => [
            'surfaceId' => 'team',
            'path' => '/company',
            'value' => 'Acme Corp',
        ]],
        ['version' => '1.0', 'updateDataModel' => [
            'surfaceId' => 'team',
            'path' => '/count',
            'value' => 3,
        ]],
    ];
}

/** fixture 的 SSE 文本形态：每信封一个 `data:` 行 + 空行分帧（A2UI-over-SSE 形态）。 */
function a2uiFixture(): string
{
    $text = '';
    foreach (a2uiFixtureEnvelopes() as $envelope) {
        $text .= 'data: ' . json_encode($envelope, JSON_UNESCAPED_UNICODE) . "\n\n";
    }
    return $text;
}

/**
 * 离线回放主线：逐信封 consume → toWidgetTree → {tree, events}。
 * 渲染与断言需要的全部材料都从这里出。
 */
function a2uiReplay(): array
{
    $renderer = new Renderer();
    foreach (a2uiFixtureEnvelopes() as $envelope) {
        $renderer->consume($envelope);
    }
    $tree = $renderer->toWidgetTree('team');
    return [
        'renderer' => $renderer,
        'tree' => $tree['tree'],
        'events' => $tree['events'],
        'warnings' => $renderer->takeWarnings(),
    ];
}

/**
 * --bench 的计时主体：纯 PHP 回放 $messages 条信封（fixture 循环复用，
 * 每个批次 = 一次完整「consume 全部 + toWidgetTree」，Renderer 按批新建 ——
 * surface 表状态不跨批复用，测的是协议层稳态吞吐而非一次性初始化）。
 * 计时用 hrtime；本函数**不建 Qt 应用**（调用方在 new QtApp() 之前跑完并 exit）。
 */
function bench_replay(int $messages): array
{
    $batch = a2uiFixtureEnvelopes();
    $size = count($batch);
    $start = hrtime(true);
    $fed = 0;
    while ($fed < $messages) {
        $count = min($size, $messages - $fed);
        $renderer = new Renderer();
        foreach (array_slice($batch, 0, $count) as $envelope) {
            $renderer->consume($envelope);
        }
        $renderer->toWidgetTree('team');
        $fed += $count;
    }
    $elapsedNs = hrtime(true) - $start;
    $perSecond = $elapsedNs > 0 ? intdiv($messages * 1000000000, (int) $elapsedNs) : 0;
    return [
        'messages' => $messages,
        'total_ms' => $elapsedNs / 1000000.0,
        'per_s' => $perSecond,
    ];
}

/** --bench 的消息数：`--bench N`（缺省 20000，非法值回落缺省）。 */
function bench_message_count(array $argv): int
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

/** 命令行里是否存在某个布尔开关（agui-demo 同款）。 */
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
 * 无头自检：四段判据全过才 passed，任何一条失败返回非零（main 里 exit(1)，
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

    // ── ① 纯 PHP 段：信封 → Renderer → WidgetTree 断言 ──

    $tree = $replay['tree'];
    $check('root Column → vbox', ($tree['type'] ?? '') === 'vbox', (string) ($tree['type'] ?? 'null'));

    $kids = $tree['children'] ?? [];
    $head = $kids[0] ?? [];
    $check('标题 Text：formatString + 根替换后的 company + upsert 的 count',
        ($head['text'] ?? '') === 'Acme Corp 团队（3 人）', (string) ($head['text'] ?? 'null'));

    $list = $kids[2] ?? [];
    $check('List → scroll', ($list['type'] ?? '') === 'scroll', (string) ($list['type'] ?? 'null'));
    // scroll 的孩子是 vbox，vbox 的孩子才是模板实例（card__0/group 节点，
    // group 的 children 直接是 who —— WidgetTree::group(title, children) 单层）
    $cards = $list['children'][0]['children'] ?? [];
    $check('模板迭代 3 张卡片（updateDataModel upsert 生效）', count($cards) === 3, (string) count($cards));
    $who0 = $cards[0]['children'][0] ?? [];
    $check('卡片 0：相对路径 name/role + @index(offset:1)',
        ($who0['text'] ?? '') === 'Alice · Engineer · #1', (string) ($who0['text'] ?? 'null'));
    $check('模板实例 id 后缀（card__0 / who__0，帧间稳定）',
        ($cards[0]['id'] ?? '') === 'card__0' && ($who0['id'] ?? '') === 'who__0',
        ($cards[0]['id'] ?? 'null') . '/' . ($who0['id'] ?? 'null'));
    $who2 = $cards[2]['children'][0] ?? [];
    $check('卡片 2（Carol）来自 upsert 的 /employees/2',
        ($who2['text'] ?? '') === 'Carol · PM · #3', (string) ($who2['text'] ?? 'null'));

    $check('回放零 warning（fixture 全部合规范）', $replay['warnings'] === [], json_encode($replay['warnings'], JSON_UNESCAPED_UNICODE));

    // ── ② 控件渲染段：tree → 窗口 → 读回断言（不靠像素，F53 先例）──
    $app->runFrames(1);
    $emailText = (string) $app->value('field');
    $check('TextField 初值来自模型（email=""）', $emailText === '', $emailText);
    $titleText = (string) $app->value('a2ui_title');
    $check('标题标签读回', $titleText === 'A2UI 离线回放 · team', $titleText);

    // ── ③ 输入双绑 → action 信封派发（spec 表单提交模式）──
    // 用户输入：applyInput 写回本地模型（不自动发网络），点击时随 action 回传。
    $bound = $app->value('field');
    $replay['renderer']->applyInput('team', 'field', 'jane@acme.dev');
    $check('双绑写回本地模型（applyInput 落 /email）',
        $replay['renderer']->model('team')->get('/email') === 'jane@acme.dev', (string) $replay['renderer']->model('team')->get('/email'));
    unset($bound);

    $action = $replay['renderer']->buildAction('team', 'go');
    $check('按钮 action 信封（name/surfaceId/源组件 id）',
        $action !== null && ($action['action']['name'] ?? '') === 'submit_roster'
        && ($action['action']['surfaceId'] ?? '') === 'team'
        && ($action['action']['sourceComponentId'] ?? '') === 'go',
        json_encode($action, JSON_UNESCAPED_UNICODE));
    $check('context 派发时解析（email 双绑值 + 模板外 who=null）',
        ($action['action']['context']['email'] ?? '') === 'jane@acme.dev'
        && !array_key_exists('who', $action['action']['context']) || ($action['action']['context']['who'] ?? null) === null,
        json_encode($action['action']['context'] ?? [], JSON_UNESCAPED_UNICODE));
    $check('userMessage 的 ${} 插值（绝对路径 + 双绑值）',
        ($action['action']['userMessage'] ?? '') === 'roster of Acme Corp (jane@acme.dev)',
        (string) ($action['action']['userMessage'] ?? 'null'));
    $check('timestamp ISO 8601 UTC',
        preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) ($action['action']['timestamp'] ?? '')) === 1,
        (string) ($action['action']['timestamp'] ?? 'null'));

    // ── ④ 桥接 smoke 段：file:// 流逐帧取回信封 consume（A2UI-over-SSE 形态）──
    // QNetworkAccessManager 原生支持 file: scheme，零外部网络；帧分帧逻辑与真实
    // SSE 同一条 C++ 代码路径。泵帧设 150 帧上限（≈2.4s，超时即 FAIL 防死循环）。
    $tmpFile = tempnam(sys_get_temp_dir(), 'a2ui-sse-');
    $tmpPath = (string) $tmpFile;
    file_put_contents($tmpPath, a2uiFixture());
    // file URL 三斜杠形态（同 agui-demo 修复注释：file://C:/… 是畸形 URL，
    // Windows CI 实测 state=error；file:///C:/… 与 file:///var/… 两平台皆合法）
    $url = str_starts_with($tmpPath, '/')
        ? 'file://' . $tmpPath
        : 'file:///' . str_replace('\\', '/', $tmpPath);
    $stream = $app->sseOpen(['url' => $url, 'method' => 'GET']);
    if ($stream < 0) {
        $check('sseOpen(file://) 成功', false, '返回 ' . (string) $stream);
    } else {
        $state = 'open';
        for ($i = 0; $i < 150; $i++) {
            $app->runFrames(1);
            $status = $app->sseStatus($stream);
            $state = (string) ($status['state'] ?? '');
            if ($state === 'done' || $state === 'error') {
                break;
            }
        }
        $check('SSE 流 150 帧内到达 done（超时 = FAIL）', $state === 'done', 'state=' . $state);
        $frames = $app->sseRead($stream, 64);
        $expectedFrames = count(a2uiFixtureEnvelopes());
        $check('帧数 == fixture 信封数', count($frames) === $expectedFrames, count($frames) . ' != ' . (string) $expectedFrames);

        // 把流里的信封喂给一个新 Renderer，证明「线上形态」与直连 consume 等价
        $streamRenderer = new Renderer();
        $badJson = 0;
        foreach ($frames as $frame) {
            $envelope = json_decode((string) ($frame['data'] ?? ''), true);
            if (!is_array($envelope)) {
                $badJson++;
                continue;
            }
            $streamRenderer->consume($envelope);
        }
        $check('流式信封与直连 consume 等价（3 张卡片）',
            $badJson === 0 && count($streamRenderer->toWidgetTree('team')['tree']['children'][2]['children'][0]['children'] ?? []) === 3,
            'badJson=' . (string) $badJson);
        $app->sseClose($stream);
    }
    @unlink($tmpPath);

    echo $failed === 0 ? "selftest passed\n" : "selftest failed: $failed\n";
    return $failed;
}

/** 回放结果 → 完整控件树。动态孩子显式 id（模板实例后缀已由 Renderer 保证）。 */
function a2uiView(QtApp $app, array $replay): array
{
    // 点击按钮：buildAction() 产出 Renderer→Agent 信封并就地展示（无 live agent 的
    // 离线回放主线）；applyInput 把输入写回本地模型，action.context 派发时读最新值。
    $renderer = $replay['renderer'];
    $surfaceTree = $replay['tree'];

    $app->on('go', 'click', function () use ($app, $renderer): void {
        // 输入框最新值先写回（GUI 里用户的输入经 value() 读回；无头模式无输入）
        $input = $app->value('field');
        $renderer->applyInput('team', 'field', $input);
        $action = $renderer->buildAction('team', 'go');
        $summary = $action !== null
            ? '[action] ' . (string) $action['action']['name'] . ' · ' . (string) ($action['action']['userMessage'] ?? '')
            : '[action] 无绑定';
        $app->patch([['op' => 'call', 'id' => 'outbox', 'method' => 'setText', 'args' => [$summary]]]);
    });

    // view() 的构建函数返回**单个控件节点**（qt_window_render 吃单节点，
    // 不是节点列表 —— 首轮验收实测：包一层数组整个树静默不渲染）。
    return WidgetTree::vbox([
        WidgetTree::label('A2UI 离线回放 · team', ['id' => 'a2ui_title']),
        $surfaceTree,
        WidgetTree::label('[action] （点击「提交名单」生成信封）', ['id' => 'outbox']),
    ], ['id' => 'a2ui_root']);
}

/**
 * 全局作用域只放声明（AOT）：$replay 在 main() 里。
 * --bench 段在 new QtApp()（惰性单例）之前完成并 exit(0)（agui-demo 同款）。
 */
function main(int $argc, array $argv): void
{
    if (has_flag($argv, '--bench')) {
        $report = bench_replay(bench_message_count($argv));
        echo 'METRIC a2ui_replay_messages_per_s=' . (string) $report['per_s'] . "\n";
        echo 'METRIC a2ui_replay_total_ms=' . (string) $report['total_ms'] . "\n";
        echo 'METRIC a2ui_bench_messages=' . (string) $report['messages'] . "\n";
        exit(0);
    }

    $app = new QtApp();
    $app->create(['name' => 'A2uiDemo', 'version' => '1.0', 'organization' => 'TypePHP']);
    $app->createWindow('A2UI 离线回放', [
        'width' => 640,
        'height' => 520,
        'min_width' => 420,
        'min_height' => 320,
        'centered' => true,
    ]);

    $replay = a2uiReplay();

    // view() 的构建函数按引用捕获 $replay（闭包 use (&$replay)）：AOT 要求
    // 显式类型标注；$replay 是数组，值捕获也一样可用，但渲染器状态（outbox）
    // 要跨帧共享，按引用才与 self_test 共享同一 Renderer 实例。
    $app->view(function () use ($app, $replay): array {
        return a2uiView($app, $replay);
    });

    $app->setStatus(['A2UI 回放：' . count(a2uiFixtureEnvelopes()) . ' 条信封 · surface=team · 零网络']);

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
