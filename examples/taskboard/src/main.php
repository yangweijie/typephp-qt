<?php

declare(strict_types=1);

use TypePHP\Qt\AppData;
use TypePHP\Qt\JsonStore;
use TypePHP\Qt\QtApp;
use TypePHP\Qt\WidgetTree;

/**
 * Taskboard — TypePHP\Qt 综合示例：任务看板。
 *
 * 覆盖四件事：
 *   1. 声明式控件树 —— 表格用 row_ids 给每行显式 id，条件分支（详情面板、空态提示）
 *      也显式给 id。隐式路径 id 只保证帧内稳定，前面兄弟数量一变就漂移，
 *      控件会被判成「删了又建」，选中与光标随之丢失。
 *   2. 事件 —— click / change / submit / select / activate / menu / timer / tray，
 *      以及 onAny() 通配。
 *   3. 对话框 —— confirm() 确认删除与清空，alert() 关于与空态，error() 校验失败。
 *   4. 持久化 —— AppData 定位用户配置目录，JsonStore 原子落盘，任务与窗口几何
 *      重启后还在。
 *
 * AOT 约束：全局作用域只有声明，$state 定义在 main() 里，闭包用 use (&$state)
 * 按引用捕获。状态变更统一走「纯函数返回新状态」，闭包里 `$state = f(...)` 回写，
 * 避免给辅助函数传引用参数。
 */
function main(int $argc, array $argv): void
{
    // ── 持久化：任务列表与窗口几何都落在用户配置目录 ──
    $data = new AppData('Taskboard', 'TypePHP');
    $store = new JsonStore($data->configPath('tasks.json'));
    $store->load();

    /** @var array<string, mixed> $state 事件改它，视图读它。 */
    $state = [
        'tasks' => taskboard_tasks($store),
        'next_id' => taskboard_next_id($store),
        'filter' => 'all',
        'query' => '',
        'selected' => '',
        'new_title' => '',
        'new_priority' => 'mid',
        'saved_at' => '',
        'clicks' => 0,
    ];

    // 首次运行给几条种子数据，让界面不是空的。
    if ($state['tasks'] === []) {
        $state['tasks'] = [
            ['id' => 't1', 'title' => '阅读 AGENTS.md', 'status' => 'done', 'priority' => 'high'],
            ['id' => 't2', 'title' => '写一个任务看板示例', 'status' => 'doing', 'priority' => 'high'],
            ['id' => 't3', 'title' => '跑通 phpunit 与 qtphp lint', 'status' => 'todo', 'priority' => 'mid'],
        ];
        $state['next_id'] = 4;
    }

    $app = new QtApp();
    $app->create(['name' => 'TaskboardApp', 'version' => '1.0', 'organization' => 'TypePHP']);
    $app->createWindow('任务看板 · TypePHP-Qt', [
        'width' => 880,
        'height' => 640,
        'min_width' => 660,
        'min_height' => 440,
        'centered' => true,
    ]);

    // 恢复上次的窗口几何。x/y 是客户区坐标，可以直接回喂，不用自己减标题栏。
    $geometry = $store->get('geometry');
    if (is_array($geometry)) {
        $app->restoreGeometry($geometry);
    }

    $app->setMenu([
        ['type' => 'menu', 'text' => '文件', 'children' => [
            ['type' => 'item', 'id' => 'menu.new', 'text' => '新建任务', 'shortcut' => 'Ctrl+N'],
            ['type' => 'item', 'id' => 'menu.clear', 'text' => '清空全部'],
            ['type' => 'sep'],
            ['type' => 'item', 'id' => 'menu.quit', 'text' => '退出', 'shortcut' => 'Ctrl+Q'],
        ]],
        ['type' => 'menu', 'text' => '帮助', 'children' => [
            ['type' => 'item', 'id' => 'menu.about', 'text' => '关于'],
        ]],
    ]);
    $app->setStatus(['就绪']);

    // 系统托盘：不传 icon 时桥接会兜底用窗口图标/标准图标 —— macOS、Linux 上
    // 没有图标的托盘项根本不显示，传不传都得能看见、能点。
    $app->setTray([
        'icon' => 'assets/icon.png',
        'tooltip' => '任务看板 · 左键点一下',
        'visible' => true,
        'menu' => [
            ['type' => 'item', 'id' => 'tray.show', 'text' => '显示主窗口'],
            ['type' => 'item', 'id' => 'tray.new', 'text' => '新建任务'],
            ['type' => 'separator'],
            ['type' => 'item', 'id' => 'tray.quit', 'text' => '退出'],
        ],
    ]);

    // ── 视图：每帧按 $state 重新描述界面 ──
    // 控件状态（输入光标、表格选中、滚动位置）由 C++ 侧的 id diff 保留。
    $app->view(function () use (&$state): array {
        $visible = taskboard_visible($state);

        $children = [
            // 新建行：标题输入 + 优先级 + 按钮
            WidgetTree::hbox([
                WidgetTree::lineEdit((string) $state['new_title'], [
                    'id' => 'title_input',
                    'placeholder' => '新任务标题…',
                    'clear_button' => true,
                ]),
                WidgetTree::combo(
                    ['低', '中', '高'],
                    taskboard_priority_label((string) $state['new_priority']),
                    ['id' => 'priority_combo']
                ),
                WidgetTree::button('新建任务', ['id' => 'add_btn']),
                WidgetTree::spacer(1),
            ]),
            // 过滤行：搜索 + 状态过滤 + 统计
            WidgetTree::hbox([
                WidgetTree::lineEdit((string) $state['query'], [
                    'id' => 'query_input',
                    'placeholder' => '搜索标题…',
                    'clear_button' => true,
                ]),
                WidgetTree::combo(
                    ['全部', '待办', '进行中', '完成'],
                    taskboard_filter_label((string) $state['filter']),
                    ['id' => 'filter_combo']
                ),
                WidgetTree::spacer(1),
                WidgetTree::label(taskboard_stats_text($state), ['id' => 'stats_label']),
            ]),
            WidgetTree::separator(),
            // 主表格：row_ids 让选中在插行、过滤、删除后仍按任务 id 命中，
            // 而不是跟着行索引错位。
            WidgetTree::table(
                ['标题', '状态', '优先级'],
                taskboard_rows($visible),
                [
                    'id' => 'task_table',
                    'row_ids' => taskboard_row_ids($visible),
                    'current' => (string) $state['selected'],
                    'stretch_last' => true,
                ]
            ),
        ];

        // 条件分支：有选中任务才挂详情面板。显式 id —— 隐式路径 id 只保证帧内稳定，
        // 前面兄弟数量一变就漂移，面板会被判成「删了又建」。
        if ($state['selected'] !== '') {
            $children[] = taskboard_detail_panel($state);
        }

        // 条件分支：过滤后没有行时给个提示，同样显式 id。
        if ($visible === []) {
            $children[] = WidgetTree::label('没有匹配的任务', ['id' => 'empty_hint']);
        }

        $children[] = WidgetTree::hbox([
            WidgetTree::button('标记完成', ['id' => 'done_btn']),
            WidgetTree::button('取消完成', ['id' => 'undone_btn']),
            WidgetTree::button('删除', ['id' => 'delete_btn']),
            WidgetTree::spacer(1),
            WidgetTree::button('清空全部', ['id' => 'clear_btn']),
        ]);
        $children[] = WidgetTree::hbox([
            WidgetTree::label('自动保存每 2 秒 · 点击 ' . (int) $state['clicks'] . ' 次', ['id' => 'hint_label']),
            WidgetTree::spacer(1),
            WidgetTree::label((string) $state['saved_at'], ['id' => 'saved_label']),
        ]);

        return WidgetTree::vbox($children);
    });

    // ── 事件：只改状态，不改界面 ──

    $app->on('title_input', 'change', function (array $event) use (&$state) {
        $state['new_title'] = (string) ($event['value'] ?? '');
    });

    $app->on('priority_combo', 'change', function (array $event) use (&$state) {
        $state['new_priority'] = taskboard_priority_key((string) ($event['value'] ?? '中'));
    });

    $app->on('add_btn', 'click', function () use ($app, &$state, $store) {
        $state = taskboard_add($app, $state, $store);
    });

    // 回车提交 = 点新建按钮
    $app->on('title_input', 'submit', function () use ($app, &$state, $store) {
        $state = taskboard_add($app, $state, $store);
    });

    $app->on('query_input', 'change', function (array $event) use (&$state) {
        $state['query'] = (string) ($event['value'] ?? '');
    });

    $app->on('query_input', 'submit', function (array $event) use (&$state) {
        $state['query'] = (string) ($event['value'] ?? '');
    });

    $app->on('filter_combo', 'change', function (array $event) use (&$state) {
        $state['filter'] = taskboard_filter_key((string) ($event['value'] ?? '全部'));
    });

    // 表格选中：value 是行 id（因为给了 row_ids）
    $app->on('task_table', 'select', function (array $event) use (&$state) {
        $state['selected'] = (string) ($event['value'] ?? '');
    });

    // 双击行 = 在「完成 / 待办」之间切换
    $app->on('task_table', 'activate', function (array $event) use ($app, &$state, $store) {
        $id = (string) ($event['value'] ?? '');
        if ($id === '') {
            return;
        }
        $state['tasks'] = taskboard_update_status($state['tasks'], $id, null);
        $state['saved_at'] = taskboard_save($state['tasks'], $state['next_id'], $store);
        $app->setStatus(['已切换：' . $id]);
    });

    // 详情面板里的状态下拉
    $app->on('detail_status', 'change', function (array $event) use ($app, &$state, $store) {
        if ($state['selected'] === '') {
            return;
        }
        $state['tasks'] = taskboard_update_status(
            $state['tasks'],
            $state['selected'],
            taskboard_status_key((string) ($event['value'] ?? '待办'))
        );
        $state['saved_at'] = taskboard_save($state['tasks'], $state['next_id'], $store);
    });

    // 详情面板里的优先级下拉
    $app->on('detail_priority', 'change', function (array $event) use ($app, &$state, $store) {
        if ($state['selected'] === '') {
            return;
        }
        $state['tasks'] = taskboard_update_priority(
            $state['tasks'],
            $state['selected'],
            taskboard_priority_key((string) ($event['value'] ?? '中'))
        );
        $state['saved_at'] = taskboard_save($state['tasks'], $state['next_id'], $store);
    });

    $app->on('done_btn', 'click', function () use ($app, &$state, $store) {
        $state = taskboard_set_status($app, $state, $store, 'done');
    });

    $app->on('undone_btn', 'click', function () use ($app, &$state, $store) {
        $state = taskboard_set_status($app, $state, $store, 'todo');
    });

    $app->on('delete_btn', 'click', function () use ($app, &$state, $store) {
        $state = taskboard_delete($app, $state, $store);
    });

    $app->on('clear_btn', 'click', function () use ($app, &$state, $store) {
        $state = taskboard_clear($app, $state, $store);
    });

    // ── 菜单 ──

    $app->on('menu.new', 'menu', function () use ($app) {
        // 把键盘焦点交给标题输入框：走 patch 的 call 通道，不是状态。
        $app->patch([['op' => 'call', 'id' => 'title_input', 'method' => 'focus']]);
    });

    $app->on('menu.clear', 'menu', function () use ($app, &$state, $store) {
        $state = taskboard_clear($app, $state, $store);
    });

    $app->on('menu.about', 'menu', function () use ($app) {
        $app->alert("任务看板示例\nTypePHP AOT + Qt 6 Widgets", '关于');
    });

    $app->on('menu.quit', 'menu', function () use ($app, &$state, $store) {
        taskboard_save($state['tasks'], $state['next_id'], $store);
        $app->close();
    });

    // ── 托盘 ──

    $app->on('tray.show', 'menu', function () use ($app) {
        $app->focus();
    });

    $app->on('tray.new', 'menu', function () use ($app) {
        $app->focus();
        $app->patch([['op' => 'call', 'id' => 'title_input', 'method' => 'focus']]);
    });

    $app->on('tray.quit', 'menu', function () use ($app, &$state, $store) {
        taskboard_save($state['tasks'], $state['next_id'], $store);
        $app->close();
    });

    // 托盘激活不带 id（点的是托盘项本身），所以只能挂 onAny。
    // $event['value'] 是激活方式（left / right / double / middle）。
    $app->onAny('tray', function (array $event) use ($app) {
        $app->setStatus(['托盘 ' . (string) ($event['value'] ?? '')]);
    });

    // 通配：所有点击都计数，显示在底部提示条上。
    // on() 先触发、onAny() 后触发，所以这里不碰状态栏，避免覆盖具体处理器的文案。
    $app->onAny('click', function (array $event) use (&$state) {
        $state['clicks'] = (int) $state['clicks'] + 1;
    });

    // 通配：所有选中都记到状态栏。task_table 的 select 处理器只改状态，不冲突。
    $app->onAny('select', function (array $event) use ($app) {
        $app->setStatus(['选中：' . (string) ($event['value'] ?? '')]);
    });

    // 定时器：周期性落盘任务与窗口几何。
    $app->on('autosave', 'timer', function () use ($app, &$state, $store) {
        $store->set('tasks', $state['tasks']);
        $store->set('next_id', $state['next_id']);
        $store->set('geometry', $app->saveGeometry());
        $store->flush();
    });

    // 无头验收：--shot <path> 渲染几帧后存 PNG 退出。
    $shot = shot_path($argv);
    if ($shot !== '') {
        // 3 帧就够：瞬态动画由 snapshot() 推到终点，不靠多泵帧等它跑完。
        $app->runFrames(3);
        $ok = $app->snapshot($shot);
        $app->destroy();
        if (!$ok) {
            echo "snapshot failed: $shot\n";
            exit(1);
        }
        return;
    }

    // 定时器注册点在 --shot 分支之后：截图基线要求画面不随墙钟变化。
    $app->setTimer('autosave', 2000);

    // 无头自检：--selftest 逐个触发所有事件，验证每个 handler 都能正常调用。
    // 这能在无头环境覆盖「闭包参数个数不匹配」这类只在 AOT 下暴露的问题。
    if (has_flag($argv, '--selftest')) {
        $failed = self_test($app);
        $app->destroy();
        if ($failed > 0) {
            exit(1);
        }
        return;
    }

    // GUI 主循环
    while ($app->isOpen()) {
        $app->runFrames(1);
    }
    // 退出前再落一次盘，别让用户最后几秒的改动只留在内存里。
    taskboard_save($state['tasks'], $state['next_id'], $store);
    $app->destroy();
}

// ── 状态变更：纯函数，返回新状态 ──

/** 新建一个任务：标题为空时弹错误框，不写入。 */
function taskboard_add(QtApp $app, array $state, JsonStore $store): array
{
    $title = trim((string) $state['new_title']);
    if ($title === '') {
        $app->error('标题不能为空', '新建任务');
        return $state;
    }
    $id = 't' . (int) $state['next_id'];
    $state['next_id'] = (int) $state['next_id'] + 1;
    $state['tasks'][] = [
        'id' => $id,
        'title' => $title,
        'status' => 'todo',
        'priority' => (string) $state['new_priority'],
    ];
    $state['selected'] = $id;
    $state['new_title'] = '';
    $state['saved_at'] = taskboard_save($state['tasks'], $state['next_id'], $store);
    $app->setStatus(['已新建：' . $title]);
    return $state;
}

/** 把选中任务改成指定状态；没选中时弹错误框。 */
function taskboard_set_status(QtApp $app, array $state, JsonStore $store, string $status): array
{
    if ($state['selected'] === '') {
        $app->error('请先选中一个任务', '修改状态');
        return $state;
    }
    $state['tasks'] = taskboard_update_status($state['tasks'], $state['selected'], $status);
    $state['saved_at'] = taskboard_save($state['tasks'], $state['next_id'], $store);
    $app->setStatus(['已设为：' . taskboard_status_label($status)]);
    return $state;
}

/** 删除选中任务，先弹确认框。 */
function taskboard_delete(QtApp $app, array $state, JsonStore $store): array
{
    if ($state['selected'] === '') {
        $app->error('请先选中一个任务', '删除');
        return $state;
    }
    if (!$app->confirm('删除选中的任务？', '删除确认')) {
        return $state;
    }
    $id = (string) $state['selected'];
    $state['tasks'] = taskboard_remove($state['tasks'], $id);
    $state['selected'] = '';
    $state['saved_at'] = taskboard_save($state['tasks'], $state['next_id'], $store);
    $app->setStatus(['已删除：' . $id]);
    return $state;
}

/** 清空全部任务，先弹确认框。 */
function taskboard_clear(QtApp $app, array $state, JsonStore $store): array
{
    if ($state['tasks'] === []) {
        $app->alert('已经是空的了', '清空全部');
        return $state;
    }
    if (!$app->confirm('清空全部任务？此操作不可撤销。', '清空确认')) {
        return $state;
    }
    $state['tasks'] = [];
    $state['selected'] = '';
    $state['saved_at'] = taskboard_save($state['tasks'], $state['next_id'], $store);
    $app->setStatus(['已清空']);
    return $state;
}

// ── 持久化 ──

/** 落盘任务与自增 id，返回落盘时间戳。 */
function taskboard_save(array $tasks, int $nextId, JsonStore $store): string
{
    $store->set('tasks', $tasks);
    $store->set('next_id', $nextId);
    $store->flush();
    return date('H:i:s');
}

/** 从存储载入任务列表，过滤掉残缺条目（缺 id 或标题的不能进表格）。 */
function taskboard_tasks(JsonStore $store): array
{
    $raw = $store->get('tasks', []);
    if (!is_array($raw)) {
        return [];
    }
    $tasks = [];
    foreach ($raw as $task) {
        if (!is_array($task)) {
            continue;
        }
        $id = (string) ($task['id'] ?? '');
        $title = (string) ($task['title'] ?? '');
        if ($id === '' || $title === '') {
            continue;
        }
        $tasks[] = [
            'id' => $id,
            'title' => $title,
            'status' => taskboard_norm_status((string) ($task['status'] ?? 'todo')),
            'priority' => taskboard_norm_priority((string) ($task['priority'] ?? 'mid')),
        ];
    }
    return $tasks;
}

/** 下一个任务 id 的序号；存储里不是数字或小于 1 时回落到 1。 */
function taskboard_next_id(JsonStore $store): int
{
    $stored = $store->get('next_id', 1);
    $n = is_numeric($stored) ? (int) $stored : 1;
    return $n < 1 ? 1 : $n;
}

// ── 视图数据 ──

/** 按过滤条件与搜索词筛出可见任务。 */
function taskboard_visible(array $state): array
{
    $out = [];
    foreach ($state['tasks'] as $task) {
        if (!is_array($task)) {
            continue;
        }
        $status = (string) ($task['status'] ?? 'todo');
        $title = (string) ($task['title'] ?? '');
        if ($state['filter'] !== 'all' && $status !== $state['filter']) {
            continue;
        }
        // strpos 按字节比较，对 UTF-8 子串搜索是安全的（UTF-8 自同步，字节序列不重叠）。
        // 不用 stripos：它会把字节转小写，可能破坏多字节字符。
        if ($state['query'] !== '' && strpos($title, $state['query']) === false) {
            continue;
        }
        $out[] = $task;
    }
    return $out;
}

/** 可见任务 → 表格行（每行一组单元格）。 */
function taskboard_rows(array $visible): array
{
    $rows = [];
    foreach ($visible as $task) {
        $rows[] = [
            (string) ($task['title'] ?? ''),
            taskboard_status_label((string) ($task['status'] ?? 'todo')),
            taskboard_priority_label((string) ($task['priority'] ?? 'mid')),
        ];
    }
    return $rows;
}

/** 可见任务 → 行 id 列表，与行一一对应。 */
function taskboard_row_ids(array $visible): array
{
    $ids = [];
    foreach ($visible as $task) {
        $ids[] = (string) ($task['id'] ?? '');
    }
    return $ids;
}

/** 按 id 找任务，找不到返回 null。 */
function taskboard_find(array $tasks, string $id): ?array
{
    foreach ($tasks as $task) {
        if (is_array($task) && (string) ($task['id'] ?? '') === $id) {
            return $task;
        }
    }
    return null;
}

/** 改一个任务的状态；$status 为 null 时在 done / todo 之间切换。 */
function taskboard_update_status(array $tasks, string $id, ?string $status): array
{
    $count = count($tasks);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tasks[$i])) {
            continue;
        }
        if ((string) ($tasks[$i]['id'] ?? '') !== $id) {
            continue;
        }
        $tasks[$i]['status'] = $status === null
            ? (($tasks[$i]['status'] === 'done') ? 'todo' : 'done')
            : $status;
        return $tasks;
    }
    return $tasks;
}

/** 改一个任务的优先级。 */
function taskboard_update_priority(array $tasks, string $id, string $priority): array
{
    $count = count($tasks);
    for ($i = 0; $i < $count; $i++) {
        if (!is_array($tasks[$i])) {
            continue;
        }
        if ((string) ($tasks[$i]['id'] ?? '') !== $id) {
            continue;
        }
        $tasks[$i]['priority'] = $priority;
        return $tasks;
    }
    return $tasks;
}

/** 按 id 删掉一个任务。 */
function taskboard_remove(array $tasks, string $id): array
{
    $out = [];
    foreach ($tasks as $task) {
        if (is_array($task) && (string) ($task['id'] ?? '') === $id) {
            continue;
        }
        $out[] = $task;
    }
    return $out;
}

/** 统计文案。 */
function taskboard_stats_text(array $state): string
{
    $total = count($state['tasks']);
    $done = 0;
    foreach ($state['tasks'] as $task) {
        if (is_array($task) && (string) ($task['status'] ?? '') === 'done') {
            $done++;
        }
    }
    return '共 ' . $total . ' 项 · 完成 ' . $done . ' · 显示 ' . count(taskboard_visible($state));
}

/**
 * 详情面板：选中任务时显示。
 *
 * 整个面板显式给 id —— 它是条件分支，前面兄弟（表格行数）一变，
 * 隐式路径 id 就会漂移，面板会被判成「删了又建」，里面的下拉框选中随之丢失。
 */
function taskboard_detail_panel(array $state): array
{
    $task = taskboard_find($state['tasks'], (string) $state['selected']);
    if ($task === null) {
        return WidgetTree::label('未选中任务', ['id' => 'detail_panel']);
    }
    return WidgetTree::group('任务详情', [
        WidgetTree::label('标题：' . (string) ($task['title'] ?? ''), ['id' => 'detail_title']),
        WidgetTree::hbox([
            WidgetTree::label('状态：'),
            WidgetTree::combo(
                ['待办', '进行中', '完成'],
                taskboard_status_label((string) ($task['status'] ?? 'todo')),
                ['id' => 'detail_status']
            ),
            WidgetTree::label('优先级：'),
            WidgetTree::combo(
                ['低', '中', '高'],
                taskboard_priority_label((string) ($task['priority'] ?? 'mid')),
                ['id' => 'detail_priority']
            ),
        ]),
    ], ['id' => 'detail_panel']);
}

// ── 键值映射 ──

function taskboard_status_label(string $key): string
{
    $map = ['todo' => '待办', 'doing' => '进行中', 'done' => '完成'];
    return $map[$key] ?? '待办';
}

function taskboard_status_key(string $label): string
{
    $map = ['待办' => 'todo', '进行中' => 'doing', '完成' => 'done'];
    return $map[$label] ?? 'todo';
}

function taskboard_priority_label(string $key): string
{
    $map = ['low' => '低', 'mid' => '中', 'high' => '高'];
    return $map[$key] ?? '中';
}

function taskboard_priority_key(string $label): string
{
    $map = ['低' => 'low', '中' => 'mid', '高' => 'high'];
    return $map[$label] ?? 'mid';
}

function taskboard_filter_label(string $key): string
{
    $map = ['all' => '全部', 'todo' => '待办', 'doing' => '进行中', 'done' => '完成'];
    return $map[$key] ?? '全部';
}

function taskboard_filter_key(string $label): string
{
    $map = ['全部' => 'all', '待办' => 'todo', '进行中' => 'doing', '完成' => 'done'];
    return $map[$label] ?? 'all';
}

/** 把存储里读到的状态值归一到合法键（未知值回落 todo）。 */
function taskboard_norm_status(string $key): string
{
    return taskboard_status_key(taskboard_status_label($key));
}

/** 把存储里读到的优先级值归一到合法键（未知值回落 mid）。 */
function taskboard_norm_priority(string $key): string
{
    return taskboard_priority_key(taskboard_priority_label($key));
}

// ── 无头自检 ──

/**
 * 逐个触发所有已注册控件的事件，验证 handler 可调用。
 *
 * AOT 编译后的闭包对实参个数做精确校验，参数个数不匹配时
 * 只有在真正触发事件时才会暴露 —— 所以这个自检必须实际分发事件。
 */
function self_test(QtApp $app): int
{
    // 自检必须无头：否则 delete_btn / clear_btn 的 confirm() 会弹模态框，
    // 在无终端环境下永久阻塞在 exec()。
    $app->headless(true);

    $cases = [
        ['change', 'title_input', '写测试'],
        ['change', 'priority_combo', '高'],
        ['click', 'add_btn'],
        ['submit', 'title_input'],
        ['change', 'query_input', '测试'],
        ['submit', 'query_input'],
        ['change', 'filter_combo', '进行中'],
        ['select', 'task_table', 't1'],
        ['activate', 'task_table', 't1'],
        ['change', 'detail_status', '完成'],
        ['change', 'detail_priority', '低'],
        ['click', 'done_btn'],
        ['click', 'undone_btn'],
        ['click', 'delete_btn'],
        ['click', 'clear_btn'],
        ['menu', 'menu.new'],
        ['menu', 'menu.clear'],
        ['menu', 'menu.about'],
        ['menu', 'tray.show'],
        ['menu', 'tray.new'],
        ['timer', 'autosave'],
        ['tray', '', 'left'],
        ['tray', '', 'double'],
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
