<?php

declare(strict_types=1);

use TypePHP\Qt\QtApp;
use TypePHP\Qt\WidgetTree;

/**
 * QmlClock — qml 控件（QQuickWidget）最小示例。
 *
 * 与 examples/hello 的区别：
 *  1. 界面上嵌一块 QML（assets/clock.qml）——QML 内部结构由 .qml 自己组织，
 *     PHP 树里只给叶子节点（叶子语义：children 被忽略）。
 *  2. 无头验收**不靠像素**：QQuickItem 的内容不进 QWidget::grab()（F29/F27），
 *     所以 --selftest 的判据走「事件 + 读回」——qt_qml_available()、tick 事件到达、
 *     qmlStatus()、value('clock') 的属性读回。
 *  3. qmlStatus() 的 errors 进 rc 判据（必须为空），status 字段只打印不进
 *     判据：offscreen 平台下组件 status 可能停在 loading（statusChanged(Ready)
 *     不触发是平台行为，F51 §51.7 探针实测），断言 ready 会让无头 selftest
 *     稳定失败。
 *
 * 注意：AOT 要求全局作用域只有声明，所以 $state 定义在 main() 里，
 * 闭包用 use (&$state) 按引用捕获。
 */
function main(int $argc, array $argv): void
{
    /** @var array<string, mixed> $state 应用状态：事件改它，视图读它。 */
    $state = [
        'ticks' => 0,
        'last_time' => '',
        'speed' => 1,
    ];

    $app = new QtApp();
    $app->create(['name' => 'QmlClock', 'version' => '1.0', 'organization' => 'TypePHP']);
    $app->createWindow('QmlClock', [
        'width' => 420,
        'height' => 360,
        'min_width' => 320,
        'min_height' => 240,
        'centered' => true,
    ]);
    $app->setStatus(['就绪']);

    // QML 不可用的构建（没定义 QT_QML）退化成占位标签：走降级分支，不让白屏。
    if (!$app->qmlAvailable()) {
        $app->setStatus(['QML 未启用（构建时未定义 QT_QML）']);
    }

    // ── 视图：qml 节点是叶子，四把键 source / signals / props / read ──
    // source 相对 exe 目录解析（qtResolvePath，build 时 assets/ 已拷进 build/assets/）；
    // signals 按名连进事件队列（type='qml'、value=信号名、payload=read 快照）；
    // props 写根对象的动态属性；read 声明「读回哪些属性」，事件 payload 与 value() 都用它。
    $app->view(function () use (&$state, $app): array {
        return WidgetTree::vbox([
            WidgetTree::label('QML 时钟（QQuickWidget 嵌入）', ['id' => 'title']),
            WidgetTree::qml('assets/clock.qml', [
                    'id' => 'clock',
                    'signals' => ['tick'],
                    // speed 写 QML 根的动态属性（setProperty 通道）；
                    // sizeFactor 是 QML 里声明的属性，props 写入后经 value() 读回演示。
                    'props' => ['speed' => (int) $state['speed'], 'sizeFactor' => 1.25],
                    'read' => ['timeText', 'speed', 'sizeFactor'],
                    'grow' => 1,
                    'size' => [280, 120],
                ]),
            WidgetTree::hbox([
                WidgetTree::label('收到 tick：' . (int) $state['ticks'], ['id' => 'tick_count']),
                // payload.timeText 的展示位：tick 事件把 QML 侧时间快照写进状态，
                // 视图下一帧渲染出来 —— selftest 经 value('last_time') 断言。
                WidgetTree::label('上次：' . (string) $state['last_time'], ['id' => 'last_time']),
                WidgetTree::spacer(1),
            ]),
            WidgetTree::hbox([
                WidgetTree::button('加速', ['id' => 'faster_btn']),
                WidgetTree::button('重置', ['id' => 'reset_btn']),
                WidgetTree::spacer(1),
            ]),
        ]);
    });

    // ── 事件：qml 信号与按钮只改状态，视图下一帧按新状态重描述 ──

    // tick 事件：value='tick'，payload 是 read 声明属性（timeText/speed）的当前快照。
    $app->on('clock', 'qml', function (array $event) use ($app, &$state) {
        $state['ticks'] = (int) $state['ticks'] + 1;
        $state['last_time'] = (string) (($event['payload']['timeText'] ?? '') ?? '');
        $app->setStatus([
            'tick #' . $state['ticks'] . ' · ' . $state['last_time'],
        ]);
    });

    $app->on('faster_btn', 'click', function () use (&$state) {
        $state['speed'] = min(8, (int) $state['speed'] + 1);
    });

    $app->on('reset_btn', 'click', function () use (&$state) {
        $state['speed'] = 1;
    });

    // 无头自检：--selftest 走读回断言（不靠像素，F51 D7）。
    if (has_flag($argv, '--selftest')) {
        $failed = self_test($app, $state);
        $app->destroy();
        if ($failed > 0) {
            exit(1);
        }
        return;
    }

    // GUI 主循环。
    while ($app->isOpen()) {
        $app->runFrames(1);
    }
    $app->destroy();
}

/**
 * 无头自检：判据全部走「读回」，因为 QQuickItem 的内容不进 QWidget::grab()。
 *
 *  1. qmlAvailable() —— 本示例的 yml 定义了 QT_QML，必须为 true；
 *  2. 事件到达 —— 真实 Qt 里 QML 的 Timer 每秒触发一次 tick，泵 ~2.4s 后
 *     断言 tick_count / last_time 两个标签的文本已离开初值（handler 写状态
 *     → 视图重渲染的完整链路；经控件树读回，不走 $state 引用参数，F53）；
 *  3. 属性读回 —— QML 根对象在 tick 时写 timeText；加速按钮改 speed 后经
 *     props 写回，value('clock') 的快照里 speed 要能读到；
 *  4. qmlStatus() —— errors 必须为**空**（connect 失败会记 error，非空即
 *     回归）；status 字段只打印记录、**不进判据**（offscreen 下 status 可能
 *     停在 loading，平台行为不是缺陷，F51 §51.7）。
 *
 * 失败一条即 rc=1（判据走 rc，F34 先例）。
 */
function self_test(QtApp $app, array &$state): int
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

    // 1. 能力查询：本示例 yml 定义了 QT_QML。
    $check('qmlAvailable() 为 true（yml 定义了 QT_QML）', $app->qmlAvailable(), var_export($app->qmlAvailable(), true));

    // 先泵一帧把 qml 控件建出来（rootObject 在 setSource 同帧落地，信号随即接线）。
    $app->runFrames(1);

    // 2. 属性读回：读回值 = read 声明属性的当前快照。view() 每帧写
    //    props=['speed'=>…,'sizeFactor'=>1.25]，两个键都要读到 **PHP 写入值**
    //    （1 和 1.25）——props 走 setProperty 通道真正落到了 QML 根上。
    //    早先这里断言 sizeFactor===1.0，把「props 首帧静默丢失」固化成了判据
    //    （1.0 是 QML 默认值，1.25 才是树里写的），F53 §3 修正后改断言 1.25。
    //    先泵 ~2.4s（150 帧 × 16ms）让 QML root 落地 + Timer 跑起来（1s 一拍 tick）。
    $app->runFrames(150);
    $snapshot = $app->value('clock');
    $speed = is_array($snapshot) ? ($snapshot['speed'] ?? null) : null;
    $sizeFactor = is_array($snapshot) ? ($snapshot['sizeFactor'] ?? null) : null;
    $check('value() 读回 props 写过的 speed', $speed === 1, json_encode($snapshot, JSON_UNESCAPED_UNICODE));
    $check('value() 读回 props 写过的 sizeFactor（PHP 写入值到达 QML 根）', $sizeFactor === 1.25, json_encode($snapshot, JSON_UNESCAPED_UNICODE));

    // 3. 事件到达（原降级判据已随信号中转修复升级回来）。断言走**控件树读回**
    //    （value('tick_count')），不直接读 $state —— AOT 下闭包按引用捕获
    //    （use (&$state)）的写入，穿过「按引用传入的函数参数」不可见（普通
    //    PHP 是同一存储，F53 实测）；控件树读回也正是 GUI 的真实语义：
    //    handler 写状态 → 视图重渲染 → 界面文本变化。
    $tickText = (string) $app->value('tick_count');
    $check('tick 事件到达（tick_count 文本已离开初值）',
        $tickText !== '' && $tickText !== '收到 tick：0', var_export($tickText, true));
    $lastText = (string) $app->value('last_time');
    $check('tick 的 payload 携带 QML 侧 timeText 快照',
        $lastText !== '' && $lastText !== '上次：', var_export($lastText, true));

    // 4. qmlStatus()：errors 必须为**空**（connect 失败会记 error；信号中转
    //    修复后这里非空即回归）。status 字段只打印记录、**不进判据**
    //    （offscreen 下 status 可能停在 loading，平台行为不是缺陷，F51 §51.7）。
    // 5. reload 后信号重连 + props 重放（信号轮新写的 wired_ 代次逻辑的正控制）：
    //    先合成一次「加速」点击把 speed 推到 2（走 dispatch，别在 selftest 里直写
    //    $state——引用参数断链，F53 §2），确认 props 通道把 2 写进 QML 根；
    //    然后 qmlReload 重建 root：tick 必须重连继续到达，speed 必须被重新写回
    //    （props 的 diff 签名若不作废，新 root 会静默回落 QML 默认值 1——F46 同族）。
    $app->dispatch(['type' => 'click', 'id' => 'faster_btn']);
    $app->runFrames(2);
    $snapshot2 = $app->value('clock');
    $speed2 = is_array($snapshot2) ? ($snapshot2['speed'] ?? null) : null;
    $check('reload 前 props 通道生效（speed=2 已写进 QML 根）', $speed2 === 2, json_encode($snapshot2, JSON_UNESCAPED_UNICODE));
    $before = (string) $app->value('tick_count');
    $app->qmlReload('clock');
    $app->runFrames(3);
    $snapshot3 = $app->value('clock');
    $speed3 = is_array($snapshot3) ? ($snapshot3['speed'] ?? null) : null;
    $check('reload 后 props 重放（speed=2 仍在，不回落默认值）', $speed3 === 2, json_encode($snapshot3, JSON_UNESCAPED_UNICODE));
    $app->runFrames(120);   // ~2s：Timer 重新走满 1s 一拍
    $after = (string) $app->value('tick_count');
    $check('reload 后 tick 事件继续到达（信号重连生效）',
        $after !== $before && $after !== '收到 tick：0', var_export([$before, $after], true));

    $status = $app->qmlStatus('clock');
    $errors = is_array($status) ? ($status['errors'] ?? []) : [];
    $check('qmlStatus().errors 为空（无 connect 失败）',
        count($errors) === 0, json_encode($status, JSON_UNESCAPED_UNICODE));
    echo "info qmlStatus(clock): " . json_encode($status, JSON_UNESCAPED_UNICODE) . "\n";

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
