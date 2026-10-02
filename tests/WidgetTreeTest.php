<?php

declare(strict_types=1);

namespace TypePHP\Qt\Tests;

use PHPUnit\Framework\TestCase;
use TypePHP\Qt\WidgetTree;

use function test_reset;

/**
 * WidgetTree 构建器测试 —— 验证生成的节点数组结构符合桥接契约。
 */
final class WidgetTreeTest extends TestCase
{
    protected function setUp(): void
    {
        test_reset();
    }

    // ── 容器 ──

    public function testVbox(): void
    {
        $node = WidgetTree::vbox([
            WidgetTree::label('A'),
            WidgetTree::label('B'),
        ], ['id' => 'root']);
        $this->assertSame('vbox', $node['type']);
        $this->assertSame('root', $node['id']);
        $this->assertCount(2, $node['children']);
        $this->assertSame('A', $node['children'][0]['text']);
    }

    public function testHbox(): void
    {
        $node = WidgetTree::hbox([WidgetTree::button('OK')]);
        $this->assertSame('hbox', $node['type']);
        $this->assertCount(1, $node['children']);
    }

    public function testGridCarriesRowCol(): void
    {
        $node = WidgetTree::grid([
            WidgetTree::label('A', ['row' => 0, 'col' => 0]),
            WidgetTree::label('B', ['row' => 0, 'col' => 1]),
        ]);
        $this->assertSame(0, $node['children'][0]['row']);
        $this->assertSame(1, $node['children'][1]['col']);
    }

    public function testFormCarriesLabel(): void
    {
        $node = WidgetTree::form([
            WidgetTree::lineEdit('', ['id' => 'name', 'label' => 'Name']),
        ]);
        $this->assertSame('Name', $node['children'][0]['label']);
    }

    public function testGroupCarriesTitle(): void
    {
        $node = WidgetTree::group('Settings', [
            WidgetTree::checkbox('Enable', true, ['id' => 'enable']),
        ]);
        $this->assertSame('group', $node['type']);
        $this->assertSame('Settings', $node['title']);
    }

    public function testTabsExpandsPagesToTabNodes(): void
    {
        $node = WidgetTree::tabs([
            'Page 1' => [WidgetTree::label('Content 1')],
            'Page 2' => [WidgetTree::label('Content 2')],
        ]);
        $this->assertSame('tabs', $node['type']);
        $this->assertCount(2, $node['children']);
        $this->assertSame('tab', $node['children'][0]['type']);
        $this->assertSame('Page 1', $node['children'][0]['title']);
    }

    public function testStackExpandsToPageNodes(): void
    {
        $node = WidgetTree::stack([
            [WidgetTree::label('A')],
            [WidgetTree::label('B')],
        ]);
        $this->assertSame('page', $node['children'][0]['type']);
        $this->assertCount(2, $node['children']);
    }

    public function testSplit(): void
    {
        $node = WidgetTree::split([
            WidgetTree::label('Left'),
            WidgetTree::label('Right'),
        ]);
        $this->assertSame('split', $node['type']);
        $this->assertCount(2, $node['children']);
    }

    public function testScroll(): void
    {
        $node = WidgetTree::scroll([WidgetTree::label('Content')]);
        $this->assertSame('scroll', $node['type']);
        $this->assertCount(1, $node['children']);
    }

    public function testFrame(): void
    {
        $node = WidgetTree::frame([WidgetTree::label('Inside')]);
        $this->assertSame('frame', $node['type']);
    }

    public function testSpacerCarriesSize(): void
    {
        $node = WidgetTree::spacer(20);
        $this->assertSame('spacer', $node['type']);
        $this->assertSame(20, $node['size']);
    }

    public function testSeparator(): void
    {
        $node = WidgetTree::separator();
        $this->assertSame('separator', $node['type']);
    }

    // ── 控件 ──

    public function testLabel(): void
    {
        $node = WidgetTree::label('Hello', ['id' => 'greeting']);
        $this->assertSame('label', $node['type']);
        $this->assertSame('Hello', $node['text']);
        $this->assertSame('greeting', $node['id']);
    }

    public function testButton(): void
    {
        $node = WidgetTree::button('Click', ['id' => 'btn']);
        $this->assertSame('button', $node['type']);
        $this->assertSame('Click', $node['text']);
    }

    public function testLineEdit(): void
    {
        $node = WidgetTree::lineEdit('initial', ['id' => 'input']);
        $this->assertSame('lineedit', $node['type']);
        $this->assertSame('initial', $node['text']);
    }

    public function testTextEdit(): void
    {
        $node = WidgetTree::textEdit("multi\nline", ['id' => 'editor']);
        $this->assertSame('textedit', $node['type']);
        $this->assertSame("multi\nline", $node['text']);
    }

    public function testSpin(): void
    {
        $node = WidgetTree::spin(42, ['id' => 'count']);
        $this->assertSame('spin', $node['type']);
        $this->assertSame(42, $node['value']);
    }

    public function testDoubleSpin(): void
    {
        $node = WidgetTree::doubleSpin(3.14, ['id' => 'ratio']);
        $this->assertSame('doublespin', $node['type']);
        $this->assertSame(3.14, $node['value']);
    }

    public function testSlider(): void
    {
        $node = WidgetTree::slider(50, ['id' => 'volume']);
        $this->assertSame('slider', $node['type']);
        $this->assertSame(50, $node['value']);
    }

    public function testProgress(): void
    {
        $node = WidgetTree::progress(75, ['id' => 'pct']);
        $this->assertSame('progress', $node['type']);
        $this->assertSame(75, $node['value']);
    }

    public function testProgressAcceptsMaxProp(): void
    {
        $node = WidgetTree::progress(30, ['id' => 'pct', 'max' => 200]);
        $this->assertSame(200, $node['max']);
    }

    public function testCheckbox(): void
    {
        $node = WidgetTree::checkbox('Enable', true, ['id' => 'enable']);
        $this->assertSame('checkbox', $node['type']);
        $this->assertTrue($node['checked']);
    }

    public function testRadio(): void
    {
        $node = WidgetTree::radio('Option A', false, ['id' => 'opt']);
        $this->assertSame('radio', $node['type']);
        $this->assertFalse($node['checked']);
    }

    public function testCombo(): void
    {
        $node = WidgetTree::combo(['a', 'b', 'c'], 'b', ['id' => 'choice']);
        $this->assertSame(['a', 'b', 'c'], $node['items']);
        $this->assertSame('b', $node['value']);
    }

    public function testComboAcceptsRichItems(): void
    {
        $node = WidgetTree::combo([['id' => 'a', 'text' => 'Apple']], 'a', ['id' => 'choice']);
        $this->assertSame('Apple', $node['items'][0]['text']);
    }

    public function testList(): void
    {
        $node = WidgetTree::list(['x', 'y'], 'x', ['id' => 'items']);
        $this->assertSame(['x', 'y'], $node['items']);
    }

    public function testTable(): void
    {
        $node = WidgetTree::table(
            ['Name', 'Age'],
            [['Alice', 30], ['Bob', 25]],
            ['id' => 'data']
        );
        $this->assertSame('table', $node['type']);
        $this->assertSame(['Name', 'Age'], $node['columns']);
        $this->assertCount(2, $node['rows']);
    }

    public function testTableAcceptsRowIds(): void
    {
        $node = WidgetTree::table(
            ['Name'],
            [['Alice']],
            ['id' => 'data', 'row_ids' => ['u1']]
        );
        $this->assertSame(['u1'], $node['row_ids']);
    }

    public function testTree(): void
    {
        $node = WidgetTree::tree([
            ['text' => 'Root', 'id' => 'r', 'children' => [
                ['text' => 'Child', 'id' => 'c'],
            ]],
        ], ['id' => 'tree']);
        $this->assertSame('tree', $node['type']);
        $this->assertSame('Root', $node['nodes'][0]['text']);
    }

    public function testImage(): void
    {
        $node = WidgetTree::image('/path/to/img.png', ['id' => 'pic']);
        $this->assertSame('image', $node['type']);
        $this->assertSame('/path/to/img.png', $node['path']);
    }

    public function testLink(): void
    {
        $node = WidgetTree::link('Click here', 'https://example.com', ['id' => 'link']);
        $this->assertSame('link', $node['type']);
        $this->assertSame('https://example.com', $node['href']);
    }

    // ── 通用属性透传 ──

    public function testPropsPassThrough(): void
    {
        $node = WidgetTree::label('x', [
            'id' => 'lbl',
            'style' => 'color:red',
            'tooltip' => 'tip',
            'grow' => 2,
            'visible' => false,
        ]);
        $this->assertSame('color:red', $node['style']);
        $this->assertSame('tip', $node['tooltip']);
        $this->assertSame(2, $node['grow']);
        $this->assertFalse($node['visible']);
    }

    public function testEmptyChildrenAreOmitted(): void
    {
        $node = WidgetTree::label('x');
        $this->assertArrayNotHasKey('children', $node);
    }

    public function testChildrenAreReindexed(): void
    {
        $children = [3 => WidgetTree::label('A'), 7 => WidgetTree::label('B')];
        $node = WidgetTree::vbox($children);
        $this->assertSame([0, 1], array_keys($node['children']));
    }

    // ── webview ──

    public function testWebViewWithUrl(): void
    {
        $node = WidgetTree::webView('https://example.com', ['id' => 'wv']);
        $this->assertSame('webview', $node['type']);
        $this->assertSame('https://example.com', $node['url']);
        $this->assertSame('wv', $node['id']);
    }

    /** 不传 url 时不写这个键 —— 否则会覆盖掉同节点上的 html。 */
    public function testWebViewWithoutUrlOmitsKey(): void
    {
        $node = WidgetTree::webView('', ['id' => 'wv', 'html' => '<p>hi</p>']);
        $this->assertArrayNotHasKey('url', $node);
        $this->assertSame('<p>hi</p>', $node['html']);
    }

    public function testHtmlHelperBuildsWebView(): void
    {
        $node = WidgetTree::html('<h1>Title</h1>', ['id' => 'doc']);
        $this->assertSame('webview', $node['type']);
        $this->assertSame('<h1>Title</h1>', $node['html']);
    }

    /** 本地文件路径也走 url（相对 exe 目录解析由 C++ 侧负责）。 */
    public function testWebViewAcceptsLocalPath(): void
    {
        $node = WidgetTree::webView('assets/help.html', ['id' => 'help']);
        $this->assertSame('assets/help.html', $node['url']);
    }
}
