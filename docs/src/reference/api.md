# API Reference

## `TypePHP\Qt\QtApp`

### Lifecycle

| Method | Description |
|---|---|
| `create(array $options = []): void` | Initialize `QApplication` (idempotent). `$options` supports `name` `version` `organization` `font_size` `stylesheet` |
| `createWindow(string $title, array $options = []): mixed` | Create a window. `$options` supports `width` `height` `min_width` `min_height` `centered` |
| `run(int $maxFrames = 0): void` | Enter the main loop until the window closes (or `$maxFrames` frames elapse) |
| `runFrames(int $frames = 3): void` | Pump the given number of frames, then return. **Use this for multiple windows** |
| `stop(): void` | Request the main loop to exit |
| `close(): void` | Request the window to close |
| `isOpen(): bool` | Is the window still open |
| `destroy(): void` | Destroy the window and release resources |
| `handle(): mixed` | Get the underlying window handle |

### Describing the UI

| Method | Description |
|---|---|
| `view(callable $builder): void` | Register the view builder. **Runs every frame**, returning a widget tree |
| `render(array $tree): void` | Render a tree directly (bypassing `view()`). Used for multiple windows |
| `patch(array $ops): void` | Incremental patch, see [Patching](/guide/patching.md) |

### Events

| Method | Description |
|---|---|
| `on(string $id, string $type, callable $handler): void` | Register by id + type |
| `onAny(string $type, callable $handler): void` | Register by type only (no id, e.g. `tray`) |
| `dispatch(array $event): void` | Dispatch an event manually (used by `--selftest`) |

A handler may declare 0 or 1 parameters (`array $event`) — the framework probes the count at registration.

### Reading widget values

| Method | Returns |
|---|---|
| `text(string $id): string` | The text of a text control |
| `value(string $id): mixed` | The value of a numeric / selection control |
| `checked(string $id): bool` | The checked state |

### Imperative operations (not in the widget tree, skipped by the diff)

| Method | Description |
|---|---|
| `setTitle(string $title): void` | Window title |
| `setMenu(array $items): void` | Menu bar |
| `setStatus(array $segments): void` | Status-bar segments |
| `resize(int $width, int $height): void` | Window size |
| `setTray(array $spec): void` | System tray. `$spec` supports `icon` `tooltip` `visible` `menu` |
| `setTimer(string $id, int $intervalMs): void` | Timer; interval `<= 0` stops it |

### Dialogs

| Method | Description |
|---|---|
| `message(array $spec): string` | Low-level message box; returns the pressed button |
| `alert(string $text, string $title = '提示'): void` | Information box |
| `error(string $text, string $title = '错误'): void` | Error box |
| `confirm(string $text, string $title = '确认'): bool` | Confirmation box |
| `openFile(string $title = '打开文件', string $filter = ''): array` | Returns `['path'=>…, 'name'=>…]`, or `[]` when cancelled |
| `saveFile(string $title = '保存文件', string $filter = '', string $filename = ''): array` | As above |
| `pickDirectory(string $title = '选择目录'): string` | Returns `''` when cancelled |
| `notify(string $title, string $message): void` | System notification |

### Clipboard

| Method | Description |
|---|---|
| `clipboardRead(): string` | Read the clipboard |
| `clipboardWrite(string $text): void` | Write the clipboard |

### Verification and diagnostics

| Method | Description |
|---|---|
| `headless(bool $on = true): void` | Headless mode: dialogs do not block |
| `isHeadless(): bool` | Whether headless mode is on |
| `snapshot(string $path): bool` | Save a PNG |
| `frameCount(): int` | Frames rendered so far |
| `lastError(): string` | The last handler exception (with `file:line`) |
| `clearError(): void` | Clear the error |

---

## `TypePHP\Qt\WidgetTree`

All static factory methods returning array nodes. Signatures and main properties: [Widget Catalog](/widgets/).

```php
WidgetTree::vbox(array $children, array $props = [])
WidgetTree::hbox(array $children, array $props = [])
WidgetTree::grid(array $children, array $props = [])
WidgetTree::form(array $children, array $props = [])
WidgetTree::group(string $title, array $children, array $props = [])
WidgetTree::frame(array $children, array $props = [])
WidgetTree::scroll(array $children, array $props = [])
WidgetTree::tabs(array $pages, array $props = [])
WidgetTree::tab(string $title, array $children, array $props = [])
WidgetTree::stack(array $pages, array $props = [])
WidgetTree::page(array $children, array $props = [])
WidgetTree::split(array $children, array $props = [])
WidgetTree::spacer(int $size = 0, array $props = [])
WidgetTree::separator(array $props = [])

WidgetTree::label(string $text, array $props = [])
WidgetTree::image(string $path, array $props = [])
WidgetTree::link(string $text, string $href, array $props = [])
WidgetTree::button(string $text, array $props = [])
WidgetTree::lineEdit(string $text = '', array $props = [])
WidgetTree::textEdit(string $text = '', array $props = [])
WidgetTree::spin(int $value = 0, array $props = [])
WidgetTree::doubleSpin(float $value = 0.0, array $props = [])
WidgetTree::slider(int $value = 0, array $props = [])
WidgetTree::progress(int $value = 0, array $props = [])
WidgetTree::checkbox(string $text = '', bool $checked = false, array $props = [])
WidgetTree::radio(string $text = '', bool $checked = false, array $props = [])
WidgetTree::combo(array $items, string $value = '', array $props = [])
WidgetTree::list(array $items, string $value = '', array $props = [])
WidgetTree::table(array $columns, array $rows, array $props = [])
WidgetTree::tree(array $nodes, array $props = [])
```

---

## Event table

| Event | Emitted by | `value` | `payload` |
|---|---|---|---|
| `click` | button, link | the link's href | — |
| `change` | lineedit, textedit, spin, doublespin, slider, combo | the new value | `index` for combo |
| `submit` | lineedit | the text | — |
| `toggle` | checkbox, radio, checkable button, checkable group | `'0'` / `'1'` | — |
| `select` | list, table, tree | row / item id | `index` |
| `activate` | list, table, tree | row / item id | — |
| `tab` | tabs, stack | the index | `index` |
| `menu` | menu item | — | `checked` |
| `timer` | timer | — | — |
| `tray` | system tray | `left` / `right` / `double` / `middle` | — |
| `press` / `release` | button | — | — |
| `commit` | lineedit (focus lost or Enter) | the text | — |
| `itemClick` | list (every click, even re-clicking the same row) | item id | — |
| `cell` | table (cell edited) | new text | `row`, `col` |
| `expand` / `collapse` | tree | node id | `expanded` |
| `close` | tabs (close button) | the index | `index` |

The event object:

```php
['type' => 'click', 'id' => 'btn', 'value' => '…', 'payload' => [...]]
```

---

## Bridge contract

The 24 functions declared in `php-src/qt.stub.php` (whose agreement with the C++ implementation `qtphp lint` verifies):

| Function | Description |
|---|---|
| `qt_bridge_version(): string` | Bridge version |
| `qt_app_create(array $options = []): mixed` | Initialize `QApplication` (idempotent) |
| `qt_window_create(string $title, array $options = []): mixed` | Create a window |
| `qt_window_render(mixed $window, array $tree): void` | Render and diff |
| `qt_window_patch(mixed $window, array $ops): void` | Incremental patch |
| `qt_window_is_open(mixed $window): bool` | Is the window open |
| `qt_window_process_events(mixed $window): void` | Pump one frame of events |
| `qt_window_poll_event(mixed $window): array` | Pull one queued event |
| `qt_window_close(mixed $window): void` | Request close |
| `qt_window_destroy(mixed $window): void` | Destroy |
| `qt_window_snapshot(mixed $window, string $path): bool` | Save a PNG |
| `qt_window_widget_value(mixed $window, string $id): mixed` | Read a widget value |
| `qt_window_set_title(mixed $window, string $title): void` | Window title |
| `qt_window_set_menu(mixed $window, array $items): void` | Menu bar |
| `qt_window_set_status(mixed $window, array $segments): void` | Status bar |
| `qt_window_resize(mixed $window, int $width, int $height): void` | Window size |
| `qt_window_message(mixed $window, array $spec): string` | Message box |
| `qt_window_file_dialog(mixed $window, array $spec): array` | File dialog |
| `qt_window_directory_dialog(mixed $window, array $spec): string` | Directory dialog |
| `qt_window_notify(mixed $window, string $title, string $message): void` | System notification |
| `qt_window_set_tray(mixed $window, array $spec): void` | System tray |
| `qt_window_set_timer(mixed $window, string $id, int $intervalMs): void` | Timer |
| `qt_clipboard_read(): string` | Read the clipboard |
| `qt_clipboard_write(string $text): void` | Write the clipboard |

::: tip These are internal interfaces
Application code uses `QtApp`'s methods; do not call `qt_*` directly. This section is for people extending the bridge.
:::

---

## `TypePHP\Qt\FakeBridge`

A pure-PHP double for the `qt_*` functions, used by the tests. It defines global functions of the same names and lets you inject events from a script.

It makes domain-layer testing **dependency-free** — no Qt, no compiler:

```bash
qtphp test    # everything goes through FakeBridge
```

::: warning Its limits
`FakeBridge` runs on a tolerant PHP interpreter, so it **structurally cannot** reproduce AOT's strictness (a closure arity mismatch, for example). It covers logic, not compilation behaviour — the latter needs `--selftest`.
:::
