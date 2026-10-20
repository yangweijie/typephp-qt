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

        /** @var array<string, array> 窗口 id -> 最近一次渲染的树（a11y_tree 要用） */
        public static array $trees = [];

        /** @var array<int, array> qt_http_request() 收到的请求轨迹 */
        public static array $httpRequests = [];

        /**
         * 预置的 HTTP 响应队列。
         *
         * 假桥**绝不发真实网络请求**：队列为空时返回失败结构，让测试显式决定
         * 「网络坏了」这条分支怎么走；需要成功响应时用 test_set_http_response()。
         *
         * @var array<int, array>
         */
        public static array $httpResponses = [];

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
            self::$trees = [];
            self::$httpRequests = [];
            self::$httpResponses = [];
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
        FakeState::$windows[$id] = [
            'title' => $title,
            'open' => true,
            'options' => $options,
            // 真实桥接里窗口一开始就有几何（options 的 width/height 或 Qt 默认值），
            // 假桥同样先铺好，好让 geometry() 在任何时候都返回完整四元组。
            'x' => (int) ($options['x'] ?? 0),
            'y' => (int) ($options['y'] ?? 0),
            'width' => (int) ($options['width'] ?? 800),
            'height' => (int) ($options['height'] ?? 600),
        ];
        FakeState::$queues[$id] = [];
        FakeState::$values[$id] = [];
        FakeState::$props[$id] = [];
        FakeState::$timers[$id] = [];
        return $id;
    }

    function qt_window_render(mixed $window, array $tree): void
    {
        FakeState::$renders[] = $tree;
        // 保留最近一帧，a11y_tree() 要按它递归出结构化快照。
        FakeState::$trees[$window] = $tree;
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
            FakeState::$timers[$window],
            FakeState::$trees[$window]
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

    // ── 窗口集合与几何 ──

    function qt_window_list(): array
    {
        // `__app__` 不是窗口，是 qt_app_create() 的选项袋子。
        return array_values(array_filter(
            array_keys(FakeState::$windows),
            static fn ($id): bool => $id !== '__app__'
        ));
    }

    function qt_window_id(mixed $window): string
    {
        // 假桥的句柄本来就是 id 字符串。
        return (string) $window;
    }

    function qt_window_focus(mixed $window): void
    {
        if (isset(FakeState::$windows[$window])) {
            FakeState::$windows[$window]['minimized'] = false;
            FakeState::$windows[$window]['active'] = true;
        }
    }

    function qt_window_center(mixed $window): void
    {
        if (!isset(FakeState::$windows[$window])) {
            return;
        }
        // 真实桥接按所在屏幕的可用区域算中心；假桥没有屏幕概念，
        // 用固定虚拟屏幕（1920x1080）推出同样的四元组，好让断言可预测。
        $w = (int) (FakeState::$windows[$window]['width'] ?? 0);
        $h = (int) (FakeState::$windows[$window]['height'] ?? 0);
        FakeState::$windows[$window]['x'] = intdiv(1920 - $w, 2);
        FakeState::$windows[$window]['y'] = intdiv(1080 - $h, 2);
        FakeState::$windows[$window]['centered'] = true;
    }

    function qt_window_set_parent(mixed $window, mixed $parent): void
    {
        if (!isset(FakeState::$windows[$window])) {
            return;
        }
        if ($parent === null) {
            unset(FakeState::$windows[$window]['parent']);
            return;
        }
        FakeState::$windows[$window]['parent'] = (string) $parent;
    }

    function qt_window_geometry(mixed $window): array
    {
        $info = FakeState::$windows[$window] ?? [];
        return [
            'x' => (int) ($info['x'] ?? 0),
            'y' => (int) ($info['y'] ?? 0),
            'width' => (int) ($info['width'] ?? 0),
            'height' => (int) ($info['height'] ?? 0),
        ];
    }

    function qt_window_set_geometry(mixed $window, array $rect): void
    {
        if (!isset(FakeState::$windows[$window])) {
            return;
        }
        // 缺省的键保持不动 —— 与真实桥接的语义一致。
        foreach (['x', 'y', 'width', 'height'] as $key) {
            if (array_key_exists($key, $rect)) {
                FakeState::$windows[$window][$key] = (int) $rect[$key];
            }
        }
    }

    // ── 事件注入与内省 ──

    function qt_window_send_event(mixed $window, array $event): void
    {
        // 注入的是「PHP 收到的事件」，不经过 Qt，所以这里和 test_inject_event 等价。
        test_inject_event($window, $event);
    }

    function qt_window_find(mixed $window, string $id): array
    {
        $props = FakeState::$props[$window][$id] ?? null;
        if (!is_array($props)) {
            return [];
        }
        $type = (string) ($props['type'] ?? '');
        return [
            'id' => $id,
            'type' => $type,
            'value' => FakeState::$values[$window][$id] ?? null,
            'visible' => (bool) ($props['visible'] ?? true),
            'enabled' => (bool) ($props['enabled'] ?? true),
            // 假桥没有布局引擎，几何统一报零；断言几何请走真实桥接的 headless 测试。
            'geometry' => ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 0],
        ];
    }

    function qt_window_a11y_tree(mixed $window, int $maxDepth = 6): array
    {
        $tree = FakeState::$trees[$window] ?? null;
        if (!is_array($tree)) {
            return [];
        }
        return qt_fake_a11y_node($window, $tree, max(0, $maxDepth));
    }

    function qt_window_poll_events(mixed $window, int $max = 64): array
    {
        $queue = FakeState::$queues[$window] ?? [];
        if (empty($queue) || $max <= 0) {
            return [];
        }
        $taken = array_splice($queue, 0, $max);
        FakeState::$queues[$window] = $queue;
        return array_values($taken);
    }

    function qt_window_toast(mixed $window, string $message, int $durationMs = 3000): void
    {
        FakeState::$windows[$window]['toasts'][] = ['message' => $message, 'duration' => $durationMs];
    }

    // ── 网络原语 ──

    /**
     * 假桥绝不发真实网络请求。
     *
     * 队列里有预置响应就取一条；没有就返回失败结构（status=0 + error），
     * 这样「网络坏了」这条分支永远是测试显式选择的结果，不会因环境抖动。
     */
    function qt_http_request(array $spec): array
    {
        FakeState::$httpRequests[] = $spec;
        if (FakeState::$httpResponses !== []) {
            $response = array_shift(FakeState::$httpResponses);
            return [
                'status' => (int) ($response['status'] ?? 200),
                'headers' => (array) ($response['headers'] ?? []),
                'body' => (string) ($response['body'] ?? ''),
                'error' => (string) ($response['error'] ?? ''),
            ];
        }
        return [
            'status' => 0,
            'headers' => [],
            'body' => '',
            'error' => 'fake bridge: no network',
        ];
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

    /** 预置一条 HTTP 响应，qt_http_request() 会按顺序取走。 */
    function test_set_http_response(array $response): void
    {
        FakeState::$httpResponses[] = $response;
    }

    /** 读取 HTTP 请求轨迹。 */
    function test_http_requests(): array
    {
        return FakeState::$httpRequests;
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

    /**
     * 把声明式节点转成无障碍快照。
     *
     * 真实桥接读的是 QWidget 的实际状态；假桥只能读渲染树 + 已记录的属性，
     * 所以 visible/enabled 一律按「未声明即为真」推导，geometry 报零。
     * 目的是让「树里有没有这个节点、层级对不对」可断言，而不是验像素。
     */
    function qt_fake_a11y_node(mixed $window, array $node, int $depth): array
    {
        $type = (string) ($node['type'] ?? '');
        $id = (string) ($node['id'] ?? '');
        $result = [
            'role' => $type,
            'id' => $id,
            'text' => (string) ($node['text'] ?? $node['label'] ?? $node['title'] ?? ''),
            'value' => FakeState::$values[$window][$id] ?? ($node['value'] ?? null),
            'visible' => (bool) ($node['visible'] ?? true),
            'enabled' => (bool) ($node['enabled'] ?? true),
            'geometry' => ['x' => 0, 'y' => 0, 'width' => 0, 'height' => 0],
        ];
        if ($depth > 0) {
            $children = [];
            foreach ($node['children'] ?? [] as $child) {
                if (is_array($child)) {
                    $children[] = qt_fake_a11y_node($window, $child, $depth - 1);
                }
            }
            if ($children !== []) {
                $result['children'] = $children;
            }
        }
        return $result;
    }
}
