<?php

declare(strict_types=1);

namespace TypePHP\Qt;

/**
 * WidgetTree — 声明式控件树构建器。
 *
 * 提供便捷方法构建控件树数组，最终传给 qt_window_render()。
 *
 * 用法：
 *   $tree = WidgetTree::vbox([
 *       WidgetTree::label('Hello', ['id' => 'greeting']),
 *       WidgetTree::button('Click', ['id' => 'btn', 'onClick' => 'handleClick']),
 *   ]);
 *   qt_window_render($window, $tree);
 */
final class WidgetTree
{
    // ── 容器 ──

    public static function vbox(array $children, array $props = []): array
    {
        return self::node('vbox', $children, $props);
    }

    public static function hbox(array $children, array $props = []): array
    {
        return self::node('hbox', $children, $props);
    }

    public static function grid(array $children, array $props = []): array
    {
        return self::node('grid', $children, $props);
    }

    public static function form(array $children, array $props = []): array
    {
        return self::node('form', $children, $props);
    }

    public static function group(string $title, array $children, array $props = []): array
    {
        $props['title'] = $title;
        return self::node('group', $children, $props);
    }

    public static function frame(array $children, array $props = []): array
    {
        return self::node('frame', $children, $props);
    }

    public static function scroll(array $children, array $props = []): array
    {
        return self::node('scroll', $children, $props);
    }

    public static function tabs(array $pages, array $props = []): array
    {
        $children = [];
        foreach ($pages as $title => $pageChildren) {
            $children[] = self::tab($title, is_array($pageChildren) ? $pageChildren : [$pageChildren]);
        }
        return self::node('tabs', $children, $props);
    }

    public static function tab(string $title, array $children, array $props = []): array
    {
        $props['title'] = $title;
        return self::node('tab', $children, $props);
    }

    public static function stack(array $pages, array $props = []): array
    {
        $children = [];
        foreach ($pages as $pageChildren) {
            $children[] = self::page(is_array($pageChildren) ? $pageChildren : [$pageChildren]);
        }
        return self::node('stack', $children, $props);
    }

    public static function page(array $children, array $props = []): array
    {
        return self::node('page', $children, $props);
    }

    public static function split(array $children, array $props = []): array
    {
        return self::node('split', $children, $props);
    }

    public static function spacer(int $size = 0, array $props = []): array
    {
        $props['size'] = $size;
        return self::node('spacer', [], $props);
    }

    public static function separator(array $props = []): array
    {
        return self::node('separator', [], $props);
    }

    // ── 控件 ──

    public static function label(string $text, array $props = []): array
    {
        $props['text'] = $text;
        return self::node('label', [], $props);
    }

    public static function image(string $path, array $props = []): array
    {
        $props['path'] = $path;
        return self::node('image', [], $props);
    }

    public static function link(string $text, string $href, array $props = []): array
    {
        $props['text'] = $text;
        $props['href'] = $href;
        return self::node('link', [], $props);
    }

    public static function button(string $text, array $props = []): array
    {
        $props['text'] = $text;
        return self::node('button', [], $props);
    }

    public static function lineEdit(string $text = '', array $props = []): array
    {
        $props['text'] = $text;
        return self::node('lineedit', [], $props);
    }

    public static function textEdit(string $text = '', array $props = []): array
    {
        $props['text'] = $text;
        return self::node('textedit', [], $props);
    }

    public static function spin(int $value = 0, array $props = []): array
    {
        $props['value'] = $value;
        return self::node('spin', [], $props);
    }

    public static function doubleSpin(float $value = 0.0, array $props = []): array
    {
        $props['value'] = $value;
        return self::node('doublespin', [], $props);
    }

    public static function slider(int $value = 0, array $props = []): array
    {
        $props['value'] = $value;
        return self::node('slider', [], $props);
    }

    public static function progress(int $value = 0, array $props = []): array
    {
        $props['value'] = $value;
        return self::node('progress', [], $props);
    }

    public static function checkbox(string $text = '', bool $checked = false, array $props = []): array
    {
        $props['text'] = $text;
        $props['checked'] = $checked;
        return self::node('checkbox', [], $props);
    }

    public static function radio(string $text = '', bool $checked = false, array $props = []): array
    {
        $props['text'] = $text;
        $props['checked'] = $checked;
        return self::node('radio', [], $props);
    }

    public static function combo(array $items, string $value = '', array $props = []): array
    {
        $props['items'] = $items;
        $props['value'] = $value;
        return self::node('combo', [], $props);
    }

    public static function list(array $items, string $value = '', array $props = []): array
    {
        $props['items'] = $items;
        $props['value'] = $value;
        return self::node('list', [], $props);
    }

    public static function table(array $columns, array $rows, array $props = []): array
    {
        $props['columns'] = $columns;
        $props['rows'] = $rows;
        return self::node('table', [], $props);
    }

    public static function tree(array $nodes, array $props = []): array
    {
        $props['nodes'] = $nodes;
        return self::node('tree', [], $props);
    }

    /**
     * 内嵌网页视图。
     *
     * 后端按平台在编译期选：Windows 上是 WebView2（完整 Chromium，支持 JS），
     * macOS 上是系统 WebKit 的 WKWebView（支持 JS），其余平台是 QTextBrowser
     * （HTML 子集，**不支持 JS**）。可用 `QtApp::webViewBackend()` / `webViewSupportsJs()` 查询。
     *
     * `$props['url']` 既可以是远程地址，也可以是本地文件路径（相对 exe 目录解析）；
     * 也可以改用 `$props['html']` 直接给 HTML 字符串。
     * 注意 QTextBrowser 后端只认本地文件，`http(s)://` 与 `data:` 在它上面加载不出来。
     */
    public static function webView(string $url = '', array $props = []): array
    {
        if ($url !== '') {
            $props['url'] = $url;
        }
        return self::node('webview', [], $props);
    }

    /** 直接渲染一段 HTML（不经过 URL）。 */
    public static function html(string $html, array $props = []): array
    {
        $props['html'] = $html;
        return self::node('webview', [], $props);
    }

    /**
     * QML 组件（QQuickWidget 嵌入现有控件树，叶子控件）。
     *
     * 叶子语义（F51 D6）：QML 内部结构由 .qml 文件自己组织，
     * 节点上的 children 被静默忽略，不从 PHP 树里种 Qt Quick 节点。
     *
     * 四个专用键（其余键走通用控件树语义）：
     *   `$props['source']`   .qml 文件路径（相对 exe 目录解析）或 file:// URL
     *   `$props['signals']`  root 对象暴露的信号名列表（按名连进事件队列，
     *                        事件 type='qml'、value=信号名、payload=read 快照）
     *   `$props['props']`    关联数组，写 root 的动态属性
     *                        （setProperty 通道，动态 metaobject 支持）
     *   `$props['read']`     属性名列表，事件 payload 与 value() 读回都用它
     *
     * 空 source **不写键**防覆盖显式传入的 source（照 webView 的 guard，
     * tests/WidgetTreeTest.php:325 的注释先例）。
     *
     * 能力查询：`QtApp::qmlAvailable()` 返回编译时是否启用了 QML；
     * false 时控件退化成可诊断占位标签（strict 下打警告），信号/属性/读回
     * 全部失效，应用走降级分支。
     */
    public static function qml(string $source = '', array $props = []): array
    {
        if ($source !== '') {
            $props['source'] = $source;
        }
        return self::node('qml', [], $props);
    }

    // ── 内部 ──

    private static function node(string $type, array $children, array $props): array
    {
        $node = ['type' => $type];
        if (!empty($props)) {
            $node = array_merge($node, $props);
        }
        if (!empty($children)) {
            $node['children'] = array_values($children);
        }
        return $node;
    }
}
