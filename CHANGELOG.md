# Changelog

本项目的所有重要变更都记录在这里。

格式参考 [Keep a Changelog](https://keepachangelog.com/zh-CN/1.1.0/)，
版本号遵循 [语义化版本](https://semver.org/lang/zh-CN/)。

## [Unreleased]

### Added

- **窗口集合 API**：`qt_window_list()` / `qt_window_id()` / `qt_window_focus()` /
  `qt_window_center()` / `qt_window_set_parent()`，多窗口应用不必自己维护句柄表。
- **窗口几何持久化**：`qt_window_geometry()` / `qt_window_set_geometry()`，
  配合 `QtApp::saveGeometry()` / `restoreGeometry()` 记住窗口位置与大小。
- **测试驱动接口**：`qt_window_send_event()` 从外部注入事件、`qt_window_find()` 按
  id 查控件信息、`qt_window_a11y_tree()` 导出可访问性树，用于无头验收。
- **批量事件拉取**：`qt_window_poll_events(int $max = 64)`，一次取走一帧内积压的事件，
  避免高频信号下每帧只消费一个事件导致队列堆积。
- **HTTP 原语**：`qt_http_request()`（QNetworkAccessManager，同步语义 —— 内部跑嵌套
  QEventLoop，所以窗口在请求期间不假死），以及 PHP 侧 `QtApp::httpGet()` / `httpPost()`。
- **通知降级**：`qt_window_notify()` 在没有系统托盘时退回任务栏气泡，不再静默丢失。
- **应用数据目录**：`TypePHP\Qt\AppData`（配置/缓存/数据目录解析）与
  `TypePHP\Qt\JsonStore`（原子写入的 JSON 键值存储）。
- **控件工厂注册表**：`qtCreateWidget` 的 if 链改为注册表，新增控件类型不必再改分发逻辑。
- **帧耗时观测**：设置 `QT_DEBUG_TIMING=1` 后，每帧渲染耗时输出到 stderr。
- **调试期诊断**：设置 `QT_DEBUG_STRICT=1` 后，未知控件类型、未知属性、未知
  patch 操作/id/method 会输出警告，不再静默忽略。
- **示例**：`examples/taskboard`，一个包含表格、过滤、状态栏与几何持久化的完整应用。
- **QML 支持**：新控件类型 `'qml'`（`WidgetTree::qml()`），经 `QQuickWidget` 把
  QML 组件嵌进现有控件树——编译期开关 `QT_QML`（需额外引入
  `QtQml` / `QtQuick` / `QtQuickWidgets` 三个模块）；未开启时退化成带诊断
  文案的占位标签，不静默白屏。根对象的 `signals` 接进既有事件队列
  （`type='qml'`、`value`=信号名、payload 是 `read` 属性快照），属性经
  `props` 写入、`value()` 读回；`QtApp::qmlAvailable()` / `qmlStatus()` 提供
  能力查询与显式错误通道。配套示例 `examples/qmlclock`。
  信号经真实 moc 槽（`QPushButton::click()` 跳板）同步投递；mac 打包经藏依赖归因
  验证自包含（QML 类型注册内嵌 framework，F55）。
- **A2UI 支持**：纯 PHP 协议层 `TypePHP\Qt\A2UI`（`DataModel` / `Scope` / `Evaluator` /
  `Catalog` / `Renderer` / `Outbound`，零新桥接函数——传输复用既有 `qt_sse_*`，
  AG-UI 是 A2UI 的标准传输绑定之一）。六种 Agent→Renderer 信封分发、JSON Pointer
  数据模型（upsert / null 删键 / 根替换）、模板迭代（实例 id `__<index>` 后缀，
  帧间稳定）、双向绑定（输入写回本地模型，action 派发时解析 context）、
  18 组件映射到现有控件树（13 原生 + 5 占位 label，不静默白屏）。离线回放示例
  `examples/a2ui-demo`（`--selftest` 四段 rc 判据 / `--bench N` METRIC 行 / GUI）。
  顺带修掉一个 AOT 陷阱：`DataModel::get()` 的下钻变量被单一类型推断钉成 array，
  标量节点回填处插入隐式 `Variant→Array` 转换，纯 PHP 单测全绿、AOT 产物运行期
  fatal —— 用编译期降级 `std::any($x)`（`php::Var`）修复，测试引导补 `std` 运行期
  polyfill。
- **AG-UI 支持**：新增 SSE 流式桥接原语 `cpp-src/qt_sse.cc`（`qt_sse_open/read/status/
  close`，全局流注册表 + `QNetworkAccessManager`、无 moc）与纯 PHP 协议层
  `TypePHP\Qt\AGUI`（`JsonPatch` / `Decoder` / `Session` / `SseClient`，不依赖网络
  即可单测）；`QtApp` 补 `sseOpen/sseRead/sseStatus/sseClose` 四个无窗口转发方法。
  `QTPHP_NO_NETWORK` 构建下原语退化为报错桩（符号恒在、不链 `Qt6Network`）。
  离线回放示例 `examples/agui-demo`（内置 fixture → 解码 → 渲染 → 读回断言，
  无头可验收；`--bench N` 输出 `METRIC` 行供 autoresearch 计时）。
  解码器热路径经 autoresearch 循环优化（每事件哈希探测收敛 + 快路径，
  回放吞吐约 2× 基线，`experiment:` 提交链 a2f1b1e…b74b85b）。
- **CI**：新增 `.github/workflows/ci.yml`（PHPUnit + `qtphp lint` + Windows 构建，
  并编译/无头自测 hello、qmlclock、agui-demo 三个示例）。
- **文档**：新增 `docs/src/advanced/performance.md` 与 `pitfalls.md`；
  `CHANGELOG.md`、`AGENTS.md`。

### Changed

- **Qt Network 变为可选**：`project.yml` 的 `cxx-flags` 定义 `QTPHP_NO_NETWORK`
  （MSVC：`/DQTPHP_NO_NETWORK`）并去掉 `Qt6Network` 的头/库后，不联网的应用不再链接
  `Qt6Network.lib`，`qtphp build` / `package` 也不会再部署 `Qt6Network.dll`。桥接保留
  `qt_http_request()` 的同签名桩，调用时返回 `error` 而不是链接失败。`examples/hello`
  已切到无 Network；`taskboard` 与 `qtphp new` 模板仍默认链 Network。
- `syncChildren()` 改为最小编辑：只增删/移动真正变化的子项，不再整容器清空重建，
  消除窗口缩放和列表刷新时的闪烁与焦点丢失。
- `qtSignature()` 由递归拼接完整字符串改为哈希，大表格/大树的差异比较开销显著下降。
- `forgetSubtree()` / `removeStale()` 由每帧全表扫描改为按已登记子树裁剪。
- 打包（Windows）追加 `--no-opengl-sw`，去掉 20 MB 的 `opengl32sw.dll`（软件渲染回退，
  桌面程序用不上）。链了 Network 时 `tls` / `networkinformation` 插件**不能**跳：
  `qt_http_request()` 走 `QNetworkAccessManager`，跳过 `tls` 就只剩明文 HTTP，HTTPS 会失败。
- 示例项目三份 `project*.yml` 去重：`sources` 只在 `project.yml` 写一次，平台差异用
  tpc 支持的条件条目（`path:` + `if: PHP_OS_FAMILY == "..."`）表达；Linux/macOS 入口不再
  覆盖 `include-paths`，Qt 头目录改由 `cxx-flags` 的 `-I` 传入，从而继承仓库内路径。
  `qtphp new` 模板同步。之所以不是「合并成一份」：`include-paths` 等列表在 include
  合并时是整体替换，且不支持条件条目，一份文件表达不了三平台的头/库差异。
- `qtphp new` 生成的配置补上 `Qt6Network`（`qt_http_request()` 需要），Linux 入口在
  非 Debian 布局的生成机上不再写出 `AMD64-linux-gnu` 这种不存在的多架构三元组。

### Fixed

- `qtphp new` 传绝对路径（如 `qtphp new /home/me/demo`）不再把整串路径当项目名：
  目录按路径落位、yml/plist/bat 里的项目名取 basename（此前会拼出 `cwd//tmp/...`
  且 `output: build//tmp/...` 链接期直接失败，F54）。
- `spacer` 在 `QBoxLayout` 中 `size <= 1` 时按文档承诺生成弹性空白（`addStretch`）。
- 滚动页内容面板在 `grow` 全为 0 时不再把多余高度平均分给子控件。

## [0.9.5] - 2026-10-15

### Fixed

- 下游反馈的四项「静默不生效」修复：事件处理器不再被 `on()` 提前 `return` 吃掉
  `onAny()` 通配处理器；`tabs`/`stack` 的 `current` 首帧即可生效；
  表格多行单元格不再被裁切；Windows `--shot` 截图路径收敛并实测。

## [0.9.0] - 2026-10-06

### Added

- 表格桥接：`row_colors` 行背景色与多选选区上报。
- Windows 上 nano 构建链路跑通（`bin/qtphp` 接入 nano 产物验收）。

### Fixed

- CLI 项目根 / 包根混淆。
- `tabs` / `stack` 的 `current` 首帧失效。

## [0.8.0] - 2026-10-03

### Added

- macOS WebKit 支持（`qt_webview_wk.mm`），与 Windows 的 WebView2 统一入口。
- WebView2 分支 W1–W5 真机验证，补交 `WebView2Loader.dll.lib`。

### Fixed

- `.app` 自包含缺陷：相对资源路径不再静默丢失；`.app` 体积统计口径纠正。

## [0.7.1] - 2026-10-02

### Changed

- 文档补充 Qt SDK 安装说明。

## [0.7.0] - 2026-10-02

### Added

- 补全信号实现（`click` / `change` / `submit` / `select` / `activate` /
  `itemClick` / `cell` / `expand` / `collapse` / `loaded` / `navigating` / `title` 等）。

## [0.6.5] - 2026-10-02

### Changed

- 优化托盘多种点击事件（左键/右键/双击/中键分别上报）。

## [0.6.0] - 2026-10-02

### Fixed

- 修复托盘图标看不见的问题。

## [0.5.0] - 2026-10-02

### Added

- VuePress 文档站（英文 30 页 + 中文 29 页），GitHub Pages 自动部署。

### Fixed

- 修复 VuePress CI 报错。

## [0.4.0] - 2026-10-02

### Added

- Windows 技能文档（`.ohmyagent/skills/typephp-qt-app`）。
- 跨平台支持完成：Windows / macOS / Linux 均可编译、运行、打包。

## [0.3.0] - 2026-10-02

### Added

- 打通 macOS 原生编译 / 运行 / 打包链路。

## [0.2.0] - 2026-10-01

### Added

- WebView 与 QTextBrowser 支持（`WidgetTree::webView()`）。

## [0.1.0] - 2026-10-01

### Added

- 第一个版本：PHP 数组描述控件树、C++ 薄桥、按 id 的差异更新、
  `qtphp` CLI（doctor / new / build / run / package / test / lint）。

[Unreleased]: https://github.com/yangweijie/typephp-qt/compare/v0.9.5...HEAD
[0.9.5]: https://github.com/yangweijie/typephp-qt/compare/v0.9.0...v0.9.5
[0.9.0]: https://github.com/yangweijie/typephp-qt/compare/v0.8.0...v0.9.0
[0.8.0]: https://github.com/yangweijie/typephp-qt/compare/v0.7.1...v0.8.0
[0.7.1]: https://github.com/yangweijie/typephp-qt/compare/v0.7.0...v0.7.1
[0.7.0]: https://github.com/yangweijie/typephp-qt/compare/v0.6.5...v0.7.0
[0.6.5]: https://github.com/yangweijie/typephp-qt/compare/v0.6.0...v0.6.5
[0.6.0]: https://github.com/yangweijie/typephp-qt/compare/v0.5.0...v0.6.0
[0.5.0]: https://github.com/yangweijie/typephp-qt/compare/v0.4.0...v0.5.0
