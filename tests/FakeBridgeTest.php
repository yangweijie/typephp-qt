<?php

declare(strict_types=1);

namespace TypePHP\Qt\Tests;

use PHPUnit\Framework\TestCase;
use TypePHP\Qt\Fake\FakeState;

use function qt_app_create;
use function qt_bridge_version;
use function qt_clipboard_read;
use function qt_clipboard_write;
use function qt_window_close;
use function qt_window_create;
use function qt_window_destroy;
use function qt_window_is_open;
use function qt_window_message;
use function qt_window_notify;
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
use function test_reset;
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
}
