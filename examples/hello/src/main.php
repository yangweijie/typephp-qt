<?php

declare(strict_types=1);

use TypePHP\Qt\QtApp;
use TypePHP\Qt\WidgetTree;

/**
 * Hello — TypePHP\Qt 最小示例。
 *
 * 状态驱动的声明式 UI：事件处理器只改 $state，
 * view() 注册的构建函数每帧读到新状态自动重渲染。
 *
 * 注意：AOT 要求全局作用域只有声明，所以 $state 定义在 main() 里，
 * 闭包用 use (&$state) 按引用捕获。
 */
function main(int $argc, array $argv): void
{
    /** @var array<string, mixed> $state 应用状态：事件改它，视图读它。 */
    $state = [
        'name' => 'World',
        'greeting' => 'Hello, World!',
        'clicks' => 0,
        'dark' => false,
        'progress' => 0,
        'ticks' => 0,
        'tray' => 0,
        'log' => null,
    ];

    $app = new QtApp();
    $app->create(['name' => 'HelloApp', 'version' => '1.0', 'organization' => 'TypePHP']);
    $app->createWindow('Hello TypePHP-Qt', [
        'width' => 760,
        'height' => 720,
        'min_width' => 520,
        'min_height' => 380,
        'centered' => true,
    ]);

    $app->setMenu([
        ['type' => 'menu', 'text' => '文件', 'children' => [
            ['type' => 'item', 'id' => 'menu.quit', 'text' => '退出', 'shortcut' => 'Ctrl+Q'],
        ]],
        ['type' => 'menu', 'text' => '帮助', 'children' => [
            ['type' => 'item', 'id' => 'menu.about', 'text' => '关于'],
        ]],
    ]);
    $app->setStatus(['就绪']);

    // 系统托盘：不传 icon 时桥接会兜底用窗口图标/标准图标 —— macOS、Linux 上
    // **没有图标的托盘项根本不显示**，传不传都得能看见、能点。
    $app->setTray([
        'icon' => 'assets/icon.png',
        'tooltip' => 'Hello TypePHP-Qt · 左键点一下',
        'visible' => true,
        'menu' => [
            ['type' => 'item', 'id' => 'tray.show', 'text' => '显示主窗口'],
            ['type' => 'item', 'id' => 'tray.hello', 'text' => '打个招呼'],
            ['type' => 'separator'],
            ['type' => 'item', 'id' => 'tray.quit', 'text' => '退出'],
        ],
    ]);

    // ── 视图：每帧按 $state 重新描述界面 ──
    // 控件状态（输入光标、表格选中、滚动位置）由 C++ 侧的 id diff 保留。
    $app->view(function () use (&$state, $app): array {
        $greetingStyle = 'font-size:22px;font-weight:bold;'
            . ($state['dark'] ? 'color:#0ea5e9;' : 'color:#1d4ed8;');

        return WidgetTree::vbox([
            WidgetTree::label((string) $state['greeting'], [
                'id' => 'greeting',
                'style' => $greetingStyle,
            ]),

            WidgetTree::hbox([
                WidgetTree::lineEdit((string) $state['name'], [
                    'id' => 'name_input',
                    'placeholder' => '输入名字',
                    'clear_button' => true,
                ]),
                WidgetTree::button('打招呼', ['id' => 'greet_btn']),
            ]),

            WidgetTree::separator(),

            WidgetTree::group('设置', [
                WidgetTree::checkbox('深色文字', (bool) $state['dark'], ['id' => 'dark_toggle']),
                WidgetTree::hbox([
                    WidgetTree::label('主题：'),
                    WidgetTree::combo(
                        ['浅色', '深色', '跟随系统'],
                        $state['dark'] ? '深色' : '浅色',
                        ['id' => 'theme_combo']
                    ),
                ]),
            ]),

            WidgetTree::group('进度', [
                WidgetTree::progress((int) $state['progress'], ['id' => 'progress_bar', 'max' => 100]),
                WidgetTree::hbox([
                    WidgetTree::button('推进', ['id' => 'step_btn']),
                    WidgetTree::button('重置', ['id' => 'reset_btn']),
            WidgetTree::spacer(1),
                    WidgetTree::label('点击次数：' . $state['clicks'], ['id' => 'click_count']),
                ]),
            ]),

            WidgetTree::group('实时', [
                WidgetTree::hbox([
                    WidgetTree::label(
                        '心跳 ' . $state['ticks'] . ' 跳 · 托盘点击 ' . $state['tray'] . ' 次',
                        ['id' => 'live_count']
                    ),
                    WidgetTree::button('打开日志窗口', ['id' => 'open_log_btn']),
                    WidgetTree::spacer(1),
                ]),
            ]),

            WidgetTree::spacer(1),

            WidgetTree::hbox([
                WidgetTree::button('复制问候语', ['id' => 'copy_btn']),
                WidgetTree::button('弹出消息框', ['id' => 'msg_btn']),
                WidgetTree::spacer(1),
                WidgetTree::link('TypePHP 文档', 'https://github.com/swoole/typephp', ['id' => 'doc_link']),
            ]),

            WidgetTree::group('WebView（backend=' . $app->webViewBackend()
                . '，js=' . ($app->webViewSupportsJs() ? '支持' : '不支持') . '）', [
                WidgetTree::webView('', [
                    'id' => 'wv',
                    'html' => '<h1 style="color:#1d4ed8">WebView OK</h1>'
                              . '<p>由 <b>' . $app->webViewBackend() . '</b> 后端渲染</p>'
                              . '<p>加粗 · 斜体 · 中文</p>',
                    'grow' => 1,
                ]),
            ], ['grow' => 1]),
        ]);
    });

    // ── 事件：只改状态，不改界面 ──

    $app->on('greet_btn', 'click', function () use ($app, &$state) {
        $state['name'] = $app->text('name_input');
        $state['greeting'] = 'Hello, ' . ($state['name'] === '' ? 'World' : $state['name']) . '!';
        $state['clicks'] = (int) $state['clicks'] + 1;
        $app->setStatus(['已打招呼 · 第 ' . $state['clicks'] . ' 次']);
    });

    $app->on('name_input', 'submit', function (array $event) use ($app, &$state) {
        $state['name'] = (string) ($event['value'] ?? '');
        $state['greeting'] = 'Hello, ' . ($state['name'] === '' ? 'World' : $state['name']) . '!';
        $app->setStatus(['按回车提交']);
    });

    $app->on('dark_toggle', 'toggle', function (array $event) use (&$state) {
        $state['dark'] = ((string) ($event['value'] ?? '0')) === '1';
    });

    $app->on('theme_combo', 'change', function (array $event) use (&$state) {
        $state['dark'] = ((string) ($event['value'] ?? '')) === '深色';
    });

    $app->on('step_btn', 'click', function () use (&$state) {
        $state['progress'] = min(100, (int) $state['progress'] + 10);
    });

    $app->on('reset_btn', 'click', function () use (&$state) {
        $state['progress'] = 0;
    });

    $app->on('copy_btn', 'click', function () use ($app, &$state) {
        $app->clipboardWrite((string) $state['greeting']);
        $app->notify('已复制', (string) $state['greeting']);
    });

    $app->on('msg_btn', 'click', function () use ($app, &$state) {
        $app->alert('当前问候语：' . $state['greeting'], '消息');
    });

    $app->on('doc_link', 'click', function (array $event) use ($app) {
        $href = (string) ($event['value'] ?? '');
        $app->clipboardWrite($href);
        $app->notify('链接已复制', $href);
    });

    $app->on('menu.quit', 'menu', function () use ($app) {
        $app->close();
    });

    $app->on('menu.about', 'menu', function () use ($app) {
        $app->alert("TypePHP\\Qt 示例应用\n构建于 TypePHP AOT + Qt 6", '关于');
    });

    // ── 托盘 / 定时器 / 副窗口 ──

    // 托盘事件不带 id（点的是托盘项本身），所以只能挂 onAny。
    // 托盘激活：$event['value'] 是激活方式（left / right / double / middle）。
    // 注意绑了 'menu' 之后，右击由 Qt 弹菜单，不再发 right 事件。
    $app->onAny('tray', function (array $event) use ($app, &$state) {
        $state['tray'] = (int) $state['tray'] + 1;
        $kind = (string) ($event['value'] ?? '');
        $app->setStatus(['托盘 ' . $kind . ' × ' . $state['tray']]);
        log_append($state['log'], 'tray ' . $kind);
    });

    // 托盘右键菜单项：和菜单栏一样走 menu 事件，id 用 'tray.' 前缀区分。
    $app->on('tray.hello', 'menu', function () use ($app, &$state) {
        $state['greeting'] = 'Hello, World!';
        $app->setStatus(['托盘菜单：已重置问候语']);
    });

    $app->on('tray.quit', 'menu', function () use ($app) {
        $app->close();
    });

    $app->on('clock', 'timer', function () use ($app, &$state) {
        $state['ticks'] = (int) $state['ticks'] + 1;
        $app->setStatus(['心跳 ' . $state['ticks'] . ' 跳']);
        log_append($state['log'], 'tick 第 ' . $state['ticks'] . ' 跳');
    });

    // 副窗口就是第二个 QtApp 实例：qt_app_create 幂等，QApplication 全程只有一个。
    $app->on('open_log_btn', 'click', function () use (&$state) {
        if ($state['log'] instanceof QtApp) {
            return;  // 已打开就不重复建
        }
        $log = new QtApp();
        $log->createWindow('运行日志', ['width' => 560, 'height' => 300]);
        $log->render(WidgetTree::vbox([
            WidgetTree::table(['时间', '事件'], [], ['id' => 'log_tbl', 'stretch_last' => true]),
            WidgetTree::hbox([
                WidgetTree::button('关闭日志', ['id' => 'close_log_btn']),
                WidgetTree::spacer(1),
            ]),
        ]));
        $log->on('close_log_btn', 'click', function () use (&$state) {
            if ($state['log'] instanceof QtApp) {
                $state['log']->close();
            }
        });
        $state['log'] = $log;
        // 先泵一帧让控件建出来：patch 找的是已存在的控件，否则第一条日志会被丢掉。
        $log->runFrames(1);
        log_append($log, '日志窗口已打开');
    });

    // 无头验收：--shot <path> 渲染几帧后存 PNG 退出。
    $shot = shot_path($argv);
    if ($shot !== '') {
        // 3 帧就够：瞬态动画由 snapshot() 推到终点（见 cpp-src/qt_bridge.cc），
        // 不靠多泵帧等它跑完，所以出图不再取决于墙钟相位。
        $app->runFrames(3);
        $ok = $app->snapshot($shot);
        $app->destroy();
        if (!$ok) {
            echo "snapshot failed: $shot\n";
            exit(1);
        }
        return;
    }

    // 注册点在 --shot 分支之后：心跳文案会随墙钟跳字，截图基线要求画面不随之变化。
    // 自检分支用 dispatch() 手动触发 timer，不依赖这里的注册。
    $app->setTimer('clock', 1000);

    // 无头自检：--selftest 逐个触发所有控件事件，验证每个 handler 都能正常调用。
    // 这能在无头环境覆盖"闭包参数个数不匹配"这类只在 AOT 下暴露的问题。
    if (has_flag($argv, '--selftest')) {
        $failed = self_test($app);
        // close_log_btn 只是关窗，实例还在 $state 里 —— 自检末尾补一次 destroy。
        if ($state['log'] instanceof QtApp) {
            $state['log']->destroy();
        }
        $app->destroy();
        if ($failed > 0) {
            exit(1);
        }
        return;
    }

    // 无头验收：--difftest 断言表格/树的差异更新边界（选中、行 id、列数变化）。
    // 只有真 Qt 才谈得上「diff 边界」，所以这块不进 PHPUnit，走 AOT 二进制。
    if (has_flag($argv, '--difftest')) {
        $failed = diff_test($app);
        $app->destroy();
        if ($failed > 0) {
            exit(1);
        }
        return;
    }

    // GUI：每个 QtApp 只泵自己那个窗口，所以多窗口要自己按帧轮流泵。
    // 副窗口被关掉（点按钮或标题栏 ×）就地销毁并从状态里摘掉，主窗口关闭时循环结束。
    while ($app->isOpen()) {
        $app->runFrames(1);

        $log = $state['log'];
        if ($log instanceof QtApp) {
            if ($log->isOpen()) {
                $log->runFrames(1);
            } else {
                $log->destroy();
                $state['log'] = null;
            }
        }
    }
    $app->destroy();
}

/**
 * 往日志副窗口追加一行：走 patch 的 call/appendRows 热路径。
 *
 * 不整树重渲染是因为 —— 命令式追加之后签名已作废，下一次 render 会以树为准
 * 重建、把追加的行冲掉。日志这种「只增不改」的场景就该用 appendRows。
 */
function log_append(mixed $log, string $text): void
{
    if (!$log instanceof QtApp) {
        return;
    }
    $log->patch([[
        'op' => 'call',
        'id' => 'log_tbl',
        'method' => 'appendRows',
        'args' => [[[date('H:i:s'), $text]]],
    ]]);
}

/** 组一个表格节点：$rows 是「每行一组单元格」，$rowIds 与行一一对应（空则不传）。 */
function diff_table_node(array $columns, array $rows, array $rowIds, array $props = []): array
{
    $node = WidgetTree::table($columns, $rows, array_merge(['id' => 'tbl'], $props));
    if ($rowIds !== []) {
        $node['row_ids'] = $rowIds;
    }
    return $node;
}

/** 组一个树节点。 */
function diff_tree_node(array $nodes, array $props = []): array
{
    return WidgetTree::tree($nodes, array_merge(['id' => 'tr'], $props));
}

/**
 * 表格/树 diff 边界验收。
 *
 * 用独立窗口：主窗口的 view() 每帧重画整棵树，表格不在那棵树里会被 diff 直接销毁。
 */
function diff_test(QtApp $app): int
{
    $app->headless(true);

    $w = new QtApp();
    $w->create(['name' => 'difftest']);
    $w->createWindow('Diff Test', ['width' => 720, 'height' => 520]);

    $failed = 0;

    // 断言一条：失败时把实际值打出来（表格值是个数组）。
    $check = function (string $label, bool $ok, string $got) use (&$failed): void {
        if ($ok) {
            echo "ok   {$label}\n";
            return;
        }
        echo "FAIL {$label} -> {$got}\n";
        $failed++;
    };

    // 渲染一帧并回读表格选中。
    $frameTable = function (array $node) use ($w): array {
        $w->render(WidgetTree::vbox([$node]));
        $w->run(1);
        $value = $w->value('tbl');
        return is_array($value) ? $value : [];
    };

    $columns = ['名称', '数量', '备注'];
    $rows = [['苹果', '1', ''], ['香蕉', '2', ''], ['橙子', '3', '']];
    $ids = ['r1', 'r2', 'r3'];

    // 1. 首次渲染带 current：声明式选中必须命中 row_ids 里的 id。
    $got = $frameTable(diff_table_node($columns, $rows, $ids, ['current' => 'r2']));
    $check('table current=r2 首次生效', ($got['value'] ?? '') === 'r2', json_encode($got, JSON_UNESCAPED_UNICODE));

    // 2. 同一棵树原样再渲染：选中是控件状态，diff 不该把它抹掉。
    $got = $frameTable(diff_table_node($columns, $rows, $ids));
    $check('table 重渲染保留选中', ($got['value'] ?? '') === 'r2', json_encode($got, JSON_UNESCAPED_UNICODE));

    // 3. 头部插一行：选中要跟着 **行 id** 走，而不是跟着索引走。
    $grown = array_merge([['新行', '0', '']], $rows);
    $got = $frameTable(diff_table_node($columns, $grown, array_merge(['r0'], $ids)));
    $check('table 插行后选中跟随行 id', ($got['value'] ?? '') === 'r2', json_encode($got, JSON_UNESCAPED_UNICODE));

    // 4. 列数变化（3 → 4）：行数与选中都不该被这次重排弄丢。
    $wide = [];
    foreach ($rows as $row) {
        $wide[] = array_merge($row, ['ok']);
    }
    $got = $frameTable(diff_table_node(['名称', '数量', '备注', '状态'], $wide, $ids, ['current' => 'r2']));
    $check('table 列数变化后仍命中行 id', ($got['value'] ?? '') === 'r2', json_encode($got, JSON_UNESCAPED_UNICODE));

    // 5. 没有 row_ids 的边界：行 id 退化成索引，删掉一行后选中只能按索引找回，且不得越界。
    $fewer = [['香蕉', '2', ''], ['橙子', '3', '']];
    $got = $frameTable(diff_table_node($columns, $fewer, []));
    $row = (int) ($got['row'] ?? -1);
    $check('table 无 row_ids 时选中不越界', $row >= -1 && $row < count($fewer), json_encode($got, JSON_UNESCAPED_UNICODE));

    // 回到三行并声明选中，给下面两条补丁用例一个已知起点。
    $read = function () use ($w): array {
        $value = $w->value('tbl');
        return is_array($value) ? $value : [];
    };
    $got = $frameTable(diff_table_node($columns, $rows, $ids, ['current' => 'r2']));

    // 6. 补丁只碰非结构属性时，行内容必须原样留着（早先这里是无条件 setRowCount(0)）。
    $w->patch([['op' => 'set', 'id' => 'tbl', 'props' => ['enabled' => false]]]);
    $got = $read();
    $check('table 补丁改非结构属性不清空行', ($got['value'] ?? '') === 'r2' && (int) ($got['row'] ?? -1) === 1, json_encode($got, JSON_UNESCAPED_UNICODE));

    // 7. 补丁换数据（rows 在 props 里，不在 op 顶层）：重建后按行 id 找回选中。
    $w->patch([['op' => 'set', 'id' => 'tbl', 'props' => [
        'rows' => [['乙', '2', ''], ['甲', '1', '']],
        'row_ids' => ['r2', 'r1'],
    ]]]);
    $got = $read();
    $check('table 补丁换 rows 后按行 id 保留选中', ($got['value'] ?? '') === 'r2' && (int) ($got['row'] ?? -1) === 0, json_encode($got, JSON_UNESCAPED_UNICODE));

    // 8. 树：current 按节点 id 命中。
    $nodes = [
        ['id' => 'n1', 'text' => '第一组', 'expanded' => true, 'children' => [
            ['id' => 'n1a', 'text' => '子项 A'],
            ['id' => 'n1b', 'text' => '子项 B'],
        ]],
        ['id' => 'n2', 'text' => '第二组'],
    ];
    $w->render(WidgetTree::vbox([diff_tree_node($nodes, ['current' => 'n2'])]));
    $w->run(1);
    $gotTree = (string) $w->value('tr');
    $check('tree current=n2 首次生效', $gotTree === 'n2', var_export($gotTree, true));

    // 9. 树重渲染保留选中（clear() 会把 currentItem 抹掉，属同一类缺陷）。
    $w->render(WidgetTree::vbox([diff_tree_node($nodes)]));
    $w->run(1);
    $gotTree = (string) $w->value('tr');
    $check('tree 重渲染保留选中', $gotTree === 'n2', var_export($gotTree, true));

    // 10. 树结构变化（给 n2 加子节点）后仍按 id 找回选中。
    $nodes2 = $nodes;
    $nodes2[1]['children'] = [['id' => 'n2a', 'text' => '子项 C']];
    $w->render(WidgetTree::vbox([diff_tree_node($nodes2)]));
    $w->run(1);
    $gotTree = (string) $w->value('tr');
    $check('tree 结构变化后选中跟随节点 id', $gotTree === 'n2', var_export($gotTree, true));

    // 11. 树的补丁只碰非结构属性时不得 clear() —— clear 会把节点、展开态、选中一起抹掉。
    $w->patch([['op' => 'set', 'id' => 'tr', 'props' => ['enabled' => false]]]);
    $gotTree = (string) $w->value('tr');
    $check('tree 补丁改非结构属性不清空节点', $gotTree === 'n2', var_export($gotTree, true));

    // 12. 树的补丁换 nodes：重建后仍按节点 id 找回选中。
    $w->patch([['op' => 'set', 'id' => 'tr', 'props' => ['nodes' => [
        ['id' => 'n2', 'text' => '第二组'],
        ['id' => 'n1', 'text' => '第一组'],
    ]]]]);
    $gotTree = (string) $w->value('tr');
    $check('tree 补丁换 nodes 后保留选中', $gotTree === 'n2', var_export($gotTree, true));

    // 13. call appendRows：只往尾部加行，已有行和选中都不该被动到。
    $w->render(WidgetTree::vbox([diff_table_node($columns, $rows, $ids, ['current' => 'r2'])]));
    $w->run(1);
    $w->patch([['op' => 'call', 'id' => 'tbl', 'method' => 'appendRows',
        'args' => [[['苹果汁', '4', ''], ['橙汁', '5', '']], ['r4', 'r5']]]]);
    $got = $read();
    $check('table call appendRows 不动已有行与选中',
        ($got['value'] ?? '') === 'r2' && (int) ($got['row'] ?? -1) === 1, json_encode($got, JSON_UNESCAPED_UNICODE));

    // 14. 追加行的行 id 必须真的写进 UserRole，否则按 id 选不中它。
    $w->patch([['op' => 'call', 'id' => 'tbl', 'method' => 'select', 'args' => ['r5']]]);
    $got = $read();
    $check('table call select 命中追加行的 id',
        ($got['value'] ?? '') === 'r5' && (int) ($got['row'] ?? -1) === 4, json_encode($got, JSON_UNESCAPED_UNICODE));

    // 15. call clear：整表清空，取值退化到「无选中」。
    $w->patch([['op' => 'call', 'id' => 'tbl', 'method' => 'clear']]);
    $got = $read();
    $check('table call clear 清空行',
        ($got['value'] ?? '') === '' && (int) ($got['row'] ?? -1) === -1, json_encode($got, JSON_UNESCAPED_UNICODE));

    // 16. 清空后原样重渲染必须把行建回来：appendRows/clear 作废了结构签名，
    // diff 才会重建。没作废的话这里会是一张永久空表 —— 命令式改过的控件最常见的踩坑。
    $w->render(WidgetTree::vbox([diff_table_node($columns, $rows, $ids)]));
    $w->run(1);
    $w->patch([['op' => 'call', 'id' => 'tbl', 'method' => 'select', 'args' => ['r3']]]);
    $got = $read();
    $check('table 命令式改过后重渲染以树为准',
        ($got['value'] ?? '') === 'r3' && (int) ($got['row'] ?? -1) === 2, json_encode($got, JSON_UNESCAPED_UNICODE));

    // 17. 文本/数值控件上的命令式调用。lineedit 的 setValue 走文本，progress 的走数值。
    $w->render(WidgetTree::vbox([
        WidgetTree::label('起点', ['id' => 'lb']),
        WidgetTree::lineEdit('old', ['id' => 'in']),
        WidgetTree::progress(10, ['id' => 'pg']),
    ]));
    $w->run(1);
    $w->patch([
        ['op' => 'call', 'id' => 'lb', 'method' => 'setText', 'args' => ['新标题']],
        ['op' => 'call', 'id' => 'in', 'method' => 'setValue', 'args' => ['新值']],
        ['op' => 'call', 'id' => 'pg', 'method' => 'setValue', 'args' => [66]],
        ['op' => 'call', 'id' => 'in', 'method' => 'focus'],
    ]);
    $check('label call setText', $w->text('lb') === '新标题', var_export($w->text('lb'), true));
    $check('lineedit call setValue 按文本', $w->text('in') === '新值', var_export($w->text('in'), true));
    $check('progress call setValue 按数值', (int) $w->value('pg') === 66, var_export($w->value('pg'), true));
    $check('call focus 不改控件值', $w->text('in') === '新值', var_export($w->text('in'), true));

    $w->destroy();
    echo $failed === 0 ? "difftest passed\n" : "difftest failed: $failed\n";

    return $failed;
}


/**
 * 逐个触发所有已注册控件的事件，验证 handler 可调用。
 *
 * AOT 编译后的闭包对实参个数做精确校验，参数个数不匹配时
 * 只有在真正触发事件时才会暴露 —— 所以这个自检必须实际分发事件。
 */
function self_test(QtApp $app): int
{
    // 自检必须无头：否则 msg_btn / menu.about 会弹模态框，
    // 在无终端环境下永久阻塞在 exec()。
    $app->headless(true);

    $cases = [
        ['click', 'greet_btn'],
        ['submit', 'name_input', 'Ada'],
        ['toggle', 'dark_toggle', '1'],
        ['change', 'theme_combo', '深色'],
        ['click', 'step_btn'],
        ['click', 'reset_btn'],
        ['click', 'copy_btn'],
        ['click', 'msg_btn'],
        ['click', 'doc_link', 'https://example.com'],
        ['menu', 'menu.about'],
        ['click', 'open_log_btn'],
        ['timer', 'clock'],
        ['tray', '', 'left'],
        ['tray', '', 'double'],
        ['menu', 'tray.hello'],
        ['menu', 'tray.show'],
        // 桥接新增的控件信号（press/release/commit/cell/expand/collapse/close/itemClick）
        ['press', 'greet_btn'],
        ['release', 'greet_btn'],
        ['commit', 'name_input', 'Ada'],
        ['cell', 'log_tbl', 'x'],
        ['expand', 'file_tree', 'src'],
        ['collapse', 'file_tree', 'src'],
        ['close', 'main_tabs', '0'],
        ['itemClick', 'task_list', 'r1'],
        ['click', 'close_log_btn'],
    ];

    $app->run(1);

    $failed = 0;
    foreach ($cases as $case) {
        $event = ['type' => $case[0], 'id' => $case[1]];
        if (isset($case[2])) {
            $event['value'] = $case[2];
        }

        $app->dispatch($event);

        if ($app->lastError() !== '') {
            echo "FAIL {$case[0]} {$case[1]}: ", $app->lastError(), "\n";
            $failed++;
        } else {
            echo "ok   {$case[0]} {$case[1]}\n";
        }
    }

    // menu.quit 放最后：它会关窗，影响后续分发
    $app->dispatch(['type' => 'menu', 'id' => 'menu.quit']);

    echo $failed === 0 ? "selftest passed\n" : "selftest failed: $failed\n";

    return $failed;
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

/** 解析 `--shot <path>` 参数；没有该参数返回空串。 */
function shot_path(array $argv): string
{
    $count = count($argv);
    for ($i = 1; $i < $count; $i++) {
        if ($argv[$i] === '--shot' && $i + 1 < $count) {
            return (string) $argv[$i + 1];
        }
    }
    return '';
}
