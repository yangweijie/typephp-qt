<?php

declare(strict_types=1);

namespace TypePHP\Qt\Tests;

use PHPUnit\Framework\TestCase;
use TypePHP\Qt\Fake\FakeState;

use function qt_app_create;
use function qt_bridge_version;
use function qt_clipboard_read;
use function qt_clipboard_write;
use function qt_qml_available;
use function qt_qml_status;
use function qt_window_close;
use function qt_window_create;
use function qt_window_destroy;
use function qt_window_is_open;
use function qt_window_message;
use function qt_window_notify;
use function qt_window_patch;
use function qt_window_poll_event;
use function qt_window_render;
use function qt_window_set_status;
use function qt_window_set_timer;
use function qt_window_set_title;
use function qt_window_set_tray;
use function qt_window_snapshot;
use function qt_window_widget_value;
use function test_inject_event;
use function test_props;
use function test_qml_calls;
use function test_reset;
use function test_set_qml_available;
use function test_set_qml_status;
use function test_window;

/**
 * FakeBridge 自身行为测试 —— 保证测试替身与桥接契约一致。
 *
 * 这层测试保护的是"测试基础设施"：如果替身行为漂移，
 * 上层的领域测试就会给出假绿。
 */
final class FakeBridgeTest extends TestCase
{
    private string $window;

    protected function setUp(): void
    {
        test_reset();
        qt_app_create(['name' => 'TestApp']);
        $this->window = qt_window_create('Test Window');
    }

    public function testBridgeVersion(): void
    {
        $this->assertStringStartsWith('fake-', qt_bridge_version());
    }

    public function testWindowCreateReturnsHandle(): void
    {
        $this->assertNotEmpty($this->window);
        $this->assertTrue(qt_window_is_open($this->window));
    }

    public function testWindowHandlesAreUnique(): void
    {
        $second = qt_window_create('Second');
        $this->assertNotSame($this->window, $second);
    }

    public function testClose(): void
    {
        qt_window_close($this->window);
        $this->assertFalse(qt_window_is_open($this->window));
    }

    public function testDestroy(): void
    {
        qt_window_destroy($this->window);
        $this->assertFalse(qt_window_is_open($this->window));
        $this->assertSame([], test_window($this->window));
    }

    public function testRenderPopulatesWidgetValues(): void
    {
        qt_window_render($this->window, [
            'type' => 'vbox',
            'children' => [
                ['type' => 'label', 'id' => 'greeting', 'text' => 'Hello'],
                ['type' => 'lineedit', 'id' => 'input', 'text' => 'initial'],
                ['type' => 'spin', 'id' => 'count', 'value' => 7],
                ['type' => 'checkbox', 'id' => 'flag', 'checked' => true],
            ],
        ]);

        $this->assertSame('Hello', qt_window_widget_value($this->window, 'greeting'));
        $this->assertSame('initial', qt_window_widget_value($this->window, 'input'));
        $this->assertSame(7, qt_window_widget_value($this->window, 'count'));
        $this->assertTrue(qt_window_widget_value($this->window, 'flag'));
    }

    public function testRenderRecordsTree(): void
    {
        qt_window_render($this->window, ['type' => 'vbox', 'id' => 'root']);
        $this->assertCount(1, FakeState::$renders);
    }

    public function testUnknownWidgetValueIsNull(): void
    {
        $this->assertNull(qt_window_widget_value($this->window, 'missing'));
    }

    public function testEventQueueIsFifo(): void
    {
        test_inject_event($this->window, ['type' => 'click', 'id' => 'first']);
        test_inject_event($this->window, ['type' => 'click', 'id' => 'second']);

        $this->assertSame('first', qt_window_poll_event($this->window)['id']);
        $this->assertSame('second', qt_window_poll_event($this->window)['id']);
    }

    public function testPollEmptyQueueReturnsEmptyArray(): void
    {
        $this->assertSame([], qt_window_poll_event($this->window));
    }

    public function testSetTitle(): void
    {
        qt_window_set_title($this->window, 'Renamed');
        $this->assertSame('Renamed', test_window($this->window)['title']);
    }

    public function testSetStatus(): void
    {
        qt_window_set_status($this->window, ['A', 'B']);
        $this->assertSame(['A', 'B'], test_window($this->window)['status']);
    }

    public function testSetTimerRegistersAndCancels(): void
    {
        qt_window_set_timer($this->window, 'tick', 100);
        $this->assertSame(100, FakeState::$timers[$this->window]['tick']);

        qt_window_set_timer($this->window, 'tick', 0);
        $this->assertArrayNotHasKey('tick', FakeState::$timers[$this->window]);
    }

    public function testSetTray(): void
    {
        qt_window_set_tray($this->window, ['tooltip' => 'App']);
        $this->assertSame('App', test_window($this->window)['tray']['tooltip']);
    }

    public function testMessageReturnsDefaultAndRecords(): void
    {
        $result = qt_window_message($this->window, ['type' => 'question', 'default' => 'yes']);
        $this->assertSame('yes', $result);
        $this->assertCount(1, test_window($this->window)['messages']);
    }

    public function testMessageDefaultsToOk(): void
    {
        $this->assertSame('ok', qt_window_message($this->window, ['type' => 'info']));
    }

    public function testNotifyRecords(): void
    {
        qt_window_notify($this->window, 'T', 'M');
        $notes = test_window($this->window)['notifications'];
        $this->assertSame(['title' => 'T', 'message' => 'M'], $notes[0]);
    }

    public function testClipboard(): void
    {
        qt_clipboard_write('text');
        $this->assertSame('text', qt_clipboard_read());
    }

    public function testSnapshotRecordsPath(): void
    {
        $this->assertTrue(qt_window_snapshot($this->window, 'a.png'));
        $this->assertContains('a.png', FakeState::$snapshots);
    }

    public function testResetClearsEverything(): void
    {
        qt_clipboard_write('x');
        qt_window_render($this->window, ['type' => 'label', 'id' => 'l', 'text' => 'v']);
        test_reset();

        $this->assertSame('', qt_clipboard_read());
        $this->assertNull(qt_window_widget_value($this->window, 'l'));
        $this->assertSame([], FakeState::$renders);
    }

    public function testPropsRecordedForInspection(): void
    {
        qt_window_render($this->window, [
            'type' => 'label',
            'id' => 'lbl',
            'text' => 'Hello',
            'style' => 'color:red',
        ]);
        $props = test_props($this->window, 'lbl');
        $this->assertSame('color:red', $props['style']);
    }

    // ── qml ──

    /** qmlAvailable 默认 false（与未定义 QT_QML 的构建同口径）。 */
    public function testQmlAvailableDefaultsToFalse(): void
    {
        $this->assertFalse(qt_qml_available());
    }

    /** 覆盖能力位后翻转。 */
    public function testQmlAvailableCanBeOverridden(): void
    {
        test_set_qml_available(true);
        $this->assertTrue(qt_qml_available());
    }

    /** qmlStatus 默认结构：没渲染过 qml 节点时是 []。 */
    public function testQmlStatusEmptyForUnknownControl(): void
    {
        $this->assertSame([], qt_qml_status($this->window, 'missing'));
    }

    /** qmlStatus 默认结构：渲染过 qml 节点但没预置状态时，从渲染树推导。 */
    public function testQmlStatusDerivedFromRenderedNode(): void
    {
        qt_window_render($this->window, [
            'type' => 'vbox',
            'children' => [
                ['type' => 'qml', 'id' => 'clock', 'source' => 'assets/clock.qml', 'signals' => ['tick']],
            ],
        ]);
        $status = qt_qml_status($this->window, 'clock');
        $this->assertSame('loading', $status['status']);
        $this->assertSame([], $status['errors']);
    }

    /** qmlStatus 预置后返回预置值（window+id 双键，跨窗口不串状态）。 */
    public function testQmlStatusPreseeded(): void
    {
        qt_window_render($this->window, ['type' => 'vbox', 'children' => [['type' => 'qml', 'id' => 'c']]]);
        $other = qt_window_create('Other');
        test_set_qml_status($this->window, 'c', ['status' => 'error', 'errors' => [['line' => 1, 'message' => 'bad']]]);
        $this->assertSame('error', qt_qml_status($this->window, 'c')['status']);
        $this->assertSame([], qt_qml_status($other, 'c'));
    }

    /** qml 节点的 value() 返回 read 清单 ∩ props 映射的子集（与真桥 qtQmlWidgetValueImpl 只迭代 readKeysList 同语义）。 */
    public function testQmlValueReadsBackReadKeys(): void
    {
        // props 里**故意多带一个 read 未声明的键**（frame 是 QML 侧自管的属性）：
        // 真桥只读 read 清单，这个键必须被滤掉 —— 修前 FakeBridge 返回全部 props，
        // 旧断言因 props 恰好只含 read 键才通过，属巧合非真镜像。
        qt_window_render($this->window, [
            'type' => 'vbox',
            'children' => [
                ['type' => 'qml', 'id' => 'c',
                 'props' => ['timeText' => '09:09:09', 'frame' => 3],
                 'read' => ['timeText']],
            ],
        ]);
        $value = qt_window_widget_value($this->window, 'c');
        $this->assertSame(['timeText' => '09:09:09'], $value);
    }

    /** qml 节点没声明 read 时 value() 是空数组（真桥 readKeysList 为空 → 快照为空对象）。 */
    public function testQmlValueWithoutReadIsEmpty(): void
    {
        qt_window_render($this->window, [
            'type' => 'vbox',
            'children' => [
                ['type' => 'qml', 'id' => 'c', 'props' => ['timeText' => '09:09:09']],
            ],
        ]);
        $this->assertSame([], qt_window_widget_value($this->window, 'c'));
    }

    /** qml 的 reload 动作记进 qmlCalls 轨迹（与 webviewCalls 同构）。 */
    public function testQmlReloadRecordsCall(): void
    {
        qt_window_render($this->window, [
            'type' => 'vbox',
            'children' => [['type' => 'qml', 'id' => 'c', 'source' => 'a.qml']],
        ]);
        qt_window_patch($this->window, [['op' => 'call', 'id' => 'c', 'method' => 'reload']]);
        $calls = test_qml_calls();
        $this->assertSame([['window' => $this->window, 'id' => 'c', 'method' => 'reload']], $calls);
    }

    /**
     * qml 的 value() 快照按「当前已记录的 props 映射」推导（read ∩ props，
     * 与真桥 setProperty 后读回同语义）：下一帧重新渲染时 props 被刷新，读回跟着变。
     * patch set 不走 qml 的 props 键（真桥 qtQmlApplyProp 是 qml 四键专用通道，
     * patch 通道只有 reload）——这是假桥的诚实边界，不做模拟。
     */
    public function testQmlValueReflectsRenderedProps(): void
    {
        qt_window_render($this->window, [
            'type' => 'vbox',
            'children' => [
                ['type' => 'qml', 'id' => 'c', 'props' => ['timeText' => '09:09:09'], 'read' => ['timeText']],
            ],
        ]);
        qt_window_render($this->window, [
            'type' => 'vbox',
            'children' => [
                ['type' => 'qml', 'id' => 'c', 'props' => ['timeText' => '10:00:00'], 'read' => ['timeText']],
            ],
        ]);
        $this->assertSame(['timeText' => '10:00:00'], qt_window_widget_value($this->window, 'c'));
    }

    /** reset() 清掉新字段（不泄漏到下一个用例）。 */
    public function testResetClearsQmlState(): void
    {
        test_set_qml_available(true);
        qt_window_render($this->window, [
            'type' => 'vbox',
            'children' => [['type' => 'qml', 'id' => 'c', 'source' => 'a.qml']],
        ]);
        test_set_qml_status($this->window, 'c', ['status' => 'ready', 'errors' => []]);
        qt_window_patch($this->window, [['op' => 'call', 'id' => 'c', 'method' => 'reload']]);

        test_reset();

        $this->assertFalse(qt_qml_available());
        $this->assertSame([], FakeState::$qmlStatus);
        $this->assertSame([], FakeState::$qmlCalls);
        $this->assertSame([], qt_qml_status($this->window, 'c'));
    }
}
