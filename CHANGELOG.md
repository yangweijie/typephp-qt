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
- **CI**：新增 `.github/workflows/ci.yml`（PHPUnit + `qtphp lint` + Windows 构建）。
- **文档**：新增 `docs/src/advanced/performance.md` 与 `pitfalls.md`；
  `CHANGELOG.md`、`AGENTS.md`。

### Changed

- `syncChildren()` 改为最小编辑：只增删/移动真正变化的子项，不再整容器清空重建，
  消除窗口缩放和列表刷新时的闪烁与焦点丢失。
- `qtSignature()` 由递归拼接完整字符串改为哈希，大表格/大树的差异比较开销显著下降。
- `forgetSubtree()` / `removeStale()` 由每帧全表扫描改为按已登记子树裁剪。
- 打包（Windows）追加 `--no-opengl-sw`，去掉 20 MB 的 `opengl32sw.dll`（软件渲染回退，
  桌面程序用不上）。`tls` / `networkinformation` 插件**不能**跳：`qt_http_request()` 走
  `QNetworkAccessManager`，跳过 `tls` 就只剩明文 HTTP，HTTPS 会失败。
- 示例项目三份 `project*.yml` 去重：`sources` 只在 `project.yml` 写一次，平台差异用
  tpc 支持的条件条目（`path:` + `if: PHP_OS_FAMILY == "..."`）表达；Linux/macOS 入口不再
  覆盖 `include-paths`，Qt 头目录改由 `cxx-flags` 的 `-I` 传入，从而继承仓库内路径。
  `qtphp new` 模板同步。之所以不是「合并成一份」：`include-paths` 等列表在 include
  合并时是整体替换，且不支持条件条目，一份文件表达不了三平台的头/库差异。
- `qtphp new` 生成的配置补上 `Qt6Network`（`qt_http_request()` 需要），Linux 入口在
  非 Debian 布局的生成机上不再写出 `AMD64-linux-gnu` 这种不存在的多架构三元组。

### Fixed

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
