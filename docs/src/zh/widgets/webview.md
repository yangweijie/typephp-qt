# WebView（内嵌网页）

`WidgetTree::webView()` 在窗口里嵌入一个网页视图。有**三种后端**，编译期三选一 ——
PHP 代码三个后端完全一样，能力不对等的地方见下表。

| 后端 | 用在 | JS | 远程 `http(s)://` | `data:` | 本地文件 | `zoom` |
|---|---|---|---|---|---|---|
| **WebView2** | Windows，且启用了 SDK | ✅ | ✅ | ✅ | ✅ | ✅ |
| **WKWebView** | macOS（默认启用） | ✅ | ✅ | ✅ | ✅ | ✅ |
| **QTextBrowser** | Linux，以及关掉上面两个开关时 | ❌ | ❌ | ❌ | ✅ | ❌ 静默忽略 |

QTextBrowser 是 Qt 自带的 HTML 子集渲染器，**没有任何网络栈**：给它 `https://…` 只会得到一片空白，
而且不报错。需要远程页面或 JS 的应用应该用 `webViewSupportsJs()` 做降级，而不是指望它。

查当前用的是哪个：

```php
$app->webViewBackend();      // 'webview2' | 'wkwebview' | 'textbrowser'
$app->webViewSupportsJs();   // bool
```

用它做优雅降级，而不是留一片空白：

```php
$app->view(function () use ($state, $app): array {
    return WidgetTree::vbox([
        $app->webViewSupportsJs()
            ? WidgetTree::webView($state->url, ['id' => 'wv', 'grow' => 1])
            : WidgetTree::label('这个页面需要 JavaScript，当前后端跑不了。',
                                ['id' => 'fallback']),
    ]);
});
```

## 两种喂数据的方式

```php
// 1. URL —— 远程地址，或按「可执行文件目录」解析的本地文件
WidgetTree::webView('https://example.com', ['id' => 'wv']);
WidgetTree::webView('assets/help.html', ['id' => 'wv']);   // 本地文件

// 2. 直接给 HTML 字符串
WidgetTree::html('<h1>你好</h1><p>直接渲染。</p>', ['id' => 'wv']);
```

| 属性 | 说明 |
|---|---|
| `url` | 要加载的地址。带 scheme 的（`http://`、`https://`、`file://`、`data:`）原样交给后端 —— 但**只有 WebView2 / WKWebView 认这些 scheme**，QTextBrowser 只认 `file:`；无 scheme 时当**本地路径**，按可执行文件目录解析（其次工作目录），三个后端都支持 |
| `html` | 直接渲染的 HTML 字符串。两者都给时以它为准 |
| `zoom` | 缩放因子（WebView2 / WKWebView 支持；QTextBrowser 没有缩放概念，静默忽略） |

## 事件

| 事件 | 时机 | `value` | `payload` |
|---|---|---|---|
| `loaded` | 导航完成 | 最终 URL | `success`（bool） |
| `title` | 文档标题变化 | 标题 | — |
| `navigating` | 导航开始 | 目标 URL | — |
| `link` | 新窗口请求（`target="_blank"`） | URL | — |

`link` **不会自己打开任何东西** —— 它只上报，跳不跳由你决定。这和 `link` 控件是同一套
状态驱动约定：

```php
$app->on('wv', 'link', function (array $event) use ($app) {
    $app->setStatus(['已拦截弹窗：' . $event['value']]);
});
```

## 启用 WebView2

Windows 上 `qtphp new` 生成的项目默认就开着。开关是 `project.yml` 里两行：

```yaml
link-paths:
  - ../../third_party/webview2/x64      # 包里 vendor 的 SDK
link-libs:
  - WebView2Loader.dll.lib
cxx-flags:
  - /DQT_WEBVIEW2
  - /I"../../third_party/webview2/include"
```

**把 `/DQT_WEBVIEW2` 注释掉就退化成 QTextBrowser** —— 程序照样编译运行，只是没有 JS。

`qtphp build` 会自动把 195KB 的 `WebView2Loader.dll` 部署到 exe 旁边。
**运行时不在产物里**：WebView2 程序需要目标机器装有 *WebView2 Runtime*，
Windows 10/11 随 Edge 自带。缺失时控件会显示一段说明文字，而不是一片空白。

## 启用 WKWebView（macOS）

macOS 上 `qtphp new` 生成的 `project.macos.yml` 默认就开着，开关是 `cxx-flags` 里两行：

```yaml
cxx-flags:
  - -DQT_WEBVIEW_WK     # 用系统 WebKit
  - -fobjc-arc          # .mm 里 WKWebView* 是 C++ 类的成员，需要 ARC
ld-flags:
  - -Wl,-framework,WebKit
  - -Wl,-framework,Foundation
  - -Wl,-framework,AppKit
```

**把 `-DQT_WEBVIEW_WK` 注释掉就退化成 QTextBrowser** —— 程序照样编译运行，只是没有 JS。

这条路**不需要装任何东西**：WebKit 是系统框架，产物体积不变。
刻意没走 Homebrew 的 `qtwebview` 模块 —— 它在 macOS 上用的本来也是系统 WebView
（官方文档：「On macOS, the system web view is used in the same manner as iOS」），
而 brew 的打包会把它和 QtWebEngine（GB 级）绑在一起。

代价是 `cpp-src/qt_webview_wk.mm` 得进 mac 的 `sources`。tpc 的配置合并对**列表是整体替换**，
所以 mac 入口必须把整份 sources 重写一遍再加这一行 —— 也正因如此，Windows / Linux 入口
不列它就绝不会被拿去编 ObjC++。

## 注意事项

::: warning `--shot` 截不到原生后端的画面
WebView2 画在自己的子窗口里、WKWebView 挂在 Qt 控件的原生 NSView 上，两者都在 Qt 的绘制体系之外，
所以 `QWidget::grab()`（`--shot` 用的就是它）不包含那块区域，PNG 里是一片空白。这是后端的限制，不是 bug。

**QTextBrowser** 后端由 Qt 绘制，所以**能**截到。

要验原生后端真的把页面加载并执行了，用事件而不是像素：让页面里的脚本改 `document.title`，
应用收 `title` 事件 —— 只有 JS 真的跑了才会有那个标题（`loaded` 同理）。
`--selftest` 与 `--difftest` 不受影响：它们只分发事件，不看像素。
:::

::: tip 给它高度
网页视图对 Qt 的布局尺寸计算毫无贡献（原生后端的像素在 Qt 控件树之外）。控件自带一个合理的
默认尺寸与最小高度；但如果看到它被压成一条细缝，就在能伸展的容器里给它 `'grow' => 1`，
并确认窗口本身对其余内容来说够高。
:::

::: warning QTextBrowser 上跑不了 JavaScript
所有客户端渲染的东西 —— SPA、大多数现代网站 —— 会是空白。用 `webViewSupportsJs()` 判断并给出
自己的降级提示。静态 HTML、文档页、简单报表在三个后端上都正常。
:::

## 相关

- [控件目录](/zh/widgets/) —— 其他控件
- [属性](/zh/guide/properties.md) —— 通用属性（`grow`、`size` 等）
- [打包](/zh/reference/packaging.md) —— 二进制旁边要带哪些东西
