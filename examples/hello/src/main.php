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
    ];

    $app = new QtApp();
    $app->create(['name' => 'HelloApp', 'version' => '1.0', 'organization' => 'TypePHP']);
    $app->createWindow('Hello TypePHP-Qt', [
        'width' => 760,
        'height' => 560,
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

    // ── 视图：每帧按 $state 重新描述界面 ──
    // 控件状态（输入光标、表格选中、滚动位置）由 C++ 侧的 id diff 保留。
    $app->view(function () use (&$state): array {
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

            WidgetTree::spacer(1),

            WidgetTree::hbox([
                WidgetTree::button('复制问候语', ['id' => 'copy_btn']),
                WidgetTree::button('弹出消息框', ['id' => 'msg_btn']),
                WidgetTree::spacer(1),
                WidgetTree::link('TypePHP 文档', 'https://github.com/swoole/typephp', ['id' => 'doc_link']),
            ]),
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

    // 无头验收：--shot <path> 渲染几帧后存 PNG 退出。
    $shot = shot_path($argv);
    if ($shot !== '') {
        $app->run(2);
        $ok = $app->snapshot($shot);
        $app->destroy();
        return;
    }

    // 无头自检：--selftest 逐个触发所有控件事件，验证每个 handler 都能正常调用。
    // 这能在无头环境覆盖"闭包参数个数不匹配"这类只在 AOT 下暴露的问题。
    if (has_flag($argv, '--selftest')) {
        self_test($app);
        $app->destroy();
        return;
    }

    $app->run();
}

/**
 * 逐个触发所有已注册控件的事件，验证 handler 可调用。
 *
 * AOT 编译后的闭包对实参个数做精确校验，参数个数不匹配时
 * 只有在真正触发事件时才会暴露 —— 所以这个自检必须实际分发事件。
 */
function self_test(QtApp $app): void
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
