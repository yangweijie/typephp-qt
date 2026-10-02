# WebView

`WidgetTree::webView()` embeds a web view in your window. There are **two backends**, chosen at
compile time — your PHP code is identical either way.

| Backend | Where | Renders | JS |
|---|---|---|---|
| **WebView2** | Windows, when the SDK is enabled | full Chromium (Edge engine) | ✅ |
| **QTextBrowser** | everywhere else, and when the SDK is off | HTML subset + images + links | ❌ |

Ask which one you got:

```php
$app->webViewBackend();      // 'webview2' | 'textbrowser'
$app->webViewSupportsJs();   // bool
```

Use it to degrade gracefully rather than showing a blank box:

```php
$app->view(function () use ($state, $app): array {
    return WidgetTree::vbox([
        $app->webViewSupportsJs()
            ? WidgetTree::webView($state->url, ['id' => 'wv', 'grow' => 1])
            : WidgetTree::label('This page needs JavaScript; the current backend cannot run it.',
                                ['id' => 'fallback']),
    ]);
});
```

## Two ways to feed it

```php
// 1. a URL — remote, or a local file resolved against the executable's directory
WidgetTree::webView('https://example.com', ['id' => 'wv']);
WidgetTree::webView('assets/help.html', ['id' => 'wv']);   // local file

// 2. an HTML string, rendered directly
WidgetTree::html('<h1>Hello</h1><p>Rendered inline.</p>', ['id' => 'wv']);
```

| Property | Meaning |
|---|---|
| `url` | Address to load. Anything with a scheme (`http://`, `https://`, `file://`, `data:`) is used as-is; otherwise it is treated as a **local path** resolved against the executable's directory (then the working directory) |
| `html` | HTML string to render directly. Takes precedence over `url` when both are set |
| `zoom` | Zoom factor (WebView2 only; ignored by QTextBrowser) |

## Events

| Event | When | `value` | `payload` |
|---|---|---|---|
| `loaded` | navigation finished | the final URL | `success` (bool) |
| `title` | document title changed | the title | — |
| `navigating` | navigation started | the target URL | — |
| `link` | a new-window request (`target="_blank"`) | the URL | — |

`link` **does not** open anything by itself — it reports, and you decide. That is the same
state-driven convention as the `link` widget:

```php
$app->on('wv', 'link', function (array $event) use ($app) {
    $app->setStatus(['Blocked popup: ' . $event['value']]);
});
```

## Enabling WebView2

It is on by default in projects created by `qtphp new` on Windows. The switch is two lines in
`project.yml`:

```yaml
link-paths:
  - ../../third_party/webview2/x64      # the vendored SDK
link-libs:
  - WebView2Loader.dll.lib
cxx-flags:
  - /DQT_WEBVIEW2
  - /I"../../third_party/webview2/include"
```

**Comment out `/DQT_WEBVIEW2` and it falls back to QTextBrowser** — the app still compiles and
runs, just without JS.

`qtphp build` deploys the 195 KB `WebView2Loader.dll` next to your executable automatically.
The **runtime** is not bundled: WebView2 apps need the *WebView2 Runtime* installed on the target
machine, which Windows 10/11 ship with Edge. If it is missing, the widget shows an explanatory
message instead of a blank box.

## Caveats

::: warning `--shot` cannot capture the WebView2 backend
WebView2 paints into its own child window, so Qt's `QWidget::grab()` (which `--shot` uses) does not
include it — the area comes out blank in the PNG. This is a limitation of the backend, not a bug.

The **QTextBrowser** backend is drawn by Qt and therefore *is* captured.

`--selftest` and `--difftest` are unaffected: they dispatch events and never look at pixels.
:::

::: tip Give it a height
A web view contributes nothing to Qt's layout size calculation (WebView2's pixels live outside the
Qt widget tree). The widget ships a sensible default and a minimum height, but if you see it
collapsed to a thin strip, give it `'grow' => 1` inside a container that can expand — and make sure
the window itself is tall enough for the rest of the content.
:::

::: warning JavaScript is unavailable on QTextBrowser
Anything that renders client-side — SPAs, most modern sites — will come out empty. Check
`webViewSupportsJs()` and show your own fallback. Static HTML, documentation pages and simple
reports work fine on both backends.
:::

## Related

- [Widget Catalog](/widgets/) — the other controls
- [Properties](/guide/properties.md) — common properties (`grow`, `size`, …)
- [Packaging](/reference/packaging.md) — what ships next to the binary
