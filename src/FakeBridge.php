<?php

declare(strict_types=1);

/**
 * FakeBridge — 测试用桥接替身。
 *
 * 以**全局命名空间 + 同签名的函数**实现 php-src/qt.stub.php 的契约，
 * 纯 PHP、无 Qt、无编译器依赖，因此领域逻辑可以直接单测。
 *
 * 函数必须放在全局命名空间：QtApp / WidgetTree 里写的是不带前缀的
 * `qt_window_render(...)`，PHP 只在全局作用域查找，这与真实 AOT 编译
 * 时 stub 声明的全局函数形态一致。
 *
 * 与真实桥接的行为差异（测试可依赖的部分）：
 *   * 窗口句柄是字符串 `win_N`，不是不透明 Box。
 *   * 控件值由 render 时节点上的 text/value/checked 推导。
 *   * 事件由 test_inject_event() 手动注入。
 *   * 消息框返回 spec['default']，文件对话框返回空。
 *   * snapshot() 只记录调用，不产生真实 PNG。
 */

namespace TypePHP\Qt\Fake {

    /** 所有假窗口的状态。 */
    final class FakeState
    {
        /** @var array<string, array> 窗口 id -> 窗口信息（含 __app__ 这一项） */
        public static array $windows = [];

        /** @var array<string, array<int, array>> 窗口 id -> 待处理事件 */
        public static array $queues = [];

        /** @var array<string, array<string, mixed>> 窗口 id -> 控件 id -> 值 */
        public static array $values = [];

        /** @var array<string, array<string, array>> 窗口 id -> 控件 id -> 节点属性 */
        public static array $props = [];

        /** @var array<string, array<string, int>> 窗口 id -> 定时器 id -> 间隔 */
        public static array $timers = [];

        public static string $clipboard = '';

        /** 测试替身报的 webview 后端；默认 textbrowser（与无 Qt 的环境一致）。 */
        public static string $webViewBackend = 'textbrowser';

        /** @var array<int, array{window: mixed, id: string, method: string}> webview 命令式动作的调用轨迹 */
        public static array $webViewCalls = [];

        public static int $seq = 0;

        /** @var string[] snapshot() 收到的路径 */
        public static array $snapshots = [];

        /** @var array<int, array> 所有已渲染的树，便于断言 */
        public static array $renders = [];

        public static function reset(): void
        {
            self::$windows = [];
            self::$queues = [];
            self::$values = [];
            self::$props = [];
            self::$timers = [];
            self::$clipboard = '';
            self::$webViewBackend = 'textbrowser';
            self::$webViewCalls = [];
            self::$seq = 0;
            self::$snapshots = [];
            self::$renders = [];
        }
    }

}

namespace {

    use TypePHP\Qt\Fake\FakeState;

    // ── 桥接契约（与 php-src/qt.stub.php 同签名） ──

    function qt_bridge_version(): string
    {
        return 'fake-0.1.0';
    }

    function qt_app_create(array $options = []): void
    {
        FakeState::$windows['__app__'] = $options;
    }

    function qt_window_create(string $title, array $options = []): mixed
    {
        $id = 'win_' . (++FakeState::$seq);
        FakeState::$windows[$id] = ['title' => $title, 'open' => true, 'options' => $options];
        FakeState::$queues[$id] = [];
        FakeState::$values[$id] = [];
        FakeState::$props[$id] = [];
        FakeState::$timers[$id] = [];
        return $id;
    }

    function qt_window_render(mixed $window, array $tree): void
    {
        FakeState::$renders[] = $tree;
        qt_fake_walk($window, $tree);
    }

    function qt_window_patch(mixed $window, array $ops): void
    {
        foreach ($ops as $op) {
            if (!is_array($op)) continue;
            $id = (string) ($op['id'] ?? '');
            $kind = (string) ($op['op'] ?? '');

            if ($kind === 'set') {
                if (!isset($op['props']) || !is_array($op['props'])) continue;
                foreach ($op['props'] as $key => $value) {
                    FakeState::$props[$window][$id][$key] = $value;
                    if ($key === 'text' || $key === 'value') {
                        FakeState::$values[$window][$id] = $value;
                    } elseif ($key === 'checked') {
                        FakeState::$values[$window][$id] = (bool) $value;
                    }
                }
                continue;
            }

            if ($kind !== 'call') continue;
            // 与真实桥接一致：控件不存在就整条跳过，而不是凭空造一份状态。
            if (!isset(FakeState::$props[$window][$id])) continue;
            $method = (string) ($op['method'] ?? '');
            $args = array_values(array_slice((array) ($op['args'] ?? []), 0, 2));
            $type = (string) (FakeState::$props[$window][$id]['type'] ?? '');

            switch ($method) {
                case 'reload':
                case 'goBack':
                case 'goForward':
                    // webview 的三个命令式动作：真实桥接只转给 WebView2 后端，
                    // 且没有可断言的返回值。这里记一笔调用轨迹，供测试断言
                    // 「动作确实发出去了」，而不是断言某个属性变了。
                    if ($type !== 'webview') break;
                    FakeState::$webViewCalls[] = ['window' => $window, 'id' => $id, 'method' => $method];
                    break;
                case 'appendRows':
                    if ($type !== 'table') break;
                    $rows = (array) (FakeState::$props[$window][$id]['rows'] ?? []);
                    foreach ((array) ($args[0] ?? []) as $row) {
                        $rows[] = $row;
                    }
                    FakeState::$props[$window][$id]['rows'] = $rows;
                    if (isset($args[1])) {
                        $ids = (array) (FakeState::$props[$window][$id]['row_ids'] ?? []);
                        foreach ((array) $args[1] as $rowId) {
                            $ids[] = $rowId;
                        }
                        FakeState::$props[$window][$id]['row_ids'] = $ids;
                    }
                    break;
                case 'clear':
                    $key = match ($type) {
                        'table' => 'rows',
                        'tree' => 'nodes',
                        'list', 'combo' => 'items',
                        default => 'text',
                    };
                    FakeState::$props[$window][$id][$key] = $key === 'text' ? '' : [];
                    FakeState::$values[$window][$id] = $key === 'text' ? '' : null;
                    break;
                case 'setText':
                    FakeState::$props[$window][$id]['text'] = $args[0] ?? '';
                    FakeState::$values[$window][$id] = $args[0] ?? '';
                    break;
                case 'setValue':
                    $prop = $type === 'lineedit' || $type === 'textedit' ? 'text' : 'value';
                    FakeState::$props[$window][$id][$prop] = $args[0] ?? '';
                    FakeState::$values[$window][$id] = $args[0] ?? '';
                    break;
                case 'select':
                    FakeState::$props[$window][$id]['current'] = $args[0] ?? '';
                    break;
                case 'focus':
                    FakeState::$props[$window][$id]['focused'] = true;
                    break;
            }
        }
    }

    function qt_window_is_open(mixed $window): bool
    {
        return (bool) (FakeState::$windows[$window]['open'] ?? false);
    }

    function qt_window_process_events(mixed $window): void
    {
        // 假桥不做真正的事件泵
    }

    function qt_window_poll_event(mixed $window): array
    {
        if (empty(FakeState::$queues[$window])) {
            return [];
        }
        return array_shift(FakeState::$queues[$window]);
    }

    function qt_window_close(mixed $window): void
    {
        if (isset(FakeState::$windows[$window])) {
            FakeState::$windows[$window]['open'] = false;
        }
    }

    function qt_window_destroy(mixed $window): void
    {
        unset(
            FakeState::$windows[$window],
            FakeState::$queues[$window],
            FakeState::$values[$window],
            FakeState::$props[$window],
            FakeState::$timers[$window]
        );
    }

    function qt_window_snapshot(mixed $window, string $path): bool
    {
        FakeState::$snapshots[] = $path;
        return true;
    }

    function qt_window_widget_value(mixed $window, string $id): mixed
    {
        return FakeState::$values[$window][$id] ?? null;
    }

    function qt_window_set_title(mixed $window, string $title): void
    {
        FakeState::$windows[$window]['title'] = $title;
    }

    function qt_window_set_menu(mixed $window, array $items): void
    {
        FakeState::$windows[$window]['menu'] = $items;
    }

    function qt_window_set_status(mixed $window, array $segments): void
    {
        FakeState::$windows[$window]['status'] = $segments;
    }

    function qt_window_resize(mixed $window, int $width, int $height): void
    {
        FakeState::$windows[$window]['width'] = $width;
        FakeState::$windows[$window]['height'] = $height;
    }

    function qt_window_message(mixed $window, array $spec): string
    {
        FakeState::$windows[$window]['messages'][] = $spec;
        return (string) ($spec['default'] ?? 'ok');
    }

    function qt_window_file_dialog(mixed $window, array $spec): array
    {
        return FakeState::$windows[$window]['file_dialog_result'] ?? [];
    }

    function qt_window_directory_dialog(mixed $window, array $spec): string
    {
        return (string) (FakeState::$windows[$window]['dir_dialog_result'] ?? '');
    }

    function qt_window_notify(mixed $window, string $title, string $message): void
    {
        FakeState::$windows[$window]['notifications'][] = ['title' => $title, 'message' => $message];
    }

    function qt_window_set_tray(mixed $window, array $spec): void
    {
        FakeState::$windows[$window]['tray'] = $spec;
    }

    function qt_window_set_timer(mixed $window, string $id, int $intervalMs): void
    {
        if ($intervalMs <= 0) {
            unset(FakeState::$timers[$window][$id]);
        } else {
            FakeState::$timers[$window][$id] = $intervalMs;
        }
    }

    function qt_clipboard_read(): string
    {
        return FakeState::$clipboard;
    }

    function qt_clipboard_write(string $text): void
    {
        FakeState::$clipboard = $text;
    }

    /**
     * 测试替身里固定报 textbrowser 后端。
     *
     * 这是刻意的：它跑在普通 PHP 上、没有 Qt，报 "webview2" 会骗过应用侧的能力判断
     * （比如以为能用 JS）。测试里要验「不支持 JS 时的降级路径」也靠它。
     * 想模拟原生后端可用时，用 test_set_webview_backend() 覆盖。
     */
    function qt_webview_backend(): string
    {
        return FakeState::$webViewBackend;
    }

    function qt_webview_supports_js(): bool
    {
        // 与 cpp-src/qt_webview.cc 的 qtWebViewSupportsJs() 同口径：两个原生后端都支持 JS。
        return in_array(FakeState::$webViewBackend, ['webview2', 'wkwebview'], true);
    }

    // ── 测试辅助（真实桥接没有这些） ──

    /** 注入一个事件到队列。 */
    function test_inject_event(mixed $window, array $event): void
    {
        FakeState::$queues[$window][] = $event;
    }

    /** 读取窗口内部状态。 */
    function test_window(mixed $window): array
    {
        return FakeState::$windows[$window] ?? [];
    }

    /** 读取控件属性。 */
    function test_props(mixed $window, string $id): array
    {
        return FakeState::$props[$window][$id] ?? [];
    }

    /** 清空全部假状态（每个测试 setUp 调用）。 */
    function test_reset(): void
    {
        FakeState::reset();
    }

    /** 覆盖 webview 后端名，用于测「支持 JS」与「不支持 JS」两条分支。 */
    function test_set_webview_backend(string $backend): void
    {
        FakeState::$webViewBackend = $backend;
    }

    // ── 内部 ──

    /** 递归登记树里每个有 id 的节点，并按类型推导初始值。 */
    function qt_fake_walk(mixed $window, array $node): void
    {
        $id = (string) ($node['id'] ?? '');
        $type = (string) ($node['type'] ?? '');
        if ($id !== '' && $type !== '') {
            FakeState::$props[$window][$id] = $node;
            FakeState::$values[$window][$id] = qt_fake_default_value($type, $node);
        }
        foreach ($node['children'] ?? [] as $child) {
            if (is_array($child)) {
                qt_fake_walk($window, $child);
            }
        }
    }

    function qt_fake_default_value(string $type, array $node): mixed
    {
        return match ($type) {
            'lineedit', 'textedit' => $node['text'] ?? '',
            'spin', 'doublespin', 'slider', 'progress' => $node['value'] ?? 0,
            'checkbox', 'radio' => (bool) ($node['checked'] ?? false),
            'label', 'button' => $node['text'] ?? '',
            default => null,
        };
    }
}
