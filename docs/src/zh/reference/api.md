# API 参考

## `TypePHP\Qt\QtApp`

### 生命周期

| 方法 | 说明 |
|---|---|
| `create(array $options = []): void` | 初始化 `QApplication`（幂等）。`$options` 支持 `name` `version` `organization` `font_size` `stylesheet` |
| `createWindow(string $title, array $options = []): mixed` | 建窗口。`$options` 支持 `width` `height` `min_width` `min_height` `centered` |
| `run(int $maxFrames = 0): void` | 进主循环直到窗口关闭（或跑满 `$maxFrames` 帧） |
| `runFrames(int $frames = 3): void` | 泵指定帧数后返回。**多窗口场景用它** |
| `stop(): void` | 请求退出主循环 |
| `close(): void` | 请求关闭窗口 |
| `isOpen(): bool` | 窗口是否还开着 |
| `destroy(): void` | 销毁窗口并释放资源 |
| `handle(): mixed` | 拿底层窗口句柄 |

### 界面描述

| 方法 | 说明 |
|---|---|
| `view(callable $builder): void` | 注册视图构建函数。**每帧执行**，返回控件树 |
| `render(array $tree): void` | 直接渲染一棵树（不走 `view()`）。多窗口用 |
| `patch(array $ops): void` | 增量补丁，见[增量补丁](/zh/guide/patching.md) |

### 事件

| 方法 | 说明 |
|---|---|
| `on(string $id, string $type, callable $handler): void` | 按 id + 类型注册 |
| `onAny(string $type, callable $handler): void` | 按类型注册（不带 id，如 `tray`） |
| `dispatch(array $event): void` | 手动分派一个事件（`--selftest` 用） |

处理器可以声明 0 个或 1 个参数（`array $event`）—— 框架在注册时探测个数。

### 读控件值

| 方法 | 返回 |
|---|---|
| `text(string $id): string` | 文本类控件的文本 |
| `value(string $id): mixed` | 数值/选中类控件的值 |
| `checked(string $id): bool` | 勾选状态 |

### 命令式操作（不在控件树里，不参与 diff）

| 方法 | 说明 |
|---|---|
| `setTitle(string $title): void` | 窗口标题 |
| `setMenu(array $items): void` | 菜单栏 |
| `setStatus(array $segments): void` | 状态栏分段文本 |
| `resize(int $width, int $height): void` | 窗口尺寸 |
| `setTray(array $spec): void` | 系统托盘 |
| `setTimer(string $id, int $intervalMs): void` | 定时器；间隔 `<= 0` 即停 |

### 对话框

| 方法 | 说明 |
|---|---|
| `message(array $spec): string` | 底层消息框，返回按下的按钮 |
| `alert(string $text, string $title = '提示'): void` | 提示框 |
| `error(string $text, string $title = '错误'): void` | 错误框 |
| `confirm(string $text, string $title = '确认'): bool` | 确认框 |
| `openFile(string $title = '打开文件', string $filter = ''): array` | 返回 `['path'=>…, 'name'=>…]`，取消返回 `[]` |
| `saveFile(string $title = '保存文件', string $filter = '', string $filename = ''): array` | 同上 |
| `pickDirectory(string $title = '选择目录'): string` | 取消返回 `''` |
| `notify(string $title, string $message): void` | 系统通知 |

### 剪贴板

| 方法 | 说明 |
|---|---|
| `clipboardRead(): string` | 读剪贴板 |
| `clipboardWrite(string $text): void` | 写剪贴板 |

### 验收与诊断

| 方法 | 说明 |
|---|---|
| `headless(bool $on = true): void` | 无头模式：对话框不阻塞 |
| `isHeadless(): bool` | 当前是否无头 |
| `snapshot(string $path): bool` | 存 PNG |
| `frameCount(): int` | 已渲染帧数 |
| `lastError(): string` | 最后一次处理器异常（含 `文件:行号`） |
| `clearError(): void` | 清空错误 |

---

## `TypePHP\Qt\WidgetTree`

全部是静态工厂方法，返回数组节点。签名与主要属性见[控件目录](/zh/widgets/)。

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

## 事件表

| 事件 | 触发控件 | `value` | `payload` |
|---|---|---|---|
| `click` | button, link | link 的 href | — |
| `change` | lineedit, textedit, spin, doublespin, slider, combo | 新值 | combo 带 `index` |
| `submit` | lineedit | 文本 | — |
| `toggle` | checkbox, radio | `'0'` / `'1'` | — |
| `select` | list, table, tree | 行 / 项 id | `index` |
| `activate` | list, table, tree | 行 / 项 id | — |
| `tab` | tabs, stack | 索引 | `index` |
| `menu` | 菜单项 | — | `checked` |
| `timer` | 定时器 | — | — |
| `tray` | 系统托盘 | — | — |

事件对象：

```php
['type' => 'click', 'id' => 'btn', 'value' => '…', 'payload' => [...]]
```

---

## 桥接契约

`php-src/qt.stub.php` 声明的 24 个函数（`qtphp lint` 校验其与 C++ 实现一致）：

| 函数 | 说明 |
|---|---|
| `qt_bridge_version(): string` | 桥接版本 |
| `qt_app_create(array $options = []): mixed` | 初始化 `QApplication`（幂等） |
| `qt_window_create(string $title, array $options = []): mixed` | 建窗口 |
| `qt_window_render(mixed $window, array $tree): void` | 渲染并 diff |
| `qt_window_patch(mixed $window, array $ops): void` | 增量补丁 |
| `qt_window_is_open(mixed $window): bool` | 窗口是否开着 |
| `qt_window_process_events(mixed $window): void` | 泵一帧事件 |
| `qt_window_poll_event(mixed $window): array` | 取一个排队事件 |
| `qt_window_close(mixed $window): void` | 请求关闭 |
| `qt_window_destroy(mixed $window): void` | 销毁 |
| `qt_window_snapshot(mixed $window, string $path): bool` | 存 PNG |
| `qt_window_widget_value(mixed $window, string $id): mixed` | 读控件值 |
| `qt_window_set_title(mixed $window, string $title): void` | 窗口标题 |
| `qt_window_set_menu(mixed $window, array $items): void` | 菜单栏 |
| `qt_window_set_status(mixed $window, array $segments): void` | 状态栏 |
| `qt_window_resize(mixed $window, int $width, int $height): void` | 窗口尺寸 |
| `qt_window_message(mixed $window, array $spec): string` | 消息框 |
| `qt_window_file_dialog(mixed $window, array $spec): array` | 文件对话框 |
| `qt_window_directory_dialog(mixed $window, array $spec): string` | 目录对话框 |
| `qt_window_notify(mixed $window, string $title, string $message): void` | 系统通知 |
| `qt_window_set_tray(mixed $window, array $spec): void` | 系统托盘 |
| `qt_window_set_timer(mixed $window, string $id, int $intervalMs): void` | 定时器 |
| `qt_clipboard_read(): string` | 读剪贴板 |
| `qt_clipboard_write(string $text): void` | 写剪贴板 |

::: tip 这些是内部接口
应用代码用 `QtApp` 的方法，不要直接调 `qt_*`。这一节是给扩展桥接的人看的。
:::

---

## `TypePHP\Qt\FakeBridge`

纯 PHP 的 `qt_*` 替身，测试用。定义了同名的全局函数，把事件脚本化注入。

它让领域层测试**零依赖** —— 不需要 Qt、不需要编译器：

```bash
qtphp test    # 全部走 FakeBridge
```

::: warning 它的边界
`FakeBridge` 跑在宽容的 PHP 解释器上，所以**结构上无法复现 AOT 的严格性**（比如闭包参数个数）。它覆盖逻辑，不覆盖编译行为 —— 后者要靠 `--selftest`。
:::
