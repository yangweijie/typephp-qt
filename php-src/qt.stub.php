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
 * 未知 method、未知 id、未知属性、未知控件类型默认静默忽略 —— 这是为了向前兼容：
 * 新版框架写出的树，在旧版运行时上只会少一块，不会整体崩掉。
 * 代价是拼错的属性名/控件名完全没声音，所以调试期设环境变量 `QT_DEBUG_STRICT=1`
 * 让这些情况打 qWarning。`QT_DEBUG_TIMING=1` 则每帧打印 render 耗时。
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

/* ──────────────────────────── 窗口集合与几何 ──────────────────────────── */

/**
 * 当前所有存活窗口的 id 列表（按创建顺序）。
 *
 * 进程内可以有多个窗口；id 是创建时分配的稳定串，跨帧不变，可用来做
 * 「记住上次开着的窗口」这类持久化。
 */
function qt_window_list(): array {}

/** 该窗口的稳定 id（创建时分配）。 */
function qt_window_id(mixed $window): string {}

/** 激活窗口：取消最小化、置前、把键盘焦点交给它。 */
function qt_window_focus(mixed $window): void {}

/** 把窗口居中到它当前所在的屏幕。 */
function qt_window_center(mixed $window): void {}

/**
 * 设置父窗口（子窗口随之最小化/关闭）。传 null 解除父子关系。
 *
 * 注意这是 Qt 的 transient parent，不是「控件嵌入」—— 窗口仍是独立顶层窗口。
 */
function qt_window_set_parent(mixed $window, mixed $parent): void {}

/**
 * 读窗口几何，返回 ['x'=>int, 'y'=>int, 'width'=>int, 'height'=>int]。
 *
 * x/y 是**客户区**左上角在屏幕上的位置（不含标题栏边框），
 * 直接喂给 qt_window_set_geometry() 可以原样恢复。
 */
function qt_window_geometry(mixed $window): array {}

/** 设置窗口几何。$rect: ['x'=>, 'y'=>, 'width'=>, 'height'=>]；缺省的键保持不动。 */
function qt_window_set_geometry(mixed $window, array $rect): void {}

/* ─────────────────────────── 事件注入与内省 ─────────────────────────── */

/**
 * 把一个事件注入到该窗口的事件队列，下一次 poll 时由 PHP 处理器收到。
 *
 * $event 与 poll 出来的结构相同：['type'=>, 'id'=>, 'value'=>, 'payload'=>]。
 * 用途是给无头验收与单测构造「用户点了这个按钮」的场景 —— 它不经过 Qt，
 * 所以不会真的点击控件，只走 PHP 侧的分派链路。
 */
function qt_window_send_event(mixed $window, array $event): void {}

/**
 * 按 id 查控件状态，返回
 * ['id'=>, 'type'=>, 'value'=>, 'visible'=>bool, 'enabled'=>bool,
 *  'geometry'=>['x'=>,'y'=>,'width'=>,'height'=>]]；
 * 找不到返回 `[]`。
 */
function qt_window_find(mixed $window, string $id): array {}

/**
 * 导出控件树的无障碍快照（role/text/value/geometry/children）。
 *
 * 与 qt_window_snapshot() 的区别：那个出 PNG 给人看，这个出结构化数组给
 * 断言用 —— 无头环境里也能跑，不依赖渲染。
 *
 * $maxDepth 限制递归深度（0 表示只出根），防止深树把 PHP 数组撑爆。
 */
function qt_window_a11y_tree(mixed $window, int $maxDepth = 6): array {}

/**
 * 一次取走最多 $max 个待处理事件，返回事件数组。
 *
 * 比逐个 qt_window_poll_event() 少一半跨界调用；主循环里应当优先用它。
 */
function qt_window_poll_events(mixed $window, int $max = 64): array {}

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
 * 窗口内浮层提示（toast），$durationMs 后自动消失。
 *
 * 与 qt_window_notify() 的区别：那个走系统托盘，无托盘时会退化成模态框阻塞；
 * 这个始终是窗口内的非阻塞浮层，托盘不可用时的降级方案。
 */
function qt_window_toast(mixed $window, string $message, int $durationMs = 3000): void {}

/**
 * 配置系统托盘图标。$spec: icon, tooltip, menu(同 set_menu 结构), visible。
 * 托盘应用还需在 qt_app_create 传 quit_on_last_window_closed=false。
 */
function qt_window_set_tray(mixed $window, array $spec): void {}

/** 注册周期定时器，到点会收到 ['type'=>'timer','id'=>$id] 事件。interval 为毫秒，<=0 取消。 */
function qt_window_set_timer(mixed $window, string $id, int $intervalMs): void {}

function qt_clipboard_read(): string {}

function qt_clipboard_write(string $text): void {}

/** 当前编译进去的 webview 后端名："webview2"（Windows，完整 Chromium）、"wkwebview"（macOS，系统 WebKit）或 "textbrowser"（HTML 子集）。 */
function qt_webview_backend(): string {}

/** 该后端是否支持 JavaScript —— 只有 QTextBrowser 不支持，应用可据此降级提示。 */
function qt_webview_supports_js(): bool {}

/* ────────────────────────────── 网络原语 ────────────────────────────── */

/**
 * 同步 HTTP 请求，阻塞到响应返回或超时。
 *
 * $spec: method(GET|POST|PUT|DELETE|PATCH), url, headers(关联数组),
 *        body(字符串), timeout_ms(默认 15000), follow_redirects(bool, 默认 true)。
 *
 * 返回 ['status'=>int, 'headers'=>关联数组, 'body'=>string, 'error'=>string]。
 * 网络失败时 status 为 0、error 非空 —— 不抛异常，因为 PHP 侧要自己做重试
 * 与降级（桌面应用不该因为一次请求失败就崩掉）。
 *
 * ⚠ 本函数是同步阻塞的：Qt 的事件循环在这一刻不转，界面会冻结到超时为止。
 *   短请求可以接受；长请求请在定时器回调里发起，或改成后台线程 + 轮询。
 */
function qt_http_request(array $spec): array {}
