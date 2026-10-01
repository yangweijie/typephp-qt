<?php

declare(strict_types=1);

namespace TypePHP\Qt;

/**
 * QtApp — PHP 侧应用框架。
 *
 * 直接调用 qt_* 全局桥接函数（由 php-src/qt.stub.php 声明、cpp-src/ 实现）。
 * 测试时这些函数由 src/FakeBridge.php 提供同签名的纯 PHP 实现，
 * 因此领域逻辑无需 Qt 或编译器即可单测。
 *
 * 用法：
 *   $app = new QtApp();
 *   $app->create(['name' => 'MyApp']);
 *   $app->createWindow('My App');
 *   $app->on('btn', 'click', fn () => $app->notify('Hi', 'Clicked'));
 *   $app->render(WidgetTree::vbox([...]));
 *   $app->run();
 */
final class QtApp
{
    /** 不透明窗口句柄（真实环境是 Box resource，测试环境是字符串）。 */
    private mixed $window = null;

    /**
     * 事件处理器表。
     *
     * 每个条目记录 `[callable, arity]` —— arity 在**注册时**探测一次。
     *
     * 为什么需要 arity：AOT 编译后的闭包对实参个数做**精确**校验，
     * 多传一个实参就抛 `ArgumentCountError`（普通 PHP 会静默忽略多余实参）。
     * 所以 `function () {...}` 和 `function (array $event) {...}` 必须按
     * 各自声明的个数调用，不能统一传 $event。
     *
     * @var array<string, array<string, array{0: callable, 1: int}>>
     */
    private array $handlers = [];

    /** @var array<int, callable> 每帧求值的视图函数，用于基于状态的声明式重渲染。 */
    private array $views = [];

    private ?array $pendingTree = null;

    private bool $running = false;

    private int $frames = 0;

    /** 最近一次处理器异常描述，供无头自检读取。 */
    private string $lastError = '';

    // ── 应用生命周期 ──

    public function create(array $options = []): void
    {
        \qt_app_create($options);
    }

    public function createWindow(string $title, array $options = []): mixed
    {
        $this->window = \qt_window_create($title, $options);
        return $this->window;
    }

    public function handle(): mixed
    {
        if ($this->window === null) {
            throw new \RuntimeException('No window created. Call createWindow() first.');
        }
        return $this->window;
    }

    // ── 渲染 ──

    /** 渲染一棵控件树。多次调用按 id 做差异更新，控件状态保留。 */
    public function render(array $tree): void
    {
        $this->pendingTree = $tree;
    }

    /**
     * 注册一个"每帧求值"的视图函数。
     *
     * 事件处理只改状态，下一帧视图函数读到新状态自动重渲染，
     * 不需要在每个 handler 里手写 render()。
     */
    public function view(callable $builder): void
    {
        $this->views[] = $builder;
    }

    /** 局部补丁，避免整树重渲染。 */
    public function patch(array $ops): void
    {
        \qt_window_patch($this->handle(), $ops);
    }

    // ── 事件 ──

    /**
     * 注册控件事件处理器。$type 见 README 事件表（click/change/toggle/...）。
     *
     * 处理器可以写成 `function () {...}`（不关心事件内容）或
     * `function (array $event) {...}`（需要读 value/payload）。
     * 参数个数在注册时探测，两种写法都能正常工作。
     */
    public function on(string $id, string $type, callable $handler): void
    {
        $this->handlers[$id][$type] = [$handler, $this->arityOf($handler, $id, $type)];
    }

    /** 注册通配处理器，接收所有控件的该类型事件。 */
    public function onAny(string $type, callable $handler): void
    {
        $this->handlers['*'][$type] = [$handler, $this->arityOf($handler, '*', $type)];
    }

    /**
     * 探测处理器声明的必需参数个数。
     *
     * 用反射而非"传参失败再回退"：异常做控制流会重复执行处理器前半段，
     * 且 handler 内部自己抛 ArgumentCountError 时会被误判。
     * 反射在 AOT 与普通 PHP 下都可用（实测闭包/方法数组/函数名均支持）。
     */
    private function arityOf(callable $handler, string $id, string $type): int
    {
        try {
            // 方法数组必须走 ReflectionMethod，其余走 ReflectionFunction。
            // 分开用两个变量：AOT 的类型推断不允许同一变量先绑定
            // ReflectionMethod 再绑定 ReflectionFunction。
            if (is_array($handler)) {
                $methodRef = new \ReflectionMethod($handler[0], $handler[1]);
                return $methodRef->getNumberOfRequiredParameters();
            }
            $functionRef = new \ReflectionFunction($handler);
            return $functionRef->getNumberOfRequiredParameters();
        } catch (\Throwable $e) {
            // 反射失败时按"要事件"处理，这是更常见的写法。
            return 1;
        }
    }

    /** 处理单个事件（测试可直接调用，无需跑事件循环）。 */
    public function dispatch(array $event): void
    {
        $this->handleEvent($event);
    }

    // ── 窗口装饰 ──

    public function setTitle(string $title): void
    {
        \qt_window_set_title($this->handle(), $title);
    }

    public function setMenu(array $items): void
    {
        \qt_window_set_menu($this->handle(), $items);
    }

    public function setStatus(array $segments): void
    {
        \qt_window_set_status($this->handle(), $segments);
    }

    public function resize(int $width, int $height): void
    {
        \qt_window_resize($this->handle(), $width, $height);
    }

    public function setTray(array $spec): void
    {
        \qt_window_set_tray($this->handle(), $spec);
    }

    public function setTimer(string $id, int $intervalMs): void
    {
        \qt_window_set_timer($this->handle(), $id, $intervalMs);
    }

    // ── 对话框 ──

    /**
     * 无头模式：不弹真实模态框，直接返回 $spec['default']。
     *
     * 模态对话框在无头环境（CI / --selftest）会永久阻塞在 exec()，
     * 所以自动化场景必须绕开。用 headless(true) 开启。
     */
    private bool $headless = false;

    public function headless(bool $on = true): void
    {
        $this->headless = $on;
    }

    public function isHeadless(): bool
    {
        return $this->headless;
    }

    public function message(array $spec): string
    {
        if ($this->headless) {
            return (string) ($spec['default'] ?? 'ok');
        }
        return \qt_window_message($this->handle(), $spec);
    }

    public function confirm(string $text, string $title = '确认'): bool
    {
        return $this->message([
            'type' => 'question',
            'title' => $title,
            'text' => $text,
            'buttons' => 'yesno',
            'default' => 'no',
        ]) === 'yes';
    }

    public function alert(string $text, string $title = '提示'): void
    {
        $this->message(['type' => 'info', 'title' => $title, 'text' => $text, 'buttons' => 'ok']);
    }

    public function error(string $text, string $title = '错误'): void
    {
        $this->message(['type' => 'error', 'title' => $title, 'text' => $text, 'buttons' => 'ok']);
    }

    /** 打开文件选择框，取消返回空数组。无头模式下直接返回空数组。 */
    public function openFile(string $title = '打开文件', string $filter = ''): array
    {
        if ($this->headless) {
            return [];
        }
        return \qt_window_file_dialog($this->handle(), [
            'mode' => 'open',
            'title' => $title,
            'filter' => $filter,
        ]);
    }

    public function saveFile(string $title = '保存文件', string $filter = '', string $filename = ''): array
    {
        if ($this->headless) {
            return [];
        }
        return \qt_window_file_dialog($this->handle(), [
            'mode' => 'save',
            'title' => $title,
            'filter' => $filter,
            'filename' => $filename,
        ]);
    }

    public function pickDirectory(string $title = '选择目录'): string
    {
        if ($this->headless) {
            return '';
        }
        return \qt_window_directory_dialog($this->handle(), ['title' => $title]);
    }

    public function notify(string $title, string $message): void
    {
        \qt_window_notify($this->handle(), $title, $message);
    }

    // ── 剪贴板 ──

    public function clipboardRead(): string
    {
        return \qt_clipboard_read();
    }

    public function clipboardWrite(string $text): void
    {
        \qt_clipboard_write($text);
    }

    // ── 取值 ──

    /** 读取控件当前值；控件不存在返回 null。 */
    public function value(string $id): mixed
    {
        return \qt_window_widget_value($this->handle(), $id);
    }

    /** 读取控件字符串值，null 归一为空串。 */
    public function text(string $id): string
    {
        $value = $this->value($id);
        return is_string($value) ? $value : '';
    }

    public function checked(string $id): bool
    {
        return (bool) $this->value($id);
    }

    // ── 事件循环 ──

    /**
     * 运行事件循环，直到窗口关闭。
     *
     * 每帧顺序：泵 Qt 事件 → 分派给处理器（处理器改状态）→ 按新状态渲染。
     * 先处理再渲染，事件引起的状态变化在同一帧就可见，不需要等下一帧。
     *
     * $maxFrames > 0 时跑够帧数就返回（无头测试/截图用）。
     */
    public function run(int $maxFrames = 0): void
    {
        $this->running = true;
        $this->frames = 0;

        while ($this->running && \qt_window_is_open($this->handle())) {
            if ($maxFrames > 0 && $this->frames >= $maxFrames) {
                break;
            }
            $this->frames++;

            // 1. 先泵事件并分派，让处理器更新状态
            \qt_window_process_events($this->handle());
            $this->drainEvents();

            // 2. 再按最新状态渲染
            if ($this->pendingTree !== null) {
                \qt_window_render($this->handle(), $this->pendingTree);
                $this->pendingTree = null;
            }
            foreach ($this->views as $builder) {
                $tree = $builder();
                \qt_window_render($this->handle(), $tree);
            }
        }

        $this->running = false;
    }

    /** 跑固定帧数后退出，用于无头截图。 */
    public function runFrames(int $frames = 3): void
    {
        $this->run($frames);
    }

    public function stop(): void
    {
        $this->running = false;
    }

    public function close(): void
    {
        \qt_window_close($this->handle());
    }

    public function destroy(): void
    {
        if ($this->window === null) return;
        \qt_window_destroy($this->window);
        $this->window = null;
    }

    /** 渲染一帧并保存 PNG。 */
    public function snapshot(string $path): bool
    {
        return \qt_window_snapshot($this->handle(), $path);
    }

    public function frameCount(): int
    {
        return $this->frames;
    }

    // ── 内部 ──

    private function drainEvents(): void
    {
        while (true) {
            $event = \qt_window_poll_event($this->handle());
            if (empty($event)) {
                break;
            }
            $this->handleEvent($event);
        }
    }

    private function handleEvent(array $event): void
    {
        $id = (string) ($event['id'] ?? '');
        $type = (string) ($event['type'] ?? '');

        if ($id !== '' && isset($this->handlers[$id][$type])) {
            $this->safely($this->handlers[$id][$type], $event);
            return;
        }
        if (isset($this->handlers['*'][$type])) {
            $this->safely($this->handlers['*'][$type], $event);
        }
    }

    /**
     * 按注册时探测到的参数个数调用处理器。
     *
     * AOT 下闭包实参个数必须精确匹配，所以 0 参处理器不能再多传 $event。
     *
     * @param array{0: callable, 1: int} $entry
     */
    private function safely(array $entry, array $event): void
    {
        [$handler, $arity] = $entry;
        try {
            if ($arity <= 0) {
                $handler();
            } else {
                $handler($event);
            }
        } catch (\Throwable $e) {
            $this->lastError = $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine();
            $this->reportError($e);
        }
    }

    /**
     * 最近一次处理器异常的描述；没有异常时为空串。
     *
     * 供无头自检使用：错误框在无头环境看不见，需要能程序化读取。
     */
    public function lastError(): string
    {
        return $this->lastError;
    }

    /** 清空最近一次异常记录。 */
    public function clearError(): void
    {
        $this->lastError = '';
    }

    private function reportError(\Throwable $e): void
    {
        $message = $e->getMessage() . "\n" . $e->getFile() . ':' . $e->getLine();
        try {
            $this->message([
                'type' => 'error',
                'title' => '未捕获的异常',
                'text' => $message,
                'buttons' => 'ok',
            ]);
        } catch (\Throwable) {
            // 窗口已销毁时忽略
        }
    }
}
