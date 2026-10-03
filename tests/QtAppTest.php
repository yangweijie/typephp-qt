<?php

declare(strict_types=1);

namespace TypePHP\Qt\Tests;

use PHPUnit\Framework\TestCase;
use TypePHP\Qt\QtApp;
use TypePHP\Qt\WidgetTree;

use function test_inject_event;
use function test_reset;
use function test_window;

/** 字符串 callable 测试用的计数器（放文件级，供函数名字符串引用）。 */
$GLOBALS['arity_probe_count'] = 0;

/** 零参数函数，用作字符串 callable。 */
function arity_probe_zero(): void
{
    $GLOBALS['arity_probe_count'] = ($GLOBALS['arity_probe_count'] ?? 0) + 1;
}

function arity_probe_count(): int
{
    return (int) ($GLOBALS['arity_probe_count'] ?? 0);
}

function arity_probe_reset(): void
{
    $GLOBALS['arity_probe_count'] = 0;
}

/**
 * QtApp 的领域逻辑测试。
 *
 * 全部走 FakeBridge 的纯 PHP 全局函数实现，不需要 Qt 或编译器。
 */
final class QtAppTest extends TestCase
{
    private QtApp $app;

    protected function setUp(): void
    {
        test_reset();
        $this->app = new QtApp();
        $this->app->create(['name' => 'TestApp']);
        $this->app->createWindow('Test', ['width' => 800, 'height' => 600]);
    }

    // ── 生命周期 ──

    public function testCreateWindowReturnsHandle(): void
    {
        $this->assertNotNull($this->app->handle());
        $this->assertTrue(qt_window_is_open($this->app->handle()));
    }

    public function testHandleRequired(): void
    {
        $fresh = new QtApp();
        $this->expectException(\RuntimeException::class);
        $fresh->handle();
    }

    public function testCloseMarksWindowClosed(): void
    {
        $this->app->close();
        $this->assertFalse(qt_window_is_open($this->app->handle()));
    }

    public function testIsOpenReflectsWindowLifecycle(): void
    {
        $this->assertTrue($this->app->isOpen());
        $this->app->close();
        $this->assertFalse($this->app->isOpen());
    }

    public function testIsOpenFalseBeforeAnyWindow(): void
    {
        $this->assertFalse((new QtApp())->isOpen(), '没建过窗口的实例不该抛异常');
    }

    public function testSecondWindowPumpsIndependently(): void
    {
        // 示例里的多窗口泵循环就是这个形状：每个 QtApp 只泵自己那个窗口。
        $extra = new QtApp();
        $extra->createWindow('副窗口', []);
        $extra->render(WidgetTree::vbox([WidgetTree::label('日志', ['id' => 'l1'])]));
        $extra->runFrames(1);

        $this->assertSame(1, $extra->frameCount());
        $this->assertTrue($extra->isOpen());
        $this->assertSame('日志', $extra->text('l1'));

        $extra->close();
        $this->assertFalse($extra->isOpen());
        $this->assertTrue($this->app->isOpen(), '关副窗口不该影响主窗口');
    }

    public function testTrayAndTimerEventsDispatch(): void
    {
        $seen = [];
        $this->app->setTray(['tooltip' => 't', 'visible' => true]);
        $this->app->setTimer('clock', 1000);
        // 托盘事件不带 id，只能由 onAny 接住。
        $this->app->onAny('tray', function () use (&$seen): void {
            $seen[] = 'tray';
        });
        $this->app->on('clock', 'timer', function () use (&$seen): void {
            $seen[] = 'timer';
        });

        $this->app->dispatch(['type' => 'tray']);
        $this->app->dispatch(['type' => 'timer', 'id' => 'clock']);

        $this->assertSame(['tray', 'timer'], $seen);
        $this->assertSame(1000, \TypePHP\Qt\Fake\FakeState::$timers[$this->app->handle()]['clock']);
    }

    /**
     * 托盘激活方式放在 $event['value'] 里（left / right / double / middle）。
     * Qt 的 QSystemTrayIcon 会区分这几种，桥接全部转给 PHP —— 只转发左击
     * 会让「右击没反应」看起来像框架不支持。
     */
    public function testTrayEventCarriesActivationKind(): void
    {
        $kinds = [];
        $this->app->onAny('tray', function (array $event) use (&$kinds): void {
            $kinds[] = (string) ($event['value'] ?? '');
        });

        foreach (['left', 'right', 'double', 'middle'] as $kind) {
            $this->app->dispatch(['type' => 'tray', 'value' => $kind]);
        }

        $this->assertSame(['left', 'right', 'double', 'middle'], $kinds);
    }

    /** 托盘右键菜单项走与菜单栏相同的 menu 事件，靠 id 区分。 */
    public function testTrayMenuItemsDispatchAsMenuEvents(): void
    {
        $fired = [];
        $this->app->setTray([
            'tooltip' => 't',
            'visible' => true,
            'menu' => [
                ['type' => 'item', 'id' => 'tray.show', 'text' => '显示主窗口'],
                ['type' => 'item', 'id' => 'tray.quit', 'text' => '退出'],
            ],
        ]);
        $this->app->on('tray.show', 'menu', function () use (&$fired): void {
            $fired[] = 'show';
        });
        $this->app->on('tray.quit', 'menu', function () use (&$fired): void {
            $fired[] = 'quit';
        });

        $this->app->dispatch(['type' => 'menu', 'id' => 'tray.show']);
        $this->app->dispatch(['type' => 'menu', 'id' => 'tray.quit']);

        $this->assertSame(['show', 'quit'], $fired);
        // 菜单 spec 原样存进假桥，便于断言「菜单确实被登记了」
        $tray = test_window($this->app->handle())['tray'];
        $this->assertCount(2, $tray['menu']);
    }

    public function testDestroyClearsHandle(): void
    {
        $this->app->destroy();
        $this->expectException(\RuntimeException::class);
        $this->app->handle();
    }

    // ── 渲染与取值 ──

    public function testRenderThenReadValue(): void
    {
        $this->app->render(WidgetTree::vbox([
            WidgetTree::label('Hello', ['id' => 'greeting']),
            WidgetTree::lineEdit('initial', ['id' => 'input']),
        ]));
        $this->app->run(1);

        $this->assertSame('Hello', $this->app->text('greeting'));
        $this->assertSame('initial', $this->app->text('input'));
    }

    public function testViewRebuildsEveryFrame(): void
    {
        $state = ['n' => 0];
        $this->app->view(function () use (&$state): array {
            return WidgetTree::vbox([
                WidgetTree::label('n=' . $state['n'], ['id' => 'counter']),
            ]);
        });

        $this->app->run(1);
        $this->assertSame('n=0', $this->app->text('counter'));

        $state['n'] = 7;
        $this->app->run(1);
        $this->assertSame('n=7', $this->app->text('counter'));
    }

    public function testUnknownWidgetValueIsNull(): void
    {
        $this->assertNull($this->app->value('nope'));
    }

    public function testTextNormalizesNullToEmptyString(): void
    {
        $this->assertSame('', $this->app->text('nope'));
    }

    public function testCheckedReadsBoolean(): void
    {
        $this->app->render(WidgetTree::vbox([
            WidgetTree::checkbox('On', true, ['id' => 'flag']),
        ]));
        $this->app->run(1);
        $this->assertTrue($this->app->checked('flag'));
    }

    // ── 事件 ──

    public function testEventHandlerReceivesEvent(): void
    {
        $seen = null;
        $this->app->on('btn', 'click', function ($event) use (&$seen) {
            $seen = $event;
        });

        test_inject_event($this->app->handle(), ['type' => 'click', 'id' => 'btn']);
        $this->app->run(1);

        $this->assertSame('btn', $seen['id']);
        $this->assertSame('click', $seen['type']);
    }

    public function testTypedEventParameterHandler(): void
    {
        $seen = null;
        $this->app->on('btn', 'click', function (array $event) use (&$seen) {
            $seen = $event['id'] ?? null;
        });

        test_inject_event($this->app->handle(), ['type' => 'click', 'id' => 'btn']);
        $this->app->run(1);

        $this->assertSame('btn', $seen);
    }

    /**
     * 零参数处理器不能被传入 $event。
     *
     * AOT 编译后的闭包对实参个数做精确校验（多传一个就抛 ArgumentCountError），
     * 而普通 PHP 会静默忽略多余实参 —— 所以这条必须显式断言，
     * 否则本地测试全绿、编译后一点按钮就崩。
     */
    public function testZeroArgHandlerIsCalledWithoutEvent(): void
    {
        $called = 0;
        $this->app->on('btn', 'click', function () use (&$called) {
            $called++;
        });

        test_inject_event($this->app->handle(), ['type' => 'click', 'id' => 'btn']);
        $this->app->run(1);

        $this->assertSame(1, $called);
    }

    public function testZeroArgWildcardHandler(): void
    {
        $called = 0;
        $this->app->onAny('click', function () use (&$called) {
            $called++;
        });

        test_inject_event($this->app->handle(), ['type' => 'click', 'id' => 'a']);
        test_inject_event($this->app->handle(), ['type' => 'click', 'id' => 'b']);
        $this->app->run(1);

        $this->assertSame(2, $called);
    }

    /** 方法数组形式的处理器也要能正确探测参数个数。 */
    public function testMethodArrayHandlerArity(): void
    {
        $controller = new class {
            public int $zeroCalls = 0;
            public ?string $lastId = null;

            public function noArgs(): void
            {
                $this->zeroCalls++;
            }

            public function withEvent(array $event): void
            {
                $this->lastId = $event['id'] ?? null;
            }
        };

        $this->app->on('a', 'click', [$controller, 'noArgs']);
        $this->app->on('b', 'click', [$controller, 'withEvent']);

        test_inject_event($this->app->handle(), ['type' => 'click', 'id' => 'a']);
        test_inject_event($this->app->handle(), ['type' => 'click', 'id' => 'b']);
        $this->app->run(1);

        $this->assertSame(1, $controller->zeroCalls);
        $this->assertSame('b', $controller->lastId);
    }

    /** 函数名字符串形式的处理器。 */
    public function testStringCallableHandler(): void
    {
        \TypePHP\Qt\Tests\arity_probe_reset();

        $this->app->on('btn', 'click', 'TypePHP\Qt\Tests\arity_probe_zero');
        test_inject_event($this->app->handle(), ['type' => 'click', 'id' => 'btn']);
        $this->app->run(1);

        $this->assertSame(1, \TypePHP\Qt\Tests\arity_probe_count());
    }

    public function testWildcardHandlerCatchesAllIds(): void
    {
        $ids = [];
        $this->app->onAny('click', function ($event) use (&$ids) {
            $ids[] = $event['id'];
        });

        test_inject_event($this->app->handle(), ['type' => 'click', 'id' => 'a']);
        test_inject_event($this->app->handle(), ['type' => 'click', 'id' => 'b']);
        $this->app->run(1);

        $this->assertSame(['a', 'b'], $ids);
    }

    /**
     * on() 与 onAny() 同时注册时**两个都要触发**（文档明确承诺）。
     *
     * 早先的实现是「特例命中就 return」，通配被静默吃掉 —— 于是一旦为某个 id
     * 注册过同类型处理器，全局监听就再也不工作（埋点/日志类代码会静默失效）。
     */
    public function testSpecificAndWildcardBothFire(): void
    {
        $log = [];
        $this->app->on('a', 'click', function () use (&$log) { $log[] = 'specific'; });
        $this->app->onAny('click', function () use (&$log) { $log[] = 'wildcard'; });

        test_inject_event($this->app->handle(), ['type' => 'click', 'id' => 'a']);
        $this->app->run(1);

        // 顺序：先特例，后通配
        $this->assertSame(['specific', 'wildcard'], $log);
    }

    /** 只有通配注册时，它当然要接住所有该类型事件。 */
    public function testWildcardStillFiresWithoutSpecificHandler(): void
    {
        $log = [];
        $this->app->onAny('click', function (array $event) use (&$log) { $log[] = $event['id']; });

        test_inject_event($this->app->handle(), ['type' => 'click', 'id' => 'x']);
        $this->app->run(1);

        $this->assertSame(['x'], $log);
    }

    /**
     * 桥接新增的控件信号（press/release/commit/cell/expand/collapse/close/itemClick）
     * 走的是同一套 {type,id,value,payload} 事件约定，所以都能被 on/onAny 接住。
     * 这里逐一验证分发不丢字段。
     */
    public function testExtendedControlSignalsDispatch(): void
    {
        $seen = [];
        $this->app->onAny('press', function (array $e) use (&$seen) { $seen[] = 'press:' . $e['id']; });
        $this->app->onAny('release', function (array $e) use (&$seen) { $seen[] = 'release:' . $e['id']; });
        $this->app->onAny('commit', function (array $e) use (&$seen) { $seen[] = 'commit:' . $e['value']; });
        $this->app->onAny('cell', function (array $e) use (&$seen) {
            $seen[] = 'cell:' . $e['payload']['row'] . ',' . $e['payload']['col'] . '=' . $e['value'];
        });
        $this->app->onAny('expand', function (array $e) use (&$seen) { $seen[] = 'expand:' . $e['value']; });
        $this->app->onAny('collapse', function (array $e) use (&$seen) { $seen[] = 'collapse:' . $e['value']; });
        $this->app->onAny('close', function (array $e) use (&$seen) { $seen[] = 'close:' . $e['payload']['index']; });
        $this->app->onAny('itemClick', function (array $e) use (&$seen) { $seen[] = 'itemClick:' . $e['value']; });

        $this->app->dispatch(['type' => 'press', 'id' => 'btn']);
        $this->app->dispatch(['type' => 'release', 'id' => 'btn']);
        $this->app->dispatch(['type' => 'commit', 'id' => 'edit', 'value' => 'hello']);
        $this->app->dispatch(['type' => 'cell', 'id' => 'tbl', 'value' => 'X', 'payload' => ['row' => 2, 'col' => 1]]);
        $this->app->dispatch(['type' => 'expand', 'id' => 'tree', 'value' => 'node1']);
        $this->app->dispatch(['type' => 'collapse', 'id' => 'tree', 'value' => 'node1']);
        $this->app->dispatch(['type' => 'close', 'id' => 'tabs', 'payload' => ['index' => 3]]);
        $this->app->dispatch(['type' => 'itemClick', 'id' => 'lst', 'value' => 'r7']);

        $this->assertSame([
            'press:btn',
            'release:btn',
            'commit:hello',
            'cell:2,1=X',
            'expand:node1',
            'collapse:node1',
            'close:3',
            'itemClick:r7',
        ], $seen);
    }

    public function testUnhandledEventIsIgnored(): void
    {
        $this->app->on('other', 'click', function () {
            $this->fail('不应被调用');
        });

        test_inject_event($this->app->handle(), ['type' => 'click', 'id' => 'unknown']);
        $this->app->run(1);

        $this->addToAssertionCount(1);
    }

    public function testThrowingHandlerDoesNotKillLoop(): void
    {
        $reached = false;
        $this->app->on('bad', 'click', function () {
            throw new \RuntimeException('boom');
        });
        $this->app->on('good', 'click', function () use (&$reached) {
            $reached = true;
        });

        test_inject_event($this->app->handle(), ['type' => 'click', 'id' => 'bad']);
        test_inject_event($this->app->handle(), ['type' => 'click', 'id' => 'good']);
        $this->app->run(1);

        $this->assertTrue($reached, '一个回调抛异常不应阻断后续事件');
    }

    public function testThrowingHandlerShowsErrorBox(): void
    {
        $this->app->on('bad', 'click', function () {
            throw new \RuntimeException('boom');
        });
        test_inject_event($this->app->handle(), ['type' => 'click', 'id' => 'bad']);
        $this->app->run(1);

        $messages = test_window($this->app->handle())['messages'] ?? [];
        $this->assertNotEmpty($messages);
        $this->assertSame('error', $messages[0]['type']);
        $this->assertStringContainsString('boom', $messages[0]['text']);
    }

    public function testDispatchWithoutRunningLoop(): void
    {
        $called = false;
        $this->app->on('btn', 'click', function () use (&$called) { $called = true; });
        $this->app->dispatch(['type' => 'click', 'id' => 'btn']);
        $this->assertTrue($called);
    }

    // ── 状态驱动闭环 ──

    public function testEventChangesStateThenViewReflectsIt(): void
    {
        $state = ['count' => 0];

        $this->app->view(function () use (&$state): array {
            return WidgetTree::vbox([
                WidgetTree::label('count=' . $state['count'], ['id' => 'counter']),
                WidgetTree::button('+1', ['id' => 'inc']),
            ]);
        });
        $this->app->on('inc', 'click', function () use (&$state) {
            $state['count']++;
        });

        $this->app->run(1);
        $this->assertSame('count=0', $this->app->text('counter'));

        test_inject_event($this->app->handle(), ['type' => 'click', 'id' => 'inc']);
        $this->app->run(1);
        $this->assertSame('count=1', $this->app->text('counter'));
    }

    // ── 窗口装饰 ──

    public function testSetTitle(): void
    {
        $this->app->setTitle('New Title');
        $this->assertSame('New Title', test_window($this->app->handle())['title']);
    }

    public function testSetStatus(): void
    {
        $this->app->setStatus(['Ready', 'v1.0']);
        $this->assertSame(['Ready', 'v1.0'], test_window($this->app->handle())['status']);
    }

    public function testSetMenu(): void
    {
        $this->app->setMenu([['type' => 'item', 'id' => 'file', 'text' => 'File']]);
        $menu = test_window($this->app->handle())['menu'];
        $this->assertSame('File', $menu[0]['text']);
    }

    public function testResize(): void
    {
        $this->app->resize(1024, 768);
        $win = test_window($this->app->handle());
        $this->assertSame(1024, $win['width']);
        $this->assertSame(768, $win['height']);
    }

    public function testSetTimer(): void
    {
        $this->app->setTimer('tick', 250);
        $this->assertSame(250, \TypePHP\Qt\Fake\FakeState::$timers[$this->app->handle()]['tick']);
    }

    public function testSetTimerZeroCancels(): void
    {
        $this->app->setTimer('tick', 250);
        $this->app->setTimer('tick', 0);
        $this->assertArrayNotHasKey('tick', \TypePHP\Qt\Fake\FakeState::$timers[$this->app->handle()]);
    }

    public function testSetTray(): void
    {
        $this->app->setTray(['tooltip' => 'MyApp', 'visible' => true]);
        $this->assertSame('MyApp', test_window($this->app->handle())['tray']['tooltip']);
    }

    // ── 无头模式 ──

    public function testHeadlessIsOffByDefault(): void
    {
        $this->assertFalse($this->app->isHeadless());
    }

    public function testHeadlessCanBeToggled(): void
    {
        $this->app->headless(true);
        $this->assertTrue($this->app->isHeadless());

        $this->app->headless(false);
        $this->assertFalse($this->app->isHeadless());
    }

    /** 无头模式下消息框不弹窗，直接返回 default —— 否则 CI 会永久阻塞。 */
    public function testHeadlessMessageReturnsDefaultWithoutDialog(): void
    {
        $this->app->headless(true);
        $result = $this->app->message(['type' => 'error', 'default' => 'cancel']);

        $this->assertSame('cancel', $result);
        // 假桥的 messages 记录不应增加，说明根本没走对话框
        $messages = test_window($this->app->handle())['messages'] ?? [];
        $this->assertSame([], $messages);
    }

    public function testHeadlessConfirmUsesDefault(): void
    {
        $this->app->headless(true);
        $this->assertFalse($this->app->confirm('确定吗？'));
    }

    public function testHeadlessFileDialogsReturnEmpty(): void
    {
        $this->app->headless(true);
        $this->assertSame([], $this->app->openFile());
        $this->assertSame([], $this->app->saveFile());
        $this->assertSame('', $this->app->pickDirectory());
    }

    /** 非无头模式下仍走真实桥接（假桥会记录调用）。 */
    public function testNonHeadlessMessageGoesToBridge(): void
    {
        $this->app->headless(false);
        $this->app->message(['type' => 'info', 'default' => 'ok']);

        $messages = test_window($this->app->handle())['messages'] ?? [];
        $this->assertCount(1, $messages);
    }

    // ── 错误读取 ──

    public function testLastErrorIsEmptyInitially(): void
    {
        $this->assertSame('', $this->app->lastError());
    }

    public function testLastErrorRecordsHandlerFailure(): void
    {
        $this->app->headless(true);
        $this->app->on('bad', 'click', function () {
            throw new \RuntimeException('boom');
        });

        $this->app->dispatch(['type' => 'click', 'id' => 'bad']);

        $this->assertStringContainsString('boom', $this->app->lastError());
    }

    public function testClearErrorResets(): void
    {
        $this->app->headless(true);
        $this->app->on('bad', 'click', function () {
            throw new \RuntimeException('boom');
        });
        $this->app->dispatch(['type' => 'click', 'id' => 'bad']);

        $this->app->clearError();
        $this->assertSame('', $this->app->lastError());
    }

    // ── 对话框 ──

    public function testMessageReturnsDefaultButton(): void
    {
        $result = $this->app->message(['type' => 'info', 'default' => 'ok']);
        $this->assertSame('ok', $result);
    }

    public function testConfirmReturnsFalseWhenDefaultNo(): void
    {
        $this->assertFalse($this->app->confirm('确定吗？'));
    }

    public function testAlertRecordsMessage(): void
    {
        $this->app->alert('注意', '标题');
        $messages = test_window($this->app->handle())['messages'];
        $this->assertSame('info', $messages[0]['type']);
        $this->assertSame('注意', $messages[0]['text']);
    }

    public function testOpenFileReturnsEmptyOnCancel(): void
    {
        $this->assertSame([], $this->app->openFile());
    }

    public function testNotify(): void
    {
        $this->app->notify('标题', '内容');
        $notes = test_window($this->app->handle())['notifications'];
        $this->assertSame('标题', $notes[0]['title']);
    }

    // ── 剪贴板 ──

    public function testClipboardRoundTrip(): void
    {
        $this->app->clipboardWrite('hello');
        $this->assertSame('hello', $this->app->clipboardRead());
    }

    // ── webview ──

    /**
     * 测试替身默认报 textbrowser —— 它跑在普通 PHP 上、没有 Qt，
     * 报 "webview2" 会骗过应用的能力判断（以为能用 JS）。
     */
    public function testWebViewBackendDefaultsToTextBrowserInTests(): void
    {
        $this->assertSame('textbrowser', $this->app->webViewBackend());
        $this->assertFalse($this->app->webViewSupportsJs());
    }

    /** 覆盖后端名后，JS 能力判断要跟着变（应用据此走不同分支）。 */
    public function testWebViewBackendCanBeOverridden(): void
    {
        test_set_webview_backend('webview2');
        $this->assertSame('webview2', $this->app->webViewBackend());
        $this->assertTrue($this->app->webViewSupportsJs());

        test_set_webview_backend('wkwebview');
        $this->assertSame('wkwebview', $this->app->webViewBackend());
        $this->assertTrue($this->app->webViewSupportsJs());
    }

    /**
     * 三个后端与 JS 能力的对应关系钉死。
     *
     * 这张表必须与 `cpp-src/qt_webview.cc` 的 `qtWebViewSupportsJs()` 一致：
     * 应用侧只按 `webViewSupportsJs()` 分支，替身报错了就会在真 Qt 上走空。
     */
    public function testWebViewJsCapabilityMatrix(): void
    {
        $matrix = ['webview2' => true, 'wkwebview' => true, 'textbrowser' => false];
        foreach ($matrix as $backend => $supportsJs) {
            test_set_webview_backend($backend);
            $this->assertSame($backend, $this->app->webViewBackend());
            $this->assertSame(
                $supportsJs,
                $this->app->webViewSupportsJs(),
                "backend={$backend} 的 JS 能力与契约不符"
            );
        }
    }

    public function testWebViewRendersIntoTree(): void
    {
        $this->app->render(WidgetTree::vbox([
            WidgetTree::webView('https://example.com', ['id' => 'wv']),
        ]));
        $this->app->run(1);

        $this->assertSame('https://example.com', test_props($this->app->handle(), 'wv')['url']);
    }

    // ── 补丁 ──

    public function testPatchUpdatesProps(): void
    {
        $this->app->render(WidgetTree::vbox([
            WidgetTree::label('before', ['id' => 'lbl']),
        ]));
        $this->app->run(1);

        $this->app->patch([['op' => 'set', 'id' => 'lbl', 'props' => ['text' => 'after']]]);
        $this->assertSame('after', $this->app->text('lbl'));
    }

    public function testPatchCallAppendRowsKeepsExistingRows(): void
    {
        $this->app->render(WidgetTree::vbox([
            WidgetTree::table(['名称'], [['甲']], ['id' => 'tbl', 'row_ids' => ['r1']]),
        ]));
        $this->app->run(1);

        $this->app->patch([[
            'op' => 'call', 'id' => 'tbl', 'method' => 'appendRows',
            'args' => [[['乙'], ['丙']], ['r2', 'r3']],
        ]]);

        $table = \TypePHP\Qt\Fake\FakeState::$props[$this->app->handle()]['tbl'];
        $this->assertSame([['甲'], ['乙'], ['丙']], $table['rows']);
        $this->assertSame(['r1', 'r2', 'r3'], $table['row_ids']);
    }

    public function testPatchCallClearEmptiesByType(): void
    {
        $this->app->render(WidgetTree::vbox([
            WidgetTree::table(['名称'], [['甲']], ['id' => 'tbl', 'row_ids' => ['r1']]),
            WidgetTree::label('文本', ['id' => 'lbl']),
        ]));
        $this->app->run(1);

        $this->app->patch([
            ['op' => 'call', 'id' => 'tbl', 'method' => 'clear'],
            ['op' => 'call', 'id' => 'lbl', 'method' => 'clear'],
        ]);

        $props = \TypePHP\Qt\Fake\FakeState::$props[$this->app->handle()];
        $this->assertSame([], $props['tbl']['rows'], '表格 clear 走 rows');
        $this->assertSame('', $props['lbl']['text'], 'label clear 走 text');
    }

    public function testPatchCallSetTextAndValue(): void
    {
        $this->app->render(WidgetTree::vbox([
            WidgetTree::label('before', ['id' => 'lbl']),
            WidgetTree::lineEdit('old', ['id' => 'in']),
            WidgetTree::progress(10, ['id' => 'pg']),
        ]));
        $this->app->run(1);

        $this->app->patch([
            ['op' => 'call', 'id' => 'lbl', 'method' => 'setText', 'args' => ['after']],
            ['op' => 'call', 'id' => 'in', 'method' => 'setValue', 'args' => ['typed']],
            ['op' => 'call', 'id' => 'pg', 'method' => 'setValue', 'args' => [66]],
        ]);

        $this->assertSame('after', $this->app->text('lbl'));
        $this->assertSame('typed', $this->app->text('in'));
        $this->assertSame(66, $this->app->value('pg'));
    }

    public function testPatchCallSelectAndFocus(): void
    {
        $this->app->render(WidgetTree::vbox([
            WidgetTree::table(['名称'], [['甲'], ['乙']], ['id' => 'tbl', 'row_ids' => ['r1', 'r2']]),
            WidgetTree::lineEdit('x', ['id' => 'in']),
        ]));
        $this->app->run(1);

        $this->app->patch([
            ['op' => 'call', 'id' => 'tbl', 'method' => 'select', 'args' => ['r2']],
            ['op' => 'call', 'id' => 'in', 'method' => 'focus'],
        ]);

        $props = \TypePHP\Qt\Fake\FakeState::$props[$this->app->handle()];
        $this->assertSame('r2', $props['tbl']['current'], 'select 写进 current，与声明式同义');
        $this->assertTrue($props['in']['focused']);
        $this->assertSame('x', $this->app->text('in'), 'focus 不该改值');
    }

    public function testPatchCallIgnoresUnknownMethodAndId(): void
    {
        $this->app->render(WidgetTree::vbox([WidgetTree::label('keep', ['id' => 'lbl'])]));
        $this->app->run(1);

        $this->app->patch([
            ['op' => 'call', 'id' => 'lbl', 'method' => 'noSuchMethod', 'args' => ['x']],
            ['op' => 'call', 'id' => 'ghost', 'method' => 'setText', 'args' => ['x']],
            ['op' => 'call', 'id' => 'lbl'],
        ]);

        $this->assertSame('keep', $this->app->text('lbl'));
    }

    // ── 截图 ──

    public function testSnapshotRecordsPath(): void
    {
        $this->assertTrue($this->app->snapshot('shot.png'));
        $this->assertContains('shot.png', \TypePHP\Qt\Fake\FakeState::$snapshots);
    }

    // ── 帧数控制 ──

    public function testRunMaxFramesStopsEarly(): void
    {
        $this->app->run(3);
        $this->assertSame(3, $this->app->frameCount());
    }

    public function testRunFramesHelper(): void
    {
        $this->app->runFrames(2);
        $this->assertSame(2, $this->app->frameCount());
    }
}
