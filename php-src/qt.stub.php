<?php

/**
 * TypePHP\Qt — C++ 桥接契约。
 *
 * ⚠ 本文件只声明类型，**函数体必须为空**。C++ 实现在 cpp-src/ 下，每个
 *   PHP 函数 `qt_foo_bar()` 对应一个 C++ 符号 `php_qt_foo_bar()`。
 *
 * 设计约定（整个框架都建立在这三条上）：
 *
 *  1. **数组进、数组出。** 控件树是嵌套 PHP 数组，C++ 侧不承载业务逻辑。
 *  2. **不透明对象走 mixed。** 窗口句柄是 `php::Box`，PHP 只持有不窥探。
 *  3. **信号只入队。** 所有用户操作进事件队列，由 PHP 主循环拉取分派；
 *     事件统一扁平结构 `['type'=>.., 'id'=>.., 'value'=>.., 'payload'=>..]`。
 *
 * 控件树节点通用字段（所有 type 都认）：
 *   id        string   稳定标识，diff 的键；**要更新的控件必须给 id**
 *   type      string   节点类型，见下方目录
 *   visible   bool     默认 true
 *   enabled   bool     默认 true
 *   tooltip   string
 *   style     string   Qt 样式表片段，如 "color:#c00;font-weight:bold"
 *   size      array    [宽, 高]，数值为 0 表示不约束
 *   grow      int      布局拉伸因子（QVBoxLayout stretch）
 *   align     string   left|center|right|top|bottom|vcenter|hcenter|justify
 *   margin    int      容器内边距（容器类专用）
 *   spacing   int      容器间距（容器类专用）
 *
 * 目录（容器 / 控件）：
 *   window  vbox hbox grid form group scroll tabs tab stack page split spacer separator
 *   label button lineedit textedit spin doublespin slider progress checkbox radio
 *   combo list table tree image link
 */

/** 桥接契约版本，`qtphp lint` 用它交叉校验 stub 与 C++ 是否同源。 */
function qt_bridge_version(): string {}

/* ─────────────────────────── 应用与窗口生命周期 ─────────────────────────── */

/**
 * 创建（或在首次调用时创建）QApplication 并配置全局外观。
 *
 * $options: style(fusion|native), stylesheet, name, version, organization,
 *           icon, font_size, quit_on_last_window_closed(bool)
 */
function qt_app_create(array $options = []): mixed {}

/**
 * 创建主窗口。
 *
 * $options: width, height, min_width, min_height, resizable(bool), frameless(bool),
 *           centered(bool), maximized(bool), icon, stylesheet, quit_on_last_window_closed(bool)
 */
function qt_window_create(string $title, array $options = []): mixed {}

/**
 * 声明式渲染整棵控件树。
 *
 * 按节点 id 做差异更新：新增则创建、消失则销毁、存在则只改变化的属性，
 * 因此输入框光标、表格选中与滚动位置在重渲染后保持。幂等，可任意次数调用。
 */
function qt_window_render(mixed $window, array $tree): void {}

/**
 * 增量补丁，用于避免整树重渲染的热路径。
 *
 * $ops 为操作数组，每项两种形态之一：
 *   ['op'=>'set',  'id'=>'x', 'props'=>['text'=>'…', ...]]   声明式改属性
 *   ['op'=>'call', 'id'=>'x', 'method'=>'appendRows', 'args'=>[…]]  命令式调方法
 *
 * `call` 的 args 是**位置参数**。已实现的方法：
 *   appendRows  args[0]=行列表（每行是单元格列表），args[1]=可选的行 id 列表。仅 table。
 *   clear       表格去行 / 树、列表、下拉去条目 / 文本类置空。
 *   setText     args[0]=文本。label、button、lineedit、textedit、checkbox、radio。
 *   setValue    args[0]=值。进度条、滑块、数字框按数值；输入类按文本。
 *   select      args[0]=行/节点 id 或索引，语义与该控件的 `current` 属性一致。
 *   focus       args 忽略；把键盘焦点给这个控件。
 *
 * `call` 是声明式模型的旁路：它直接改控件，不改你的树。执行后该控件相关属性的
 * diff 签名会被作废，下一次 `qt_window_render()` 一律以树为准重新同步 —— 也就是
 * 说追加/清空只在下一次整树渲染前有效，想让它们长期存在就写回树里。
 * 未知 method、未知 id 静默忽略（与未知属性一致）。
 */
function qt_window_patch(mixed $window, array $ops): void {}

/** 窗口是否仍然打开（用户点了关闭按钮或调用 qt_window_close 后为 false）。 */
function qt_window_is_open(mixed $window): bool {}

/** 驱动 Qt 事件循环一个时间片（约 16ms），然后返回 PHP。 */
function qt_window_process_events(mixed $window): void {}

/** 取一个待处理事件；无事件时返回空数组 `[]`。 */
function qt_window_poll_event(mixed $window): array {}

/** 请求关闭窗口。 */
function qt_window_close(mixed $window): void {}

/** 销毁窗口与全部控件（不销毁 QApplication）。 */
function qt_window_destroy(mixed $window): void {}

/** 渲染一帧并保存 PNG，成功返回 true。无头验收用。 */
function qt_window_snapshot(mixed $window, string $path): bool {}

/** 读取控件当前值：输入框文本、表格选中行、复选状态等；不存在返回 null。 */
function qt_window_widget_value(mixed $window, string $id): mixed {}

/* ─────────────────────────────── 窗口装饰 ─────────────────────────────── */

function qt_window_set_title(mixed $window, string $title): void {}

/** $items 为菜单项数组：['type'=>'item|menu|sep', 'id'=>, 'text'=>, 'children'=>[], 'shortcut'=>, 'checked'=>, 'enabled'=>]。 */
function qt_window_set_menu(mixed $window, array $items): void {}

/** $segments 为状态栏文本数组；空数组表示隐藏状态栏。 */
function qt_window_set_status(mixed $window, array $segments): void {}

function qt_window_resize(mixed $window, int $width, int $height): void {}

/* ──────────────────────────── 对话框与系统集成 ──────────────────────────── */

/**
 * 模态消息框。$spec: type(info|warning|error|question), title, text,
 * buttons(ok|cancel|yesno|yesnocancel), default。
 * 返回被点击的按钮键：'ok' | 'cancel' | 'yes' | 'no'。
 */
function qt_window_message(mixed $window, array $spec): string {}

/**
 * 文件选择对话框。$spec: mode(open|save|openmany), title, filter, dir, filename。
 * 取消时返回 `[]`。
 */
function qt_window_file_dialog(mixed $window, array $spec): array {}

/** 目录选择对话框。$spec: title, dir。取消时返回空字符串。 */
function qt_window_directory_dialog(mixed $window, array $spec): string {}

/** 系统托盘气泡通知。 */
function qt_window_notify(mixed $window, string $title, string $message): void {}

/**
 * 配置系统托盘图标。$spec: icon, tooltip, menu(同 set_menu 结构), visible。
 * 托盘应用还需在 qt_app_create 传 quit_on_last_window_closed=false。
 */
function qt_window_set_tray(mixed $window, array $spec): void {}

/** 注册周期定时器，到点会收到 ['type'=>'timer','id'=>$id] 事件。interval 为毫秒，<=0 取消。 */
function qt_window_set_timer(mixed $window, string $id, int $intervalMs): void {}

function qt_clipboard_read(): string {}

function qt_clipboard_write(string $text): void {}

/** 当前编译进去的 webview 后端名："webview2"（完整 Chromium）或 "textbrowser"（HTML 子集）。 */
function qt_webview_backend(): string {}

/** 该后端是否支持 JavaScript —— QTextBrowser 不支持，应用可据此降级提示。 */
function qt_webview_supports_js(): bool {}
