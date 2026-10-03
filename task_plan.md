# Task Plan: typephp-qt — TypePHP + Qt GUI 快速开发框架

## Goal

在 `D:\git\php\typephp-qt` 建立一个 **Composer 包**，用于快速开发基于 TypePHP(AOT) + Qt6 的原生 GUI 应用，提供：

1. **方便的封装** — 声明式 UI（PHP 数组描述控件树）+ PHP 侧应用框架（窗口/事件循环/错误兜底/截图），业务逻辑全部留在可单测的 PHP 里。
2. **打包** — `qtphp package` 组装自包含 `dist/`（windeployqt + PHP/PHPX 运行时 DLL + assets），并用「残缺 PATH 自检」证明不缺依赖。
3. **测试** — FakeBridge 让领域层用纯 PHP 单测（无需 Qt/编译）；stub↔cpp 符号一致性校验；无头渲染截图验收。

## 已确认的决策

| 决策 | 取值 |
|---|---|
| composer 包名 | `yangweijie/typephp-qt` |
| 命名空间 | `TypePHP\Qt\` |
| 桥接集成 | **源码内联**（应用 `project.yml` 直接把 `cpp-src/*.cc` 列进 `sources`）。预编译库路线已实测证伪，见下方 Decisions Log |
| v1 控件范围 | **常用全套**：窗口/布局、Label、Button、输入/多行/下拉/复选/单选/滑块/进度、表格、列表、树、标签页、分组框、滚动区、分割器、菜单栏、状态栏、文件对话框、消息框、定时器、系统托盘、剪贴板 |

## 环境（本机实测）

### Windows（Phase 1–9 交付环境）

- Qt: `D:\tools\Qt\6.9.3\msvc2022_64`（环境变量 `QT_DIR`）
- TypePHP: `D:\git\php\tpc_v0.9.4_windows_x64`（`tpc.exe` v0.9.4，`phpx/`）
- MSVC: `D:\Program Files (x86)\Microsoft Visual Studio\2022\BuildTools\...\vcvars64.bat`
- 平台: Windows 10.0.26100 / cmd.exe

### macOS（Phase 10–12 交付环境，2026-10-02 实测）

- 平台: macOS 26.6.2 / arm64，PHP 8.5.7（Homebrew，仅 cli SAPI），Apple clang 21.0.0
- tpc: `vendor/bin/tpc.php` v0.9.4（composer 装，`php` 起跑，后端识别为 `macOS + Clang`）
- Qt: `/opt/homebrew/opt/qtbase` 6.11.2（framework 布局；anaconda 的 Qt 5.15.2 遮蔽 PATH 里的 qmake，不用）
- 私有 PHP embed 运行时: **已建成并缓存** —— `$HOME/.typephp/php-builder/php-8.5.11-5852a1ce211cc711/install/lib/libphp.a`（`--enable-embed=static`），`doctor` 已能报出该路径
- 磁盘: 数据卷剩 ~11Gi

## 关键架构

```
        PHP（大脑，可单测）          C++ 桥（神经，极薄）           Qt（脸）
   ────────────────────────   ────────────────────────   ───────────────────
   TypePHP\Qt\QtApp         ─►  qt_window_render(tree) ─►  QMainWindow/QLayout
   TypePHP\Qt\WidgetTree        qt_window_poll_event()     QWidget 子树
   （声明式控件树 + 状态）       （id→widget 表 + diff）     （只显示/只上报）
```

- **桥接契约**：`php-src/qt.stub.php`（PHP 签名，空函数体）⇄ `cpp-src/*.cc`（`php_` 前缀符号）。
- **声明式渲染**：PHP 传控件树数组，C++ 按节点 `id` 做 diff —— 新增则建、消失则删、存在则只更新变化属性 → 保留控件状态（光标/选中/滚动）。无 `id` 的节点由 C++ 按**结构路径**（`_p0.1.2`）生成稳定 id。
- **状态驱动**：事件处理器只改状态，`view()` 注册的构建函数每帧按新状态重新描述界面。
- **事件**：所有信号只 `enqueue`，PHP 主循环 `poll_event` 取走并分派；处理器异常弹错误框但不中断循环。
- **AOT 约束**：全局作用域只能有声明（`require_once` 也是语句，不能放全局）；`main(int $argc, array $argv)` 必须是全局函数；跨文件声明靠 `project.yml` 的 `sources` 传递，不靠 `require`。

## Phases

| # | Phase | 状态 |
|---|---|---|
| 1 | 骨架与包定义（composer.json/目录/README） | ✅ done |
| 2 | 桥接契约 + 最小 C++ 实现（窗口/布局/label/button/事件循环/截图），**编译并跑通** | ✅ done |
| 3 | 控件目录全量 C++ 实现（diff 引擎 + 全套控件 + 菜单/对话框/托盘/剪贴板） | ✅ done |
| 4 | PHP 框架层（QtApp/WidgetTree/事件/错误兜底/FakeBridge） | ✅ done |
| 5 | CLI `qtphp`（doctor/new/build/run/package/test/lint） | ✅ done |
| 6 | 脚手架模板（project.yml/main.php/build|run|package.bat） | ✅ done |
| 7 | 测试（FakeBridge 领域测试 + stub↔cpp 符号校验 + 无头渲染验收） | ✅ done |
| 8 | 文档 + 示例应用 + 端到端最终验收 | ✅ done |
| 9 | 用户报障修复：AOT 闭包实参个数严格校验 + 无头模式 + `--selftest` | ✅ done |
| 10 | macOS 原生编译路线（跨平台 CLI + 平台条件 project.yml + Qt6 + 私有 embed 运行时） | ✅ done |
| 11 | macOS 运行/打包链路（`cmdRun`/`cmdPackage`/`deployRuntimeDlls` 曾只认 `.exe`+DLL） | ✅ done：11.1 run ✅、11.2 package ✅、11.3 运行时库定位 ✅、11.4 证伪+文档修正 ✅、11.5 参数透传（顺带修 `basename('.')`）✅、11.6 `qtphp new` 双平台模板 ✅ —— 全新项目在 mac 上 new→build→run --selftest→package→`env -i` 全链实测通过 |
| 12 | 控件行为验收（表格/树 diff 边界）+ `patch` 的 `call` 操作 + 多窗口/托盘/定时器示例 | ✅ done：12.1 表格/树 diff（揪出并修掉 4 个 C++ 真缺陷，新增 `--difftest`）、12.2 `call` 六个方法（含签名作废与鉴别力反证）、12.3 多窗口/托盘/定时器演示（补 `QtApp::isOpen()` + 托盘兜底图标）、12.4 bundle 自带 offscreen 插件（产物可无头验收）、12.5 Windows 分支同款幂等补拷（本机无环境，未实测）、12.6 Linux 打包不再冒充 macOS（显式三路 + 明确报错，F19）、12.7 Linux 首次在真机跑通（Apple Container + Debian 12 arm64 + Qt 6.4.2：build→run→offscreen 14/14 + 20/20 + 出图全 rc=0，F20+F21）、12.8 Linux `package` 真机实现并验收（`dist/<name>/` = ldd 闭包 + `patchelf` DT_RPATH + `qt.conf`，产物 `env -i` offscreen 下 14/14 + 20/20 + 出图全 rc=0，F22）、12.9 Linux 硬前置落到 `doctor`（`linuxMissingPackages()` 12 项，命令 + 多架构头文件两类探测，两条分支都真机逼验，F23）—— `--selftest` 14/14、`--difftest` 20/20、112 例单测全绿 |
| 13 | tpc 供给路线解析（Windows 上 composer 驱动抢占原生发行包 → `build` 报缺 `phpx.dll`） | ✅ done：`findTpc()` 改按**运行时体检**选路，删掉硬编码路径（F24）。Windows 端到端复验：`build` → `--selftest` 14/14 → `--shot` 21KB PNG → `test` 112/183 → `lint` 契约一致 |
| 14 | 更新 `typephp-qt-app` 技能为包优先 | ✅ done：技能原本只教手写桥接（源自 `qt-taskboard`），不知本包存在。改写为「路线 0 用包 / 路线 1 手写」双轨；新建 `references/aot-pitfalls.md` 收录 8 个 AOT 坑；SKILL.md 加 14 条硬规则；evals 4→6。逐项对照真实代码核实（22 方法 / 30 控件 / 10 事件 / 6 call / 7 子命令），`scaffold.sh` 实跑通过 |
| 15 | 技能入库到仓库 `.ohmyagent/skills/` | ✅ done：复制 25 个文件并修掉入库才暴露的三处问题 —— `.sh` 的 CRLF（Linux 上 shebang 会坏）转 LF、`git update-index --chmod=+x` 补执行位（Windows 下 `git add` 记成 100644）、新增 `.gitattributes` 固化换行符规则（`*.sh`→LF / `*.bat`→CRLF / 图片→binary）。从 git 索引导出后逐字节验证 + 实跑通过。**未提交**（已暂存） |
| 16 | VuePress 2 文档站 + GitHub Pages 部署 | ✅ done：`docs/` 下 30 页（指南 12 / 控件 5 / 深入 5 / 参考 5 / FAQ + 首页），VuePress 2 rc.31 + theme-default rc.137。新增两个构建期守门脚本（死链、跨页锚点），都做过鉴别力反证。`.github/workflows/docs.yml` 推 main 自动发布，base 按仓库名推导。事实核对：112 测试 / 24 桥接函数 / 30 控件 / 40 个 QtApp 方法 / 30 个 WidgetTree 方法 全部与代码一致。**待你手动开 Pages Source = GitHub Actions** |
| 17 | 搜索插件 + 中英双语 | ✅ done：接入官方 `plugin-search`（索引内联，按语言配占位）；站点改为**英文默认（根）+ 中文 /zh/**，各 29 页共 59 页。中文页 52 处站内链接改写为 `/zh/` 前缀。**顺带查实**：theme 内置的 links-check 只验目标文件、**不校验锚点**（注入坏锚点仍构建成功），故保留自建 `check-anchors.py`，`check-links.py` 改定位为产物级复核 —— 形成源码/产物/锚点三层防护。中英文件名一一对应，语言切换与双语言搜索均实测可用 |
| 18 | 修 docs CI（node 版本 + lock 不同步） | ✅ done：CI 日志里是两个独立问题 —— ① workflow 用 node 20，而 vuepress rc.31 要求 `>=22.18.0`（改 22）；② `sass` 是 theme 的**可选 peer**、未显式声明，npm 10 与 11 落位不同导致 CI 报 `Missing: sass@1.105.1 from lock file`（显式声明 + 用 npm 10 重生成 lock）。另加 `engines` 护栏把 node 要求变显式契约。**npm 10 与 npm 11 下 `npm ci` 均 rc=0** |
| 19 | 「hello 看不到托盘」排查与修复 | ✅ done：插桩 + 真实鼠标点击证明**托盘机制正常**（3 次点击 → 3 次 Trigger → UI 计数 3）；用户看不到的真因是 **Windows 默认把新图标收进溢出区**（注册表 `IsPromoted` 空）。排查中揪出并修掉 4 个静默缺陷：① 图标路径按 cwd 而非 exe 目录解析（文档承诺 exe 目录）→ 加 `qtResolvePath()`；② 显式路径加载失败静默变空图标致托盘不显示 → 逐级兜底 + 警告；③ `build` 不拷 `assets/`（且 macOS/Linux 分支连部署都没有）→ 加 `deployAssets()`；④ 示例与脚手架都无图标资源 → 各补一个。文档中英双语补「看不到图标」的排查小节 |
| 20 | 托盘只转发左击 → 补齐四种手势 + 右键菜单 | ✅ done：Qt 的 `ActivationReason` 有 5 种，桥接原先只转 `Trigger`。实测确认 Qt 会上报 `Context`(右击)/`DoubleClick`，遂全部转发，手势放 `$event['value']`（left/right/double/middle）。新增**托盘右键菜单**（`setTray([...'menu'=>[...]])`，复用菜单栏的 `parseMenuItems`/`buildMenu`，菜单项走 `menu` 事件）；绑菜单后右击由 Qt 接管、不再发 `right`（实测确认，已写入文档）。测试 +2（114 tests）、示例 selftest 14→17 例、示例与脚手架都加菜单演示、文档中英双语同步 |
| 21 | 桥接「能力静默丢失」审计（F25） | ✅ done：三方交叉比对（Qt 信号 19 / 属性键 52 / 文档声称）挖出 **5 个「文档说有、代码没有」的缺陷**，全修：D1 `margin`/`spacing` 根本没实现（文档写 4 处）；D2 `checkable` 按钮/group 不发 `toggle`；**D3 `build` 不部署 `qoffscreen.dll` → offscreen 下三个无头开关全崩**（而文档正让 CI 这么跑）；**D4 `onAny` 被 `on` 静默吃掉**（文档承诺「两个都触发」，单测却固化了旧行为 → 经确认改代码+改测试）；D5 `editable` 不支持 table。另按用户要求**补全信号**：press/release/commit/itemClick/cell/expand/collapse/close，toggle 扩展到 checkable button/group。测试 116（+2）、示例 selftest 25 例、文档中英双语同步 |
| 22 | 同步 README | ✅ done：+65/−18 行。事件表 10 → **18 个**（补 press/release/commit/itemClick/cell/expand/collapse/close，toggle 扩到 checkable button/group，tray 标手势）；托盘段改写（四种手势 + `menu` 右键菜单示例 + 相对路径规则 + 「看不到图标」排查）；属性补 `editable`/`closable` 与 `margin` 四元组；`build` 行补平台插件与 assets 部署；无头模式补 offscreen 中文渲染限制；测试数 112→116、selftest 14→25。**先跑交叉比对再改**（控件 30 / 属性 41 / 事件 18 / 命令 7 全部对着代码验过），避免 README 又一次「撒谎」 |
| 23 | 文档补 Qt 安装与编译（跨系统） | ✅ done：新增中英各一页 `guide/qt-setup.md`（共 +369 行）：最小模块集（只用 Core/Gui/Widgets，对着 `#include` 核过）、版本要求与 6.9.3 探测、Windows（官方安装器该勾什么 + aqtinstall）、macOS（keg-only + framework 布局，为何 `-I` 与 `-F` 必须同时给）、Linux（Debian 一条命令 + **Fedora/Arch/openSUSE 包名与扁平路径**）、`QT_DIR` 指定非默认版本、8 条排错表。**顺带修 2 个真问题**：① `findQt()` 把 `QT_DIR` 放在候选之后 → 有候选路径时被无视（最需要覆盖的场景），改为最优先；② `check-anchors.py` 的 slugify 把全角括号/斜杠直接删（VuePress 转成 `-`），把正确链接报成死锚点 —— 修后做了坏锚点注入反证 |
| 24 | 增加 webview 支持 | ✅ done：新增 `webview` 控件，**两种后端编译期二选一**（Session 25 的 Phase 25 起是**三种**） —— Windows 用 **WebView2**（完整 Chromium + JS；SDK 从 NuGet 取 1.0.4258.31 并 vendor 到 `third_party/`，只留 3 个必需文件约 200KB 压缩后，**用户零额外下载**），其余平台用 **QTextBrowser**（QtWidgets 自带，HTML 子集无 JS）。PHP 侧同一套 API：`WidgetTree::webView()` / `html()`，`QtApp::webViewBackend()` / `webViewSupportsJs()` 供降级判断。`qtphp build` 自动部署 loader 并加进依赖自检；脚手架默认带 WebView2 配置。测试 123（+7）、示例 selftest 25/25、文档 +2 页（中英）。踩坑：无 moc 故不能用 `Q_OBJECT`（改 `dynamic_cast`）、无 WIL 头、lint 只认 `Bool` 不认 `bool`、**尺寸塌陷伪装成渲染失败**（694×16 → 需 `Expanding`+`sizeHint`+`minimumHeight`）。**macOS 本机复验（Session 25，见 24.1/24.2）**：`project.macos.yml` 零改动即落到 QTextBrowser 后端（`sources` 继承 + `cxx-flags` 整体替换 ⇒ 不带 `QT_WEBVIEW2`、不链 loader），build→`--selftest` 25/25→`--difftest` 20/20→cocoa 真窗口出图全 rc=0，`html` 与 `url` 两条分支都真机验过（F26 尾部）。**Session 25 补验（24.3/24.4，F27）**：`link` 接线 ✅、`zoom` ✅ 确认静默忽略、远程 `url` ❌ Qt 6 `QTextBrowser` 不支持（文档 `webview.md:44` 需按后端分列能力）；**WebView2 分支本机零运行时证据**（无任何 Windows 目标），只交静态审查 W1–W5，并修掉 **W0**：`.gitignore` 的 `*.lib` 吞掉 vendor 的 `WebView2Loader.dll.lib` ⇒ 新克隆 Windows 链接必报 `LNK1181`（规则已放行，**该文件需从 Windows 机器补交**） |
| 25 | macOS 第三后端：WKWebView（`.mm`） | ✅ 实现 + 真机验收完成（24.8 / F29 §6）：新增 `cpp-src/qt_webview_wk.mm`（256 行），用**系统 WebKit** 做第三个编译期后端 —— 零安装、产物不涨（25,316,424 B vs 旧 25.5 MB 同一量级）、支持 JS + 远程 `https://` + `data:` + `zoom`。`url`/`html`/`zoom` 与 `navigating`/`loaded`/`title`/`link` 四事件全部按 `qt_webview.cc` 的契约对齐并**用事件读回**证明（`title=WK-JS-42`、`Example Domain`、`pageZoom=2.50`、链接点击 `type=0` 被拦下且未跳转）；不靠像素，因为原生子 view 不进 `QWidget::grab()`（F29 §5）。构建接线：mac 入口与 `qtphp new` 模板重写 `sources`（tpc 对 list 是**整体替换**）+ `-DQT_WEBVIEW_WK -fobjc-arc` + `-framework Foundation/AppKit/WebKit`。修掉一个新引入缺陷：offscreen 的 `winId()` 非 ObjC 指针 ⇒ `--shot` SIGSEGV(rc=139)，`attach()` 加 cocoa 平台闸门后 rc=0。控制组证明归因干净：关掉开关帧与旧基线 `a70cd7ae05b6…` 逐字节相同；开启后新基线 cocoa `2728fb1a6a06…`、offscreen `6c4832a6b738…`（各 8/8 稳定）。**控制组**：只注释 `-DQT_WEBVIEW_WK` 重建 ⇒ 帧与旧基线 `a70cd7ae05b6…` **逐字节相同**，开/关像素差 129,830 px（23.73%）、包围盒 x[33..726] y[454..664] 完全落在 WebView 分组内、y=454 以上零差异 ⇒ 其余控件未被触碰。 脚手架闭环：`qtphp new demo` → 生成的 mac yml 含 `.mm`/`-DQT_WEBVIEW_WK`/`-framework,WebKit` → `build .` rc=0（25,316,424 B）→ `--selftest` passed。第 ④ 步收口见 26 行 |
| 26 | `--shot` 收口：三后端能力定稿 + 瞬态动画冻结 | ✅ done（24.9 + 24.10，F28 §4/§5）：① `webViewBackend()` 定为三值 `webview2｜wkwebview｜textbrowser`，新增「后端 × JS 能力」矩阵测试，并修掉**替身说谎**（`FakeBridge::qt_webview_supports_js()` 原写 `=== 'webview2'`，会把 wkwebview 报成不支持 JS）；stub / `QtApp` / `WidgetTree` / 中英 `reference/api.md` / `guide/qt-setup.md` / `php-src/qt.stub.php` 全部改三后端口径。② 示例里那段「无条件泵 120 帧」的 TEMP：先按后端收口 ⇒ **被控制组否证**（关开关后 3 帧 8 次跑出 4 种哈希），真因是 `QLineEdit` 清除按钮淡入淡出 ⇒ 最终解法是在 `snapshot()` 里 `findChildren<QAbstractAnimation*>()` 把在跑的动画 `setCurrentTime(totalDuration())`+`stop()`，配方降回 `runFrames(3)`。③ 三条基线值在新配方下**未漂移**（cocoa `2728fb1a…` 8/8、offscreen `6c4832a6…` 8/8、关开关 `a70cd7ae…` 8/8），`--shot` 445 ms；selftest 25/25、difftest 20/20、phpunit 124/**209**、lint 一致；脚手架 demo build rc=0 → selftest passed → `--shot` ×8 distinct=1。中英 `headless.md`、`bin/qtphp` 模板、技能 references 同步。**未验**：同一配方在 Windows / Linux 上的 ×8 收敛。 |
| 27 | 补交 WebView2Loader.dll.lib + WebView2 分支 W1–W5 真机验证 | ✅ done：**补交** —— 该文件（3590 B 导入库）被 `.gitignore` 的 `*.lib` 吞掉未入库，本轮从 Windows 机器补进仓库，并用**克隆到干净目录编译**验证 LNK1181 消失。**真机验证 W1–W5**（Session 25 在 macOS 只能静态审查）：W1 ✅ 成立（`loaded` value 恒空 + 泄漏）、W2 ✅ 成立（`navigating` 慢一拍）、W3 ❌ **不成立**（实测不泄漏：4 并发峰值 9 → 全销毁 0，因 `cleanup()` 会 `delete window_` 连带回收控制器）、W4 ⚠️ 真实风险但窗口窄（20 轮 40 回调 0 次悬空，因环境是进程级单例）、W5 ✅ 成立。五条**全部修复并真机复验**；顺带补上 WKWebView 的 reload/goBack/goForward（否则新 API 在 macOS 静默失效）。测试 126（+3）、文档能力矩阵中英同步。**方法教训**：阳性对照（`--hold` 先数到 6）才让「数到 0」有意义；按 `--user-data-dir` 过滤自己的进程，别碰 Oray/向日葵 的 24 个 |
| 28 | mac 打包侧收口：体积口径修正 + bundle 内资源解析 | ✅ done（28.1，F31）：先量真实体积 —— `qtphp package` 打印的 `99.8 MB` 虚高 ~19%，真实 **83.6 MiB**（87,612,051 B / 54 文件），`du -sh` 84 M，zip 分发 **29.3 MiB**；最大单项是 `libicudata.78.dylib` 31.66 MiB（38%），产物二进制 21.55 MiB（`macdeployqt` 会 strip：`nsyms` 121,795→6,890），PHP 静态链在里面、无 `libphp.dylib`。webview 在打包产物里**可用**，三条独立证据：bundle 内 `--shot` 与开发态基线逐字节同（`2728fb1a…`）且分组标题读图为 `backend=wkwebview，js=支持`、`otool -L` 显示 WebKit 走系统框架（目标机不需装 brew Qt）、`open` 后新起 `com.apple.WebKit.WebContent` 进程（XPC 父进程是 launchd ⇒ 判据必须是启动前后 PID 集合做差）。修掉两处打包缺陷：**P1** `dirSize()` 跟随符号链接 ⇒ framework alias 重复计数；**P2** 打包后 assets 在 `Contents/Resources` 而解析器只查 exe 目录 → cwd ⇒ 托盘图标静默丢（窗口像素不受影响，`--shot` 抓不到）。P2 走 bundle-aware 解析（exe 目录 → `Contents/Resources` → cwd，且只在父目录名为 `Contents` 时启用，避免非 bundle 平台被项目根的 `Resources/` 抢先命中），**负控制**：把 `Resources/assets` 改名后警告立刻回来。复验：package 打印 83.6 MB、bundle 从 `cwd=/tmp` 与 `cwd=/` 出图均无警告且哈希仍 `2728fb1a…`、offscreen `--selftest` passed、开发态二进制行为不变、`phpunit` **126/212**、`lint` 契约一致、`codesign --verify --deep --strict` 通过。中英 `packaging.md`/`dialogs.md` + README 同步解析顺序。 |
| 29 | 打包验收补「产物内 stderr 无告警」判据 | ✅ done（29.1，F32）：把「资源加载失败只能靠人眼扫日志」变成自动化判据 —— `verifyAppBundle()` 在 bundle 内以 `QT_QPA_PLATFORM=offscreen` 真跑一遍 `--selftest`，要求输出含 `selftest passed` 且 stderr 无要拦的 warning。用 `QT_MESSAGE_PATTERN=%{type}|%{category}|%{message}` 给每条 Qt 日志加前缀，再白名单掉 Qt 平台插件恒常的无害行（`qt.qpa.fonts` 字体别名耗时、`This plugin does not support …`），剩下的 warning 才指向真实缺陷。**负控制**：藏掉 `examples/hello/assets/icon.png` 再 package ⇒ `[ERROR] 产物启动时告警…warning|default|tray icon could not be loaded: assets/icon.png`、rc=**1**；还原后 rc=**0**、打印 83.6 MB。健康产物本身就有 2 条无害 warning ⇒ 白名单是必要的，否则正例误报。phpunit 126/212、lint 契约一致。**未做**：Linux（`verifyLinuxPackage` 现在只打印无头验收命令、不执行）与 Windows 侧的同款判据；`--selftest` 失败时进程退出码仍是 0（这里靠 `selftest passed` 文本兜住）。 |
| 30 | 实测 `tpc --nano`：qt 应用体积与可运行性 | ✅ done（30.1，F33）：nano 编得出来 —— 250 个 TU（php-nano 裁剪后的 C/C++ + phpx 29 项 + 我们的 4 个 `.cc/.mm`，**含 mac WKWebView 后端**）全过，产物 **4,370,536 B（4.17 MiB）**、strip 后 3,639,504 B（3.47 MiB），同项目普通模式 `build/hello` 25,569,608 B（24.39 MiB）⇒ **二进制降 ~21 MB（83%）**；`otool -L` 只剩 Qt framework + 系统框架 + libiconv/libc++ ⇒ 确实不链 libphp、不依赖 phpx 动态库。我们的 PHP 侧（QtApp/WidgetTree/main.php/stub）**全部通过 nano 前端能力策略**。两个前置：`--nano` 要 C++17（示例 yml 是 c++20，需 `--cxx-std c++17`）；入口 yml 必须去掉 `php-builder:` 段（留着会在 nano 下仍去准备私有 embed 运行时，本机 curl 404 而 rc=255）。**但产物跑不起来**：`Unable to start PHP Nano extensions`、rc=1，且 5 行最小 nano 程序同样中招 ⇒ 与 Qt 代码无关。根因读到位：生成的模块入口声明 `ZEND_MOD_REQUIRED("Core")`，而 `find_available()` 只在传入数组里按 name 找，php-nano 里唯一叫 "Core" 的 `zend_builtin_module` 是 `static` ⇒ 依赖结构上永不可满足。我的「删一行再重编」实验**不成立**（tpc 按 target 名重新生成该文件），未做 tpc codegen 改动。推算（未实测）：若修好，`.app` 从 83.6 → ~66 MiB，**省的是 PHP、省不动 Qt**（ICU 31.66 + Qt 15.6 才是大头）。30.2（Session 27，F35）按用户给的官方文档重核：卡点**唯一**且**应用侧不可规避**（"Core" 正是 `strlen`/`function_exists`/`class_exists` 所在的扩展，实测宿主 PHP 8.5.7 反射），phpx 版本旧 / vendor 供给方式不对 / 还有第二个不匹配依赖 三条解释全部否证，姊妹仓库当前源码与 v0.9.4 在这两处逐行一致 ⇒ 等发版修不好；另记一处与文档的 Windows `--nano` 口径冲突，两边都无实测故均标未验证。 **30.3 更新（本节两条结论就此作废/更新）**：① 「跑不起来」已由上游 codegen 补丁修好并在本机真机验证 —— nano 下 deps 降为 `ZEND_MOD_OPTIONAL`，`--selftest` 25/25、`--difftest` passed、`--shot` 出图与 embed 基线逐字节相同，同一树还原成 REQUIRED 立刻回到 rc=1；② 本行 4,370,536 B 是 **`-O2` 档**的数字，而 30.1 没把 `-O2` 记下来；同档重编逐字节可复现（30.3 实测），默认 `-O0` 则是 6,988,648 B ⇒ 引用必须带档。 **30.4 更新（Phase 32）**：本行「入口 yml 必须去掉 `php-builder:` 段」这条前置**已不再需要** —— 改为在 tpc 侧把 nano 下的 `isPhpBuilderBuild()` 关掉，现有 `project.yml` + `project.macos.yml` 原样可用于 `--nano`。 |
| 32 | 把 nano 接进 `bin/qtphp`（`build --nano`）+ 双语文档 | ✅ done（32.1–32.3，F36）：`qtphp build <path> --nano` 打通并在 Apple Silicon 真机三向验过（nano 编、跑、切回 embed）。① `cmdBuild` 收 `--nano`（其余选项报错退出，不静默忽略），实际下发 `tpc <entry> -O2 --nano --cxx-std c++17` —— `--cxx-std` 在 CLI 上覆盖，**不需要每个项目多一份 nano 入口 yml**；② tpc 侧 `CompilerBase::isPhpBuilderBuild()` 加 nano 门（`typephp-qt/vendor` 与 `typephp-compiler/src` 两处同步），nano 下不再去现编/下载私有 embed 运行时，同时保留 `nanoPolicyMode`（仍链宿主 libphp）那条路上 `php-builder:` 的语义；③ Windows 分支在 nano 下跳过 `deployRuntimeDlls()`（产物不导入 `php*.dll`，tpc 自己的 `NativeDependencyAuditor` 见到就把构建判失败）。**真机**：nano 产物 4,403,640 B（strip 3,672,352 B）、`otool -L` 只剩 Qt 三件套 + 系统框架 + brew libiconv + libc++/libSystem、日志里 `php-builder` 出现 **0 次**、`Auditing Nano runtime dependencies` 后 rc=0；`--selftest` 25/25、`--difftest` 20/20、`--shot` cocoa `2728fb1a6a06…` / offscreen `6c4832a6b738…` **与 embed 基线逐字节相同**。切回 embed：只重编少数 TU（log 报 12 files）后产物回到 **25,569,608 B**（与基线等值）、`-lphp` 回来了 ⇒ 同一 `build/` 目录跨模式复用增量缓存**不是 no-op、也没被污染**。回归：phpunit **127/215**、lint 契约一致、`check-anchors.py docs/src` rc=0；**上游侧 A/B 实测零回归**（`typephp-compiler` 里 `--filter '(PhpBuilder|Nano)'` 打补丁 vs HEAD 两臂逐位相同：`Tests: 73, Assertions: 206, Errors: 3, Failures: 1`，那 4 个坏点分别是 `swoole/php-nano` 未装 ×3 与 mac `/var`→`/private/var` 路径断言 ×1，都与本改动无关）。**未验**：`.app` 打包链 × nano 产物、Windows/Linux 侧 nano；产物体积差改按 F37 口径（32,712 B 仍未归因）。 **32.6 更新（Session 28）**：`-O2`/`cxx-std` 已固化进入口 yml（`examples/hello/project.yml` + `qtphp new` 模板）、中英 cli.md 与 README 同步；裸 `tpc --nano` 无任何 CLI 覆盖直证通过（`Translator.php:661` 严格校验 + 250 TU 编出可用产物，出图与基线逐字节同）；`-O0` 对比 6.99 MB vs 4.37 MB 直证 `optimize: 2` 生效；embed/nano 产物与旧基线**同值**、四件套全过；发现上游缓存缺陷（换 output 名 → undefined symbols，复现与影响面见 F37 §2）。 |
| 31 | 无头验收开关按退出码报失败 + `lastError` 归因修复 | ✅ done（31.1，F34）：`--selftest`/`--difftest`/`--shot` 失败时不再 rc=0 —— 示例与 `qtphp new` 脚手架模板三处全部改成失败 `exit(1)`，`qtphp run` 原样透传，`verifyAppBundle()` 把 rc 升为主判据（文本比对留着接住「自写自检不改退出码」的应用）。`--shot` 失败原先**静默**（`$ok` 取了不用），现打印 `snapshot failed: <path>` 并 rc=1。**实测正反两向**：25/25、20/20、出图均 rc=0；注入一条 `throw` ⇒ 该用例 FAIL、rc=1；`--shot /no/such/dir/x.png` ⇒ rc=1（含穿过 `qtphp run`）；打包正向 rc=0（83.6 MB）、藏掉 `assets/icon.png` 的负向仍 rc=1。出图基线未漂移（cocoa `2728fb1a6a06…`、offscreen `6c4832a6b738…`）。**造负控制挖出两个 AOT 坑**：`1 % 0` 在 AOT 里**不抛** `DivisionByZeroError`（自检照旧 passed，所以指望运行期自动抛的断言不可信，必须显式 `throw`）；`echo $cond ? "a: ", $x, "\n" : "b\n"` 被 tpc 拒收（`Syntax error, unexpected ','`，脚手架第一版就这样 build rc=1）。**顺带修一处诊断缺陷**：`QtApp::safely()` 不清 `lastError` ⇒ 一处 handler 异常后，其后每次分发都冒充「最近一次异常」（实测 21 条 FAIL 全指向同一行），改为每次分发前清空 + 新增单测锁定，修后同一注入只报 1 条。phpunit **127/215**（+1）、lint 契约一致、`check-anchors.py` 全绿。**未验**：Linux/Windows 侧的同一 rc 语义（`verifyLinuxPackage` 仍只打印验收命令、不执行）。 |
| 33 | `.app` 打包链 × nano 产物验收 + 33,312 B 体积差归因结案 | ✅ done（F38，Session 28 收尾）：干净 nano 产物（4,370,328 B）过 `qtphp package` rc=0 ⇒ `dist/Hello.app` **67,248,220 B（64.1 MiB）/ 46 文件**，对照 embed 版 87,612,051 B / 54 文件 / 83.6 MiB ⇒ **−23.2%**；bundle 内 `otool -L` 全 `@executable_path` 无 libphp、`codesign --verify --deep --strict` 通过、`verifyAppBundle()`（offscreen `--selftest` + stderr 白名单）通过、两条出图哈希与开发态基线逐字节相同（cocoa `2728fb1a…` / offscreen `6c4832a6…`）、读图 `backend=wkwebview，js=支持`。**体积差结案**：四步单变量实验（清 cache 首建 4,370,328 / 先 embed 再 nano 4,403,640 精确复现 / nano-after-nano 粘滞 / 清 `build/cache/{objects,incremental,link}` 复位）＋节级 forensics 钉死是字面量字符串表风味（`_literal_strings`×2 + `__GLOBAL__sub_I…`）：`Translator.php:498-506` nano 下强制 `noLiteralStrings=true`，干净首建遵从，但 embed 轮风味被增量缓存沿用；行为零差异；归上游缓存缺陷家族（F37 §1 的 32,712 B = 33,312 − 600 目标名长差，**已结案**）。文档修正：README + 中英 cli.md 的 4,403,640→**4,370,328**、3,672,352→**3,639,472** + 干净首建口径与模式切换注记。**教训 21**：产物内嵌编译时间戳 ⇒ 跨轮次二进制不可逐字节 diff（尺子 = 尺寸 + 符号 + 出图哈希）。 |
| 34 | 修 `.app` ICU 自包含缺陷（F39） | ✅ done：`vendorBundleDeps($appBinary)` → `vendorBundleDeps($contents)` —— 队列从 Contents **全部 Mach-O** 出发（新增 `bundleMachOFiles()` 魔数判 + realpath 去重、`machOLoadRefs()` 剥 framework 自 ID），已在 Frameworks 的直接 `-change`、不在的补拷再改；`verifyAppBundle()` 从只扫主二进制扩到全 bundle，报错带「文件 → 引用」。**负控制**：停掉改写 ⇒ package rc=1 且精确列出 `QtCore → brew ICU` 三条（旧自检看不见）；**决定性验证**：藏 brew `icu4c@78` 跑修复产物 **rc=0 selftest passed**、ICU 从 bundle 内加载（修前 rc=134）。回归：difftest passed、cocoa `2728fb1a…`/offscreen `6c4832a6…` 同基线、codesign 通过、phpunit 127/215、lint 契约一致。**顺带查明**：主二进制与其余依赖 macdeployqt 本会处理，全 bundle 扫描挖出的唯一遗漏就是 framework 内部 ICU（为何漏未挖）。中英 `packaging.md` 自检机制同步（含「env -i 挡不住文件系统依赖」更正）。 |

### Phase 10 分解（macOS）

- [x] 10.1 无头验证 PHP 领域层：示例 `--selftest` / `--shot` 在 mac 上跑通（FakeBridge）
- [x] 10.2 `findTpc()` 跨平台：补 `*.php` 入口候选、`where`/`command -v` 分支、`tpcCommand()` 用解释器起 `.php`
- [x] 10.3 补 Qt 环境：`brew install qtbase`（6.11.2），实测出 mac 可用的 include/link flag 组合（见 F11.4）
- [x] 10.4 `examples/hello/project.yml` 改平台条件：sources 转相对路径；新增平台入口 yml 覆盖 Qt 段（初版名 `project.darwin.yml`，后按上游约定改 `project.macos.yml`）；`cmdBuild()` 按 `PHP_OS_FAMILY` 选入口（**Windows 入口仍是 `project.yml`，保证 `build.bat` 不破**）
- [x] 10.5 `findQt()` 支持 macOS 前缀（`/opt/homebrew/opt/qtbase`、brew keg-only、`QT_DIR`）—— `doctor` 已报 `[OK] Qt`
- [x] 10.6 过 embed 闸：`php-builder` 私有运行时已建成 —— `install/bin/php`（23 MB）+ `install/lib/libphp.a`（62 MB）+ `phpx-build/lib/libphpx.a`，缓存于 `$HOME/.typephp/php-builder/php-8.5.11-5852a1ce211cc711`，第二次构建直接复用
- [x] 10.7 tpc 真编译 `hello`：10 个翻译单元（含 `cpp-src` 2 个 Qt 单元）全过 clang++ → 链 Qt framework → 产出 `examples/hello/build/hello`（Mach-O arm64，24 MB，`otool -L` 确认 QtWidgets/Gui/Core 6.11.2 + libiconv/gmp/mpfr/onig）
- [x] 10.8 真实二进制验收：`--selftest` **10/10 全过并干净退出**；`--shot` 出 760×560 PNG，菜单/分组/进度条/状态栏全部渲染正确（`QT_QPA_PLATFORM=offscreen`）

### Phase 11 分解（macOS 运行/打包，已全部完成）

- [x] 11.1 `cmdRun()`：非 Windows 按 `build/<name>`（无 `.exe`）找产物；依赖检查换成 `checkSharedLibraryDeps()`（`otool -L` + 跳过系统库）。实测：`qtphp run examples/hello` 正常起进程、不再误报 DLL；`deployRuntimeDlls()` 只在 Windows 分支调用
- [x] 11.2 `cmdPackage()` mac 路线：产出 `dist/<Name>.app`（`Info.macos.plist` + `macdeployqt -always-overwrite -no-codesign` + ad-hoc `codesign --force --deep --sign -` + `plutil -lint`/`codesign --verify`）。实测 bundle 99.4 MB、`otool -L` 无 bundle 外绝对路径、**`env -i` 裸环境下 `--selftest` 10/10 通过**（默认 cocoa 插件）
- [x] 11.3 `findTpcDir()` → `findRuntimeLibDir()`：不再拿 tpc 入口的 `dirname` 当「运行时库目录」（composer 装只有 `vendor/bin/tpc.php`，旁边一个库都没有）。新解析器按**标记文件**筛候选：native tpc 同目录 → `TPC_RUNTIME_DIR` → `~/.typephp/php-builder/php-*/install/lib`（取 mtime 最新）。`deployRuntimeDlls()` 找不到时明确警告并给补救命令，不再拼出 `/phpx.dll` 静默复制 0 个文件；`cmdNew()` 里那行从未被模板插值的死 `$tpcDir` 删除；`doctor` 新增「PHP 运行时库」检查项，并把 Windows 专属的 MSVC 检查改成按平台分派（mac 现在报 `clang++`）。实测 4 条路径：无库→NULL、`TPC_RUNTIME_DIR`→命中、真实 HOME→`~/.typephp/.../install/lib`、`deployRuntimeDlls` 无库时输出 2 条可操作警告且无 PHP 警告
- [x] 11.4 **前提证伪并修正文档**：仓库根 `project.yml` 根本不存在 —— `find . -name '*.yml'` 只有
  `examples/hello/{project.yml,project.macos.yml}`，`git log --all -- project.yml` 零记录（从未入库），
  `.gitignore:13` 反倒把 `/project.yml` 当「生成物」忽略；它只出现在 README 项目树和 Session 1 文件清单里，
  是幻影条目 —— 所谓「仍硬编码 D:/」是对一个不存在的文件的描述。
  也不需要补：桥接 stub 函数体恒空，`tpc -m lib` 路线已被 F7 实测证伪，桥接 `.cc` 只能作为应用
  `sources` 的一部分参与编译，「单独验桥接能否编译」的正确做法本来就是 `qtphp build examples/hello`。
  改动：README 项目树删掉幻影 `project.yml`、列出两个真实入口 yml 并说明验证路径；CLI 表按平台重写
  （build 的入口挑选、run 的 `otool -L` 自检、package 的 `dist/<Name>.app`）。
  复核剩余 `D:/` 硬编码：`bin/qtphp` 的 4 处都是 `is_file`/`is_dir` 候选（mac 上永不命中，无害），
  `cmdNew()` 的 `findQt() ?? 'D:/tools/…'` 属 11.6，`examples/hello/project.yml` 是**故意的** Windows 入口。
- [x] 11.5 `cmdRun()` 透传参数：签名改 `cmdRun(string $path, array $args = [])`，`main()` 用 `array_slice($argv, 3)` 把第 3 个参数起全部递进去；拼接改成 `escapeshellarg($exe)` + 逐个 `escapeshellarg($arg)`（顺带替掉原来手搓的双引号）。实测：`qtphp run examples/hello --selftest` → 10/10；`--shot "/tmp/qtphp 11 5.png"`（带空格）→ 760×560 PNG 正常落盘；用假产物脚本（echo 参数 + `exit 7`）验证参数原样到达且**退出码逐位传递**（rc=7）。
   **顺带修掉一个跨平台真缺陷**：`basename('.')` 返回 `.`，所以 `qtphp run .` / `qtphp package .`
   （README 教的、`package.bat` 用的就是这个写法）把产物算成 `./build/.`，而 `file_exists()` 对目录为真，
   校验形同虚设 ⇒ 新增 `projectDir()`（`rtrim`+`realpath`）供 `cmdRun`/`cmdPackage` 用（见 F11.11），
   实测三种点路径 `build . / run . --selftest / package .` 全通。README 同步：`run` 行写明透传，无头验收示例改成 `qtphp run <path> --shot/--selftest` 平台无关写法，上手第 4 步不再只列 `.bat`，测试数 89→103
- [x] 11.6 `qtphp new` 补 mac 入口：新项目现在一次写出 `project.yml`（Windows 段，模板未动）+
  `project.macos.yml`（`include: [project.yml]` 后整体替换 include-paths/link-libs/link-paths/cxx-flags/ld-flags，
  加 `php-builder`）+ `Info.macos.plist`（`CFBundleExecutable` = 项目名，正是 `packageAppBundle()` 拷进
  `Contents/MacOS/<name>` 的那个名字）。brew 前缀不写死：拿 `findQt()` 的结果反推 `<prefix>/opt`
  （实测 `D:/tools/Qt/...` → 回落 `/opt/homebrew/opt`、`/usr/local/opt/qtbase` → `/usr/local/opt`、
  `/opt/homebrew/opt/qt` → `/opt/homebrew/opt`），Intel 与 Apple Silicon 都写得对。
  生成的 README 与「下一步」提示也按平台分派。
  **端到端实测（全新目录，零手改）**：`qtphp new demo` → `build .`（10 个 TU 全过，产出 `build/demo`）
  → `run . --selftest` passed → `package .` 出 `dist/Demo.app` 99.3 MB → `env -i` 裸环境 `--selftest` passed、
  `plutil -lint` OK。临时项目已删。README 的新项目产物说明同步。
  **刻意不补 `build.sh`/`run.sh`**：mac 侧每条就是一个 `qtphp <子命令> .`（`.bat` 存在的理由是还要
  `call vcvars64.bat` 和查 `build\<name>.exe`），生成的 README 已按 Windows/`.bat` 与 macOS/CLI
  两栏分开写，再加一层薄脚本只会多三个要维护的文件。

### Phase 12 分解（控件行为验收）

- [x] 12.1 表格/树 diff 边界：新增 `--difftest`（真实 AOT 二进制 + `QT_QPA_PLATFORM=offscreen`）跑 12 条断言，
  首轮就 **7/12 失败** —— 证实「表格选中/滚动位置在重渲染后保留」这句招牌承诺此前是空的。四个根因（详见 F13）：
  ① `buildNode()` 里重建成品在 `applyNodeProps()` **之后**，`current` 先被props 设置、随即被重建抹掉；
  ② 表格/树每帧**无条件**重建（结构键没进 `propSigs_` 变更判定），插一行就丢选中；
  ③ `patch()` 的 `set` 分支把**操作数组**（不是 `props`）传给重建函数，`qtField(node,"rows")` 恒取不到，
    于是 `setRowCount(0)`/`clear()` 把控件**清空**；
  ④ 结构键跳过表是**类型无关**的，`QTextEdit` 的 `rows` 属性被误当作表格结构键而永久失效。
  修法：`qtIsStructuralKey()`/`qtStructuralKeys()` 按类型判定 + 新增 `QtWindowBox::structuralChanged()`
  （复用 `propSigs_` 签名），重建移到 props 之前且仅在结构真变化时执行；`patch()` 改从 `props` 取字段并同样跳过结构键；
  `qtRebuildTable`/`qtRebuildTree` 在非数组输入时**提前 return 不做破坏性调用**，重建后按 `Qt::UserRole` 的
  **行 id / 节点 id** 恢复选中（`row_ids` 缺失时退化成索引），并在 `QSignalBlocker` 内完成以免发假 `select` 事件；
  `columns` 为空时列数按最宽行放宽（控件默认构造是 `QTableWidget(0,1)`）。
  实测：`--difftest` **12/12 ok**、`--selftest` passed、`--shot` 760×560 PNG、`qtphp test` 103 tests/162 assertions OK、`qtphp lint` 契约一致。
  遗留：`FakeBridge::qt_fake_default_value()` 对 `table`/`tree`/`list`/`combo` 返回 `null`，与真实桥接的数组/字符串形态不一致（F13 尾部）。
- [x] 12.2 `qt_window_patch` 的 `call` 操作落地（原为 `Q_UNUSED` 预留桩）：实现 `appendRows` / `clear` / `setText` /
  `setValue` / `select` / `focus` 六个方法，`args` 统一按**位置参数**取（不给每种方法发明一种对象形状）。
  `select` 直接复用 `qtApplyProp("current")`、`setValue` 按控件类别在 `text`/`value` 间分派 —— 不让命令式与声明式两套口径漂移。
  **核心技术点**：新增 `QtWindowBox::forgetProps(id, keys)` 作废被命令式改过属性的 diff 签名，
  否则 `clear` 之后下一次 `render()` 算出的 `rows` 签名与存储值相等 ⇒ 不重建 ⇒ **表永久为空**（详见 F14）。
  新 C++ 辅助：`qtAppendTableRows()`（尾追不清空、写 `Qt::UserRole` 行 id、按最宽行扩列）、`qtClearContent()`（按类型清）。
  FakeBridge 同步六个方法 + 「未知 id 整条跳过」守卫（与真实桥接一致）；stub docblock 与 README 新增「增量补丁」章节。
  实测：`--difftest` **20/20**（新增 8 条 `call` 断言）、`--selftest` passed、`--shot` 760×560、
  `qtphp test` **108 tests / 173 assertions**、`qtphp lint` 契约一致。
  **鉴别力反证**：把 `forgetProps()` 临时改空操作重编译 → 第 16 条精确失败为 `{"row":-1,"value":""}`（预测的永久空表），恢复后 20/20 —— 证明该断言不是空跑。
- [x] 12.3 示例补多窗口 / 托盘 / 定时器演示（`--selftest`、`--shot`、`--difftest` 三者保持全绿）。
  **顺带补的两处真实缺口**（详见 F15）：
  ① `QtApp::isOpen()` —— `run()` 只泵自己那个窗口，多窗口必须自己按帧轮流泵，而示例的泵循环条件
    需要「主窗口还开着吗」这个问法（此前只有内部用的 `qt_window_is_open`）；
  ② `setTray()` 的**兜底图标** —— 原来只在 `icon` 非空时 `setIcon()`，而 macOS/Linux 上无图标的
    `QSystemTrayIcon` 根本不显示，「托盘演示」会是看不见也点不到的空壳 ⇒ 依次回退窗口图标 →
    `style()->standardIcon(SP_ComputerIcon)`（加 `#include <QStyle>`）。
  示例侧：`setTimer('clock',1000)` + `on('clock','timer')` 心跳、`setTray()` + `onAny('tray')`
  （托盘事件不带 id，只能 onAny）、`open_log_btn` 懒建副窗口（`new QtApp()` + `createWindow`，
  `qt_app_create` 幂等）+ 日志表用 12.2 的 `patch/call appendRows` 追加、`main()` 末尾换成双窗口泵循环。
  **使用前提**：副窗口 `render()` 后必须先泵一帧才能 `appendRows`（`patch` 查的是已存在的控件，否则首条日志静默丢掉）。
  新增 `--selftest` 4 条用例（open_log_btn / timer / tray / close_log_btn）与 4 例 PHPUnit
  （`isOpen` 生命周期、未建窗口不抛、副窗口独立泵、tray+timer 分派）。
  **AOT 新验证**：`date('H:i:s')` 在编译产物里可用；`QtApp` 实例存进 `$state` 再 `instanceof` 取出可行。
  实测：`--selftest` **14/14**、`--difftest` 20/20、`--shot` 760×560（「实时」分组已在图上）、
  `qtphp test` **112 tests / 183 assertions**、`qtphp lint` 契约一致；
  离屏泵循环 + 真实 QTimer 跑 3 秒进程存活、日志仅 1 行字体噪声。
- [x] 12.3 之后复验打包链（Session 7）：`package` → `dist/Hello.app`，`env -i` 裸环境（cocoa）
  `--selftest` **14/14**、`--difftest` **20/20**、`--shot` 760×560 出图，三条均真 rc=0。
  **新边界（F16）**：bundle 的 `Contents/PlugIns/platforms/` 只有 `libqcocoa.dylib` ⇒ 对**产物**设
  `QT_QPA_PLATFORM=offscreen` 会 SIGABRT（真 rc=134）；build 目录二进制能 offscreen 是靠开发机的
  `/opt/homebrew/share/qt/plugins/platforms/libqoffscreen.dylib`，该能力不随 bundle 走。
  顺带纠正两处：`headless(true)` 与 QPA 平台无关（只绕模态框）；`cmd | tail` 后 `$?` 是 `tail` 的，
  差点把 SIGABRT 记成通过 ⇒ 测退出码一律 `out=$(cmd 2>&1); rc=$?`。
- [x] 12.4 让 bundle 支持无头验收（Session 8，F17）：`bin/qtphp` 新增 `vendorHeadlessPlugin()` ——
  macdeployqt 之后把 `libqoffscreen.dylib` 拷进 `Contents/PlugIns/platforms/`，并把 `@rpath/Qt*` 引用
  改写成 `@executable_path/../Frameworks/...`（macdeployqt **不修 LC_RPATH**，只能照它改引用的做法）；
  插件同时进 `vendorBundleDeps()` 队列兜绝对依赖；找不到插件只 warning。
  实测：bundle `env -i` + offscreen → `--selftest` **14/14 rc=0**、`--difftest` **20/20 rc=0**；
  cocoa → `--shot` 760×560 rc=0；`codesign --verify --deep --strict` OK；包体 99.6 → 99.7 MB。
  **Windows 侧未做**（本机无环境，`qoffscreen.dll` 是否随 windeployqt 部署未实测）。
- [x] 12.5 Windows 分支同款（Session 9，F18）：`vendorWindowsOffscreenPlugin()` —— windeployqt 之后
  **幂等**补拷 `platforms/qoffscreen.dll`（已存在就跳过；源找不到只 warning）。写成幂等是因为
  windeployqt 的默认插件清单没查到权威结论，不押注它的行为。
  **本机无 Windows 环境 ⇒ 这条分支未实测**；安全性靠同构论证（`qwindows.dll` 同样从 `platforms/` 加载、
  解析 exe 同目录的 `Qt6*.dll`）。macOS 侧回归实测：重打包 99.7 MB、bundle offscreen `--selftest` 14/14 rc=0、
  `php -l` 干净、`qtphp test` 112/183。
  **顺带查到**：`cmdPackage()` 非 Windows 一律走 `packageAppBundle()` ⇒ Linux 打包必失败（未实测，列为下一步）。
- [x] 12.6 Linux 打包不再冒充 macOS（Session 10，F19）：`cmdPackage()` 改成显式三路（Darwin → `.app`、
  Windows → `dist/`、其余 → `未实现 <平台> 平台的打包` + rc=1）。查完的结论是 **Linux 是成建制的缺口**
  （还有 `checkSharedLibraryDeps()` 用 `otool`/`.dylib`、Linux 上静默返回 []；`project.linux.yml` 的链接方式
  与 framework flag 不成立），所以**没有假装实现打包**，只修掉报错指错方向这一处。
  验收用鉴别力反证（本机 Darwin）：临时把 Darwin 条件改成永不成立 → 新分支打出 `[ERROR] 未实现 Darwin 平台的打包…`、
  rc=1、`dist/` 未被改动；还原后 `grep TEMP-NEGATIVE-TEST` rc=1 干净，macOS 重打包 +
  bundle offscreen `--selftest` **14/14 rc=0**、`php -l` 干净、`qtphp test` 112/183。
  CLI 1481 → 1487。**Linux 上的真实行为仍未实测**（验的是分支可达性与退出码）。
- [x] 12.7 Linux 首次在真机跑通（Session 11，F20+F21）：Apple Container 里 Debian 12 arm64 + `qt6-base-dev 6.4.2`
  + `cmake 3.25.1` 真编译真验收。容器无 NAT egress ⇒ 在宿主网关 IP 上起只读转发代理绕过（F20）。
  落地：新增 `examples/hello/project.linux.yml`（多架构 `-I`/`-lQt6Xxx`，无 framework）、
  `linuxMultiarchTriple()` + `findQt()` 的 Linux 分支、`doctor` 认 g++、`checkLinuxLibraryDeps()` 走 `ldd`
  （F19 第 2 条修掉）、`cmdNew()` 生成 `project.linux.yml`。CLI 1487 → 1608。
  实测：tpc 自建 embed 运行时（`php-8.5.11-142201d298b578e5`，与 macOS 哈希不同 ⇒ 按平台分桶）、
  产物 ELF PIE aarch64 58 MB、offscreen `--selftest` 14/14、`--difftest` 20/20、`--shot` 760×560 PNG，全 rc=0；
  `test` 112/183、`lint` 契约一致、`new` 三元组正确、`package` 真 Linux 上 rc=1 报「未实现」。
  macOS 侧回归：`php -l` 干净、doctor 6 项 OK、build + offscreen selftest rc=0、test/lint 全绿。
  ⇒ **Qt 6.4.2 与 6.11.2 断言集完全一致**；F19 的「成建制缺口」收窄成只剩 Linux 打包路线。

- [x] 12.8 Linux `package` 实现并在真机验收（Session 12，F22）：`cmdPackage()` 的 Linux 分支从显式报错
  改为 `packageLinuxDir()` —— 产物 `dist/<name>/{<name>, lib/*.so, plugins/{platforms,xcbglintegrations}/*.so, qt.conf}`。
  关键取舍：**DT_RPATH 而非 DT_RUNPATH**（RUNPATH 只对本对象自己的 NEEDED 生效，`libQt6Gui` 的
  `libglib/libEGL` 会回落系统 ld.so.cache，本机正常、换机炸），故 `patchelf --force-rpath` 并在自检里
  读 `readelf -d` 断言「有 `(RPATH)` 且无 `(RUNPATH)`」；`.so` 落地名用 **soname**；glibc 家族留系统、
  `libstdc++` 搬（GLIBCXX 卡 ABI）。放弃「`project.linux.yml` 加链接期 `-Wl,-rpath`」——插件不是我们链的。
  新增硬前置 `patchelf`（slim 镜像没有，`apt install patchelf` → 0.14.3-1+b1）。CLI 1608 → 1828。
  实测：`package examples/hello` rc=0，85 个 `.so` + 10 个插件，140.3 MB；`ldd`（exe 与 `libqxcb.so` 各一次）
  落在产物外的行为**空**；`env -i QT_QPA_PLATFORM=offscreen` 下 `--selftest` 14/14、`--difftest` 20/20、
  `--shot` 全 rc=0，PNG 与开发产物逐字节一致（sha256 相同）；重复打包与 `package .` 均 rc=0。
  macOS 无回归：`php -l`、`test` 112/183、`lint`、`doctor` 全 rc=0。**未测**：真实 X 桌面上的 xcb、
  `eglfs/linuxfb/vnc` 运行、跨发行版（Ubuntu/Fedora）。

- [x] 12.9 Linux 硬前置落到 `doctor`（Session 13，F23）：新增 `linuxMissingPackages()` —— 6 项按命令探测
  （`bison`/`re2c`/`autoconf`/`pkg-config`/`xz-utils`→`xz`/`patchelf`）+ 6 项按头文件探测
  （gmp/mpfr/onig/libxml2/sqlite3/zlib，在 `/usr/include` 与 `/usr/include/<三元组>` 两处找）；
  `cmdDoctor()` 加 Linux 专属一块，缺就 `[WARN]` + 直接给出 `apt install -y <包名…>`。
  **一律 warning、不进 `$allOk`**，保持「doctor 的 rc 只由 error 级项决定」。CLI 1828 → 1893。
  探测点全部实测定位：`gmp.h` **只在多架构目录**（⇒ 搜索必须带三元组）；`/usr/include/onigmo.h` 在 Debian 上
  **不存在**（`libonig-dev` 给的是 `oniguruma.h`，照 PHP 的 configure 习惯探 `onigmo.h` 会永久误报）。
  两条分支真机逼验：`apt-get remove -y patchelf` 逼出命令分支、塞一条 `definitely-not-here.h` 逼出头文件分支，
  均正确报出包名；恢复后「齐全」。回归：Linux `doctor`/`test` 112/183/`lint`/`package` 全 rc=0，
  `bin/qtphp` 两侧 sha256 一致；macOS `doctor` 输出仍 6 行、`test`/`lint`/`php -l` 全绿。

### Phase 13 分解（tpc 供给路线，Windows）

- [x] 13.1 复现并定位：`build examples/hello` 报 `The PHPX runtime library was not found at:
  ...\phpx\build\phpx.dll`。定位链 `Windows.php::getBuildLibraryWarnings()` → `PhpxLocator::resolve()`
  → 找的是 **`vendor/swoole/phpx`** 源码树（该包只有 `CMakeLists.txt`/`src`/`include`，无 `build/`）。
  即 `findTpc()` 把 composer 驱动排在了自包含的原生发行包前面。
- [x] 13.2 修法：不做「谁优先」的硬性排序（macOS/Linux 全链路本就建立在 composer 驱动 +
  tpc 自建私有运行时之上，见 11.3），改为**按运行时体检** —— 选中的候选缺运行时、候选表里另有
  带运行时的就改用后者。新增 `tpcHasRuntime()`（判据与 `findRuntimeLibDir()` 标记集一致，
  `.php` 驱动恒 false）、`nativeTpcSearchDirs()`、`whichAll()`；删掉硬编码的
  `D:/git/php/tpc_v0.9.4_windows_x64/tpc.exe`；`TPC`/`TPC_DIR` 显式指定时不体检。
  CLI 1893 → 1986 行。
- [x] 13.3 自纠：初版写成「原生发行包优先」，随即意识到这会改变 macOS/Linux 上已验证的行为，
  `git checkout` 还原后改为体检方案 —— 是修复而非推倒重来。
- [x] 13.4 Windows 端到端复验：`doctor` 6 项全 OK（tpc 与运行时库都指向原生包）、
  `build` 成功、`--selftest` 14/14 rc=0、`--shot` 21KB PNG（读图确认控件齐全）、
  `test` 112/183、`lint` 契约一致。
- [ ] 13.5 **未测**：macOS/Linux 上「无原生发行包、只有 composer 驱动」的退回路径
  （逻辑上仍走原顺序，本窗口无 mac/Linux 环境复验）。

### Phase 24 分解（webview 后端，macOS 本机复验）

- [x] 24.1 macOS 本机重编 + 真跑（Session 25）：用户侧完成 webview 之后，mac 侧此前只在 Windows 上
  「关掉 `/DQT_WEBVIEW2` 重编」间接验过 QTextBrowser。这次在 Apple Silicon + brew Qt 6.11.2 上走
  **真实 mac 入口** `project.macos.yml` 全链复验：
  ① `build` rc=0（11 个 TU，含 `qt_webview.cc`；产物 Mach-O arm64 25.5 MB）—— 关键点：**mac 入口不需要改一行**，
     `sources` 从 `project.yml` 继承到 `qt_webview.cc`，而 `cxx-flags` 是**整体替换** ⇒ brew 那套
     `-F/-DQT_*_LIB` 天然不带 `QT_WEBVIEW2`，自动落到 QTextBrowser 分支；`link-libs: []` 也把
     `WebView2Loader.dll.lib` 清掉了（否则 mac 链接必炸）。
  ② 无头：`QT_QPA_PLATFORM=offscreen` 下 `--selftest` **25/25 rc=0**、`--difftest` **20/20 rc=0**；
  ③ 真窗口：`--shot`（cocoa，无 offscreen）3 秒出 760×720 PNG rc=0，读图确认分组标题
     `WebView（backend=textbrowser，js=不支持）`、`html` 里的 `<h1>`/`<b>`/中文全部渲染；
     `./build/hello` 实跑起窗口、kill 后 rc=0 干净退出。
  ④ 回归：`qtphp test` **123 tests / 201 assertions**、`qtphp lint` **26 个桥接函数**契约一致。
- [x] 24.2 补验 `url` 分支（示例只用了 `html`，`url` 在 mac 上没人碰过）：临时把示例的 webview 换成
  `url => 'assets/webview_url_probe.html'`（含 `<code>`/相对 `<img src="icon.png">`/`<a href>`）重编出图 ——
  **渲染成功**，且相对图片按文档 base URL 解析对了 ⇒ `qtResolveUrl()` 的「无 scheme → `QUrl::fromLocalFile(qtResolvePath())`」
  在 mac 上成立。**探针已还原**（`git checkout` + 删临时 html + 重编复验 25/25 + 20/20 + 出图一致）。
  顺带量到一条差异：**文件不存在时 QTextBrowser 后端是静默空白**（第一次探针少写 `assets/` 前缀，
  rc 仍 0、无任何警告），而 WebView2 后端会发 `loaded` 且 `success=false` ⇒ 见 F26 尾部与 Errors 表。
- [x] 24.3 把 F26 尾部那三条「未 mac 验」全部补完（Session 25，F27 第 1–3 条）：
  ① **`link` 接线验通** —— 本机 Accessibility 关、无 `cliclick` ⇒ 改用应用内合成
    （临时 `qtWebViewProbeFirstLink()`：找 anchor → `cursorRect` → 自证 `anchorAt` 非空 →
    `sendEvent(viewport, press+release)`），并做**鉴别力反证**（摘掉 `enqueue` 后点击仍命中但事件消失）。
  ② **`zoom` 确实静默忽略** —— 设与不设两次 `--shot` 的 PNG **sha256 逐字节相同**，不崩也不改布局。
  ③ **远程 `url` 在 QTextBrowser 上根本不支持** —— Qt 打 `QTextBrowser: No document for …`、rc 仍 0；
    逐项排除「本机没网（curl 200）/ 没链 QtNetwork（补 `-framework QtNetwork` 后 PNG 一字不变）/
    TLS（换 http 同样失败）/ 异步没等到（`QT_TEXTBROWSER_SYNCHRONOUS=1` 无效）」，并用 `file://` 作对照。
    被追问「是不是拿纯字符串测的」之后**重做到对象级**（F27 3.1/3.2，独立 Qt 程序 `/tmp/qtb_probe/`，不经 PHP）：
    `QUrl` 解析正常（`valid=1 scheme=https host=example.com isLocalFile=0`）、警告是 `setSource` **同步**打的、
    真 `QEventLoop` 跑满 6 秒文档始终 0 字符、同进程 `QNAM` 却拿到 200/577 字节、`loadResource` 远程图片返回 null，
    再加 `otool -L QtWidgets` 的 NEEDED **里没有 QtNetwork** ⇒ 结论不变且更强。scheme 矩阵另查出
    **`data:` 也不支持**（base64 变体同样空白），只有 `file` 可用 ⇒ **文档缺陷范围比原先记的大**：
    `docs/src/zh/widgets/webview.md:44` 那句「带 scheme 的原样使用」在非 Windows 平台只对 `file` 成立。
  全部探针代码已 `git checkout` 还原。**但那次「还原后重编」经不起追**：产物里还留着探针期的字面量、
  生成文件 mtime 没前进（F28 第 2 节）⇒ 本轮补做**删 `build/` 的全清重建**并重测：`build` rc=0（11 TU）、
  `--selftest` **25/25**、`--difftest` **20/20**、`--shot` rc=0，产物内探针字符串 **0 处**，出图哈希
  `fc9319b597…` 与先前记录一致 —— 顺带量出 `--shot` 的 sha256 **本身跨次不稳定**（6 次里 1 次不同，光标闪烁），
  所以「哈希相同」只在「证明无变化」这个方向上可用（F28 第 1 节）。
- [x] 24.4 WebView2 路线：先确认本机可达边界，再交静态审查 + 一个**克隆即坏**的缺陷（Session 25，F27 第 4–5 条）：
  探测「有没有 Windows 目标」—— 无 Parallels/VMware/UTM/Fusion、`~/.ssh/config` 只有 github、无 wine、
  Apple Container 只能跑 Linux，而 `brew install mingw-w64` 是 GB 级、启动卷只剩 **3.5 Gi** ⇒
  **连交叉语法检查都做不了**，`QT_WEBVIEW2` 分支在本机不可能有运行时证据。
  改成读代码，交出 5 条：W1 `loaded` 事件 value 恒空 + 每次导航泄漏一个 CoTaskMem 串、
  W2 `navigating` 取的是导航**前**的源、W3 没有析构函数从不调 `Controller::Close()`、
  W4 三级 COM 回调裸捕 `this` 无生命周期保护、W5 `reload`/`goBack`/`goForward` 在 PHP 侧无入口 = 死代码。
  **最硬的一条是 W0**：`.gitignore:9` 的 `*.lib` 把 vendor 的 `WebView2Loader.dll.lib` 吞了
  （`git ls-files third_party/webview2/` 里确实没有它），而 `examples/hello/project.yml:39` 与
  `bin/qtphp:470`（`qtphp new` 模板）都写死链接它、`third_party/webview2/README.md:13` 还声称已 vendor
  ⇒ **新克隆在 Windows 上链接必报 `LNK1181`**。本轮加 `!third_party/**/*.lib` 放行并验证
  （`check-ignore` rc=1、`git add -n` 可收、`/build/` 规则未削弱）；**那 3.5 KB 的 `.lib` 只能从 Windows 那台机器补交**。

- [x] 24.5 让 `--shot` 出图可复现，使 PNG 的 sha256 能当 CI 基线（Session 25，F28 §3）：
  **先造 forcing function** —— `--shot` 分支加 TEMP 探针 `SHOTDELAY=<ms>`（每 10ms 泵一帧），把 grab 推到
  墙钟不同相位；这同时解释了上一轮「摘掉冻结 16/16 同哈希」为什么反证失败（不额外延时时 grab 太快，
  噪声压根没机会出现，**不是**冻结有效）。五轮全清重建（F28 §3 表格）逼出**两层**噪声，都不是 F28-1 记的
  caret/hover：① 应用自己的 1 秒心跳文案（`心跳 2 跳 → 3 跳`，差异带 `(25,366)–(159,714)`）
  ⇒ `main.php` 把 `setTimer('clock',1000)` 移到 `--shot` 分支之后；② 输入框**有没有焦点**
  （整行 QLineEdit 边框 `#b6b6b6` ↔ Fusion 焦点高亮 `#7e9dc2` + 光标有无）
  ⇒ `snapshot()` 里 grab 前 `clearFocus()`、抓完还原。**关键更正**：F28-1 那版 `qtFreezeTransientUi`
  （`setCursorFlashTime(0)` + `clearFocus(); setFocus();`）实测**不收敛** —— 有焦点时它把焦点留着、
  没焦点时它什么都不做，冻结开/关两轮跑出**同一对哈希**；harness `focus3.cc` 三个 mode × 两个起点
  量化确认：`none` 2 种、`refocus`（旧写法）2 种、`clear` 1 种 ⇒ 判据是「从不同瞬态起点必须收敛成同一帧」。
  差点写进去的两条**错归因**都被控制组否掉了：`repaint()` 版稳定 19 样本，但它在第一次 grab 前只多两次
  `focusWidget()` 读取，不可能改像素 ⇒ 那是环境漂移，控制组（去掉 repaint 仍 15 样本 1 种）证伪；
  `WA_UnderMouse`/hover 那条本机无法按需复现（只置属性不改变像素），保留 1 行清理但**如实标未证**。
  终验（删探针 + 全清重建）：`--selftest` 25/25、`--difftest` 20/20、`phpunit` 123/123（201 断言），
  `--shot` cocoa 8/8 → `a70cd7ae05b6…`、offscreen 8/8 → `4b777e2ed74e…`，且 `a70cd7ae` 与修复前那几轮的
  灰框哈希逐字节相同 ⇒ 干净帧没被改动，只是不再取决于激活竞态与秒针。

- [x] 24.6 mac 上「走 Qt WebView 类」的路线勘查（Session 25，F29，**未动任何安装**）：
  字面理解（装 brew `qtwebview`）实测不划算 —— 它的 deps 表里写着 **qtwebengine**（1.5–2 GB 口径）
  而本机启动卷只剩 **2.5 Gi**；且 Qt 官方文档明说「On macOS, the system web view is used in the same
  manner as iOS」⇒ 那个模块在 mac 上只是 **WKWebView 的 QML 壳**（`QWebView` 是 `QQuickItem`，不是
  QtWidgets 类），装一大坨换来的还是同一个引擎。对照实验：零安装直接链系统 `WebKit.framework`，
  JS / 远程 `https://example.com/` / `data:` 三条能力全过（`webkit_version=21624`、`title='JS-RAN-3'`、
  `'Example Domain'`、`'DATA-OK'`）。**用户据此选定：加 `WKWebView(.mm)` 第三后端。**

- [x] 24.7 WKWebView 嵌入 spike（#9 第 ① 步，F29 §5）：三行挂载
  （`(__bridge NSView *)(void *)host->winId()` → `addSubview:` → `autoresizingMask`）在真窗口里
  **合成成立** —— `screencapture` 抓到的帧里网页蓝 `#88bcdd` 出现在两个 Qt 标签之间、洋红宿主底色一点没露；
  `window.resize(420,300)` 后 `web.frame` 自动变成 `0,0,380,210` 且再次抓到蓝 ⇒ 不需要 Qt 侧同步几何。
  `evaluateJavaScript:"document.title"` 读回 `WK-JS-42` ⇒ 引擎在 Qt 宿主里活着。
  **一条必须提前知道的代价**：同一时刻 `window.grab()` / `host->grab()` 的中心都是 `#ff00ff`
  ⇒ 原生子 view 不进 `QWidget::grab()`（与 F27「`--shot` 截不到 WebView2」同根因，第二次确认）。
  因为 mac 现在的默认后端 QTextBrowser 是 Qt 自己画的、**能**截到，所以换默认后端会让 mac 的
  `--shot` 帧里 webview 区域变成空洞、`a70cd7ae05b6…` 必改 ⇒ 第 ④ 步要同时把 webview 的验收
  从像素改成 `evaluateJavaScript` 读回（比像素更强：能证明页面真的加载并执行了）。

- [x] 24.8 WKWebView 第三后端落地 + 构建接线（#9 第 ②③ 步，F29 §6）：新建 `cpp-src/qt_webview_wk.mm`
  （url/html/zoom 三属性、`navigating`/`loaded`/`title`/`link` 四事件全部对齐 `qt_webview.cc` 的契约），
  mac 入口 + `qtphp new` 模板同时接线（`sources` 必须**全量重写**再加 `.mm` —— tpc 的 `mergeConfig`
  对 list 是整体替换；`-DQT_WEBVIEW_WK -fobjc-arc`；`-framework Foundation/AppKit/WebKit`）。
  真机端到端（临时探针，验完已删）：`title=WK-JS-42`（JS 真跑了）、远程 `example.com` → `title=Example Domain`、
  `pageZoom=2.50` 在 `flushPending` 与 `didFinish` 两处都读回（跨导航保持）、`navigationType=0` 的链接点击
  被 `decidePolicy` 回 `Cancel` 拦下并上报 `link`（**未跳转**）。
  **修掉一个新引入的真缺陷**：`QT_QPA_PLATFORM=offscreen --shot` 直接 SIGSEGV（rc=139、PNG 不生成）——
  offscreen 的 `winId()` 不是 ObjC 指针；`attach()` 首行加平台闸门 `platformName() != "cocoa" ⇒ return` 后 rc=0。
  终验（删探针 + 全清重建）：`--selftest` 25/25（cocoa 与 offscreen 各一遍）、`--difftest` 20/20、
  `phpunit` 123 tests / **203** 断言（新增 wkwebview 能力断言）、`lint` 契约一致；
  cocoa `--shot` ×8 → `2728fb1a6a06…`、offscreen ×8 → `6c4832a6b738…`。**控制组**：只注释 `-DQT_WEBVIEW_WK`
  重建 ⇒ 帧与旧基线 `a70cd7ae05b6…` **逐字节相同**，开/关像素差 129,830 px（23.73%）、包围盒
  x[33..726] y[454..664] 完全落在 WebView 分组内、y=454 以上零差异 ⇒ 其余控件未被触碰。
  脚手架闭环：`qtphp new demo` → 生成的 mac yml 含 `.mm`/`-DQT_WEBVIEW_WK`/`-framework,WebKit` →
  `build .` rc=0（25,316,424 B）→ `--selftest` passed。

- [x] 24.9 #9 第 ④ 步收口（F28 §4）：**「120 帧泵是给 WebView2 的」这个假设被实测否掉** ——
  按后端收口成 `supportsJs ? 120 : 3` 之后，关开关那一版的 cocoa `--shot` 8 次跑出 **4 种**哈希，
  差异 100% 落在 `(622,66)-(636,80)` 的 `QLineEdit` **清除按钮淡入淡出**上（同格主灰阶
  `#bababa`/`#ededed`/`#eeeeee`/`#dcdcdc`）⇒ 泵帧数是「出图可复现」配方的一部分，与后端无关，
  改回无条件 120 并把注释换成实测真原因。顺带把文档/脚手架/技能里教的 `runFrames(3)` 这条坏配方
  改成「泵到动画停」，`docs/src/{,zh/}advanced/headless.md` 新增「把 PNG 变成 CI 基线」一节
  （三条前置条件 + ×8 收敛判据 + 三条基线值 + 哈希单向性）。能力侧：`webViewBackend()` 三值定稿，
  新增后端×JS 能力矩阵测试（`phpunit` 124 tests / **209** 断言）。恢复后复测：cocoa ×8 →
  `2728fb1a…`、offscreen ×8 → `6c4832a6…`、两平台 selftest/difftest 全 rc=0；
  脚手架 `qtphp new demo` → build rc=0 → selftest passed → `--shot` ×3 同哈希。

- [x] 24.10 #10「关瞬态动画」：`snapshot()` 把未停的动画推到终点 ⇒ `--shot` 泵帧从 120 降回 3（F28 §5，**取代 24.9 的「保持无条件 120」结论**）：
  24.9 的止血办法（多等 117 帧）不是终解。Qt6 里**没有**关通用 UI 淡入淡出的开关
  （`Qt::UIEffect` 只有 menu/combo/tooltip/toolbox 五种，无 `QT_UI_EFFECT_ANIMATE_UI_CHANGE`），
  但 `QLineEdit` 清除按钮那条动画是 parented 到该 line edit 的 `QTimeLine` ⇒
  `window_->findChildren<QAbstractAnimation *>()` 直接抓得到，对 state ≠ `Stopped` 的做
  `setCurrentTime(totalDuration())` + `stop()`（`jumpToEnd()` 只在 `QPropertyAnimation` 上，通用类没有）。
  关键因果：那条淡入淡出**正是上一句 `clearFocus()` 自己触发的** ⇒ 「清焦点」与「3 帧不稳定」是同一根链。
  **阶梯测量（每步只动一个变量）**：冻结+120 → `2728fb1a…`（与冻结前同值 ⇒ 120 帧时冻结是 no-op；
  这是**跨时间窗**比对同一串哈希，按 F28 §1 的单向读法成立，控制组 A/B 才是同窗跑的）；
  冻结+3 帧 → cocoa 8/8 = `2728fb1a…`、offscreen 8/8 = `6c4832a6…`（**两条基线值均未漂移，文档无需改数**）。
  **控制组 A**（去冻结、3 帧、同时间窗同配置）→ cocoa 8 次 **4 种**哈希（`a70ad48f`×1 / `1b586809`×3 /
  `e8f85628`×3 / `4b9d1455`×1）⇒ 冻结承重，不是环境漂移。
  **控制组 B**（关 `-DQT_WEBVIEW_WK` → QTextBrowser、3 帧、冻结开）→ cocoa 8/8 = `a70cd7ae…`
  ⇒ 证明 24.9 那次「3 帧出 4 种」确实只由动画引起，与后端无关。
  恢复开关 + 全清重建终验：两平台 `--selftest` 25/25、`--difftest` 20/20 rc=0；`--shot` **445 ms**；
  `php bin/qtphp test` 124 tests / **209** 断言、`lint` 契约一致、`php -l bin/qtphp` 无错；
  脚手架 `qtphp new demo` → `build .` rc=0（25,317,288 B）→ selftest passed → `--shot` ×8 distinct=1。
  文档四处（中英 `headless.md`、`bin/qtphp` 模板、两处技能 reference）同步回 `runFrames(3)` 并说明「动画由
  `snapshot()` 冻结」。已知语义变化：`--shot` 只能拿到动画**终点**，将来要验中间态得自己构造帧。

### Phase 28 分解（Session 26：mac 打包体积与 bundle 资源解析）

- [x] 28.1 量清打包产物 + 修 P1/P2（F31）：
  **P1**（`bin/qtphp` 的 `dirSize()`）—— `SplFileInfo::isFile()/getSize()` 跟随符号链接，
  `.framework` 的 12 个 alias 条目按目标文件重复计 ⇒ 体积虚高 ~19%（打印 99.8 MB，真实 83.6 MiB）。
  修法：`if ($file->isLink() || !$file->isFile()) continue;`。
  **P2**（`cpp-src/qt_common.h` 的 `qtResolvePath()`）—— 加一层 bundle `Contents/Resources`，
  并把触发条件钉成「exe 目录的父目录名为 `Contents`」，这样 Linux/Windows 上项目根恰好有 `Resources/`
  也不会被抢先命中。顺序：exe 目录 → bundle Resources → cwd → 报错时回给 exe 目录版本。
  **证据链**：修前 bundle 启动必报 `tray icon could not be loaded: assets/icon.png`（实测）；
  修后从 `cwd=/tmp` 与 `cwd=/`（等价于 `open` 起的进程）跑 bundle 二进制均无警告、`--shot` 哈希仍是
  `2728fb1a6a06…` ⇒ 只补了资源解析，没动像素；**负控制**把 `Contents/Resources/assets` 改名后警告立刻回来
  ⇒ 生效的正是新加的那一层，不是环境漂移。开发态二进制从 `cwd=/tmp` 跑仍命中 `build/assets/`、哈希不变。
  复验：`package` 打印 83.6 MB、offscreen `--selftest` passed、`phpunit` 126 tests / **212** 断言、
  `lint` 契约一致、`codesign --verify --deep --strict` 通过（临时改名后已还原）。
  文档：中英 `reference/packaging.md`、`{,zh/}guide/dialogs.md`、README 三处口径同步成
  「exe 目录 → bundle `Contents/Resources` → 工作目录」。
  **未做**：`LC_RPATH` 里仍留着开发机路径（`/opt/homebrew/opt/libiconv/lib`、`~/.typephp/…/install/lib`），
  不影响自包含性（无 NEEDED 走 `@rpath`），要清的话 `install_name_tool -delete_rpath` —— 未验。


### Phase 29 分解（Session 26：打包验收的 stderr 判据）

- [x] 29.1 `verifyAppBundle()` 增加「bundle 内无头真跑 + 无告警」判据（F32）：
  先量健康产物的真实 stderr（不猜白名单）⇒ 里面本来就有 2 条 `warning|`：
  `qt.qpa.fonts`（字体别名表填充耗时，开发机速度相关）与 offscreen 插件的
  `This plugin does not support propagateSizeHints()`。因此判据不能按 type 一律拦。
  做法：`QT_MESSAGE_PATTERN='%{type}|%{category}|%{message}'` + `QT_QPA_PLATFORM=offscreen` 跑 `--selftest`，
  要求输出含 `selftest passed`（**selftest 失败仍返回 0，所以 rc 只能兜底、判据看文本**），
  再由 `bundleIsWarningLine()` 过滤：`warning|critical|crash|fatal` 前缀算告警，
  白名单掉上面两类无害告知。剩下的正是应用/桥接层自己 `qWarning` 的、指向真实缺陷的行。
  **鉴别力**：负控制（藏 `examples/hello/assets/icon.png`）⇒ `warning|default|tray icon could not be loaded: assets/icon.png`
  且 `package` rc=**1**；还原后 rc=**0**、83.6 MB。正例本身带 2 条 warning 仍通过 ⇒ 白名单必要。
  复验：`php -l` 无错、`phpunit` 126 tests / 212 断言、`lint` 契约一致。
  **未做**：Linux（`verifyLinuxPackage()` 只打印无头验收命令、不执行）与 Windows 侧同款判据；
  `--selftest`/`--difftest` 失败退出码仍为 0 —— 这条已在 Phase 31 收口。

### Phase 30 分解（Session 26：nano 模式体积实测）

- [x] 30.1 用 `tpc --nano` 编 `examples/hello` 并量体积（F33）：
  供给方式：把 `swoole/php-nano` v1.1.0 手工放进 gitignored 的 `vendor/swoole/php-nano`
  （abi 80600 与仓库内 `vendor/swoole/phpx` 相符），**未改 `composer.json`/`composer.lock`**。
  两个前置条件：`--cxx-std c++17`（示例是 c++20，nano 直接 fatal 拒绝）、临时入口 yml 去掉 `php-builder:` 段。
  结果：编译成功 4,370,536 B（strip 3,639,504 B）vs 普通模式 25,569,608 B；依赖清单无 libphp/phpx。
  **不可运行**：`Unable to start PHP Nano extensions` rc=1；最小 nano 程序（`strlen`+`echo`）同样中招，
  所以卡点在上游生成代码的 `ZEND_MOD_REQUIRED("Core")`（机制见 F33 §3，全部定位到 file:line）。
  如实记录的失败实验：改生成 `.cc` 再重编**不能**作为正证，因为 tpc 会按 target 名重写该文件。
  清理：临时 yml、探针程序、nano 产物删除；`examples/hello/build` 用改前备份还原，
  还原后 `--selftest passed` 且 `--shot` 哈希仍是 cocoa 基线 `2728fb1a6a06…`（逐字节相同）。
  **当时未做、现由 30.3 完成**：改 tpc codegen 让 nano 模式不再把反射来的依赖写成 REQUIRED（已落地并真机验证），
  以及 nano 产物在 Qt 上的运行期能力面验证（`--selftest`/`--difftest`/`--shot` 三开关全过）。

- [x] 30.2 按用户给的《TypePHP 四种运行时与链接方案》文档重核 nano 判断（Session 27，F35；全程只读）：
  ① 三轴定位：本仓库 = `mode: bin` + 默认 `sapi: embed` + mac `php-builder` / win-linux 宿主 libphp，
  没有用错轴；文档也印证了 mac 那条前提（brew PHP 只有 cli SAPI ⇒ 必须 php-builder）。
  ② 逐条否证 4 种解释：phpx 版本旧（本仓库 lock 实为 v2.9.3）、vendor 供给方式不对
  （php-nano 28 个 component 无一对外的 "Core"，而 `zend_builtin_module` 是 `static` ⇒ 声明方式改不动）、
  Qt 代码（30.1 已否证）、还有第二个不匹配依赖（`#ifdef PHP_NANO` 让 `basic_functions_module`
  走 `STANDARD_MODULE_HEADER` ⇒ deps 为 NULL；`ZEND_MOD_REQUIRED` 的实际使用只落在 4 个模块，进我们数组的只有 SPL→json 且 json 在数组内）。
  ③ 反向新证据：**"Core" 恰好是 `strlen` 所在的扩展**（实测宿主 PHP 8.5.7 反射：
  `strlen`/`func_num_args`/`function_exists`/`class_exists` ⇒ Core）⇒ 应用侧不可规避，修复只可能在上游两处
  （`Translator::appendExtensionDependency()` nano 下不写 Core，或 php-nano 把已启动的 core 视为可满足）。
  姊妹仓库当前源码与 v0.9.4 这两处逐行一致 ⇒ **等发版不会自动修好**。
  ④ 记录一处与文档的**未验证矛盾**：文档称 Windows `--nano` 仅 policy、仍链 PHP/PHPX DLL；
  v0.9.4 代码三处反向（`NanoBuildBackend.php:11-19` 两个函数都无视 `$platformName` 恒返回、
  `NativeBuildConfigurationTrait.php:238-248` 的 `getLibraries()` 在 nano 下提前 return 只剩系统 `.lib`
  （`php8ts/php8embed` 只在 273-301 的非 nano 分支追加）、`NativeDependencyAuditor.php:69-96` 见 `php*.dll` 即抛错，
  调用点 `Translator.php:3025-3028`）。两边都没有 Windows 实测 ⇒ 两条都不作事实引用。
  **30.3 复核时否证了本条初稿的一个证据**：原写「`NanoSourceComposer.php:331-340` 专产 `php_nano.lib`」，
  实测该文件只有 252 行、全 `src/` grep `php_nano\.lib` 零命中 ⇒ 已换成上面 `getLibraries()` 的真证据（结论方向不变）。
  ⑤ `--full-static` 对 qt 不适用（Linux musl SDK 里没有 Qt，而 qt 必须链 Qt 动态库）⇒ 不立项。

- [x] 30.3 落地 30.2 §2(a) 的上游补丁并真机验证 nano（Session 27，用户令「直接改」，F35 §5）：
  改动 = `doGenExtension()` 里 nano 模式下把 deps 宏从 `ZEND_MOD_REQUIRED` 降为 `ZEND_MOD_OPTIONAL`
  （`Translator.php:1949`，两处同步：`typephp-qt/vendor/swoole/typephp/`（验证用、gitignored）与
  `typephp-compiler/src/`（正解落点，该仓库工作区现仅此一处 modified）。**未提交**，姊妹仓库提交待用户点头。
  为什么降级而不是特判掉 "Core"：`dependency_state()` 对 OPTIONAL 缺失 `continue`、对 REQUIRED 缺失 `Invalid`，
  而数组内已存在但未启动的模块两者都返回 `Waiting` ⇒ 启动顺序语义完全保留，真缺扩展仍在链接期以未定义符号暴露。
  现场：临时入口 `examples/hello/project.nano-probe.yml`（= `project.macos.yml` 去 `php-builder:` +
  `cxx-std: c++17` + 独立 `build-dir: build-nano`），两条 nano 前置再次复现成立。
  A/B（同窗、同树、同 `-O0`，只换这一个变量，跑完已还原）：
  · OPTIONAL ⇒ 构建 rc=0（250 TU：240 php-nano + 6 generated + 4 external，含 `qt_webview_wk.mm`），
    `--selftest` 25/25 rc=0、`--difftest` passed rc=0、`--shot` offscreen `6c4832a6b738…`、cocoa ×2 均 `2728fb1a6a06…`
    ⇒ **与 embed 模式基线逐字节相同**；`otool -L` 无 libphp/phpx。
  · 还原成 REQUIRED ⇒ 生成文件六条依赖回到 REQUIRED（含 `"Core"`），构建仍 rc=0，运行 **rc=1 且全进程只有
    一行 `Unable to start PHP Nano extensions`** ⇒ 证明 F33 的失败不是环境漂移，而是这一处 codegen。
  体积差已归因（**是 F33 漏记了一个参数**）：F33 那轮的命令是 `... --nano --cxx-std c++17 -O2 -o /tmp/hello-nano --no-progress`
  （旧会话记录里原文还在），`-O2` 与 `--cxx-std` 都写在 CLI 上、只把后者记进了 findings；本轮直连 tpc 没给 `-O` ⇒ `-O0`。
  同一棵树只换 `-O` 重编
  ⇒ `-O2` 得到 **4,370,536 B（strip 3,639,504 B）**，文件总尺寸与 F33 等值（`__text` 2,131,164 vs 2,129,548）；
  `-O0` 是 6,988,648 B（strip 6,237,888 B），`__text` 4,508,196 ⇒ 差异全在内联/死代码，不在 php-nano 组件集合
  （两档都 250 TU、链接命令含 `-Wl,-dead_strip` 完全相同）。
  `-O2` 档同样真机验过：`--selftest`/`--difftest` rc=0、offscreen `6c4832a6b738…` + cocoa `2728fb1a6a06…` 与基线同。
  ⇒ 走 `bin/qtphp` 时这一档其实固定（`bin/qtphp:1004` 硬编码 `-O2`），6.67 MiB 只在直连 tpc 不给 `-O` 时出现；
  但仍建议把 `-O`/`cxx-std` 落进入口 yml，别让同一个问题有两种答案。
  仍未验证：`.app` 打包链（`macdeployqt` × nano 产物）、Windows/Linux、`bin/qtphp` 尚未接 nano 入口。
  清理：临时 yml、`build-nano/`、`/tmp/nano*` 全部删除；`examples/hello/build`（非 nano）未触碰。

### Phase 31 分解（Session 27：无头开关的退出码）

- [x] 31.1 三个开关失败即 `exit(1)`（F34）：改 `examples/hello/src/main.php` 的 `--shot`/`--selftest`/`--difftest`
  三处（`self_test()`/`diff_test()` 返回 `$failed` 计数）+ `bin/qtphp` 里 `qtphp new` 的模板同样三处；
  `verifyAppBundle()` 的注释与判据改为「rc 主、文本兜」。先确认 AOT 通路成立：
  `exit(N)` ⇒ `php::aotExit(N)`（compiler `src/CompilerBase.php:4720-4729`），而 tpc 自身是 AOT 产物、
  `CliDiagnosticReporter` 就是 `php::aotExit(255LL)` ⇒ 本会话早前实测的 tpc rc=255 已是外证。
  **正例**：offscreen `--selftest` 25/25 rc=0、`--difftest` 20/20 rc=0、`--shot` 出图哈希
  cocoa `2728fb1a6a06…` / offscreen `6c4832a6b738…` 与 F28/F29 基线逐字节相同；
  `php bin/qtphp package examples/hello` rc=0、83.6 MB。
  **反例（每条都实测到非零）**：`--shot /no/such/dir/x.png` ⇒ rc=1 且打印 `snapshot failed: <path>`
  （改前静默 rc=0）；同一坏路径穿过 `qtphp run` ⇒ rc=1（CLI 未吞码）；往 `step_btn` handler 注入
  `throw` ⇒ 只有该用例 FAIL、`selftest failed: 1`、rc=1；把一条 diff 断言判反 ⇒ `difftest failed: 1`、rc=1；
  打包侧藏 `assets/icon.png` 的 F32 负控制仍 rc=1 且告警行精确。测完还原注入并重编，正向复验一次。
  **两个 AOT 坑**（造负控制过程中实测，非读码）：`1 % 0` 在 AOT 里不抛 `DivisionByZeroError`
  （⇒ 自检不能指望运行期自动抛，必须显式 `throw`）；`echo $cond ? "a: ", $x, "\n" : "b\n"` 被 tpc 拒收
  `Syntax error, unexpected ','`（脚手架模板第一版就这样 `build` rc=1，改「先赋值再 if/else」后通过）。
  **顺带修的缺陷**：`QtApp::safely()` 从不清 `lastError` ⇒ 一次异常后每次分发都被冒充（实测 21 条 FAIL
  同一行号），改为分发前清空 + `testLastErrorIsScopedToTheDispatchThatFailed` 锁定。
  复验：`phpunit` **127 tests / 215 assertions**、`lint` 契约一致、`docs/check-anchors.py src` 全绿、
  `php -l` 全部改动文件无错。**未验**：Linux/Windows 上同一 rc 语义；`docs/check-links.py` 是产物级检查，需 `npm run docs:build` 后才有意义（本轮未跑）。
  **鉴别力**：新建的 `#exit-codes`/`#退出码` 两处跨页锚点做过坏锚点注入 ⇒ `check-anchors.py` rc=**1** 且精确指到那一行，
  还原后 rc=**0**、目标文件数 14→16（证明这两条链接真进了检查范围，CJK slug 没被跳过，F34 §5）。

### Phase 32 分解（Session 27：nano 接进 bin/qtphp）

- [x] 32.1 `bin/qtphp` 的 build 侧接入（用户令「nano 接进 bin/qtphp」）：
  `cmdBuild(string $path, array $flags = [])` 只认 `--nano`，未知项走 `error()` + rc=1（在 `cmdBuild` 里判，
  不在分发处静默吞）；命令行为 `tpc <entry> -O2 --nano --cxx-std c++17` —— `-O2` 是 `cmdBuild` 原有硬编码，
  `--cxx-std c++17` 在 CLI 上覆盖 yml 的 `c++20`，因此**不需要为 nano 再写一份入口 yml**。
  Windows 分支把 `deployRuntimeDlls()` 改成 `if (!$nano)` 才执行，依据不是「我觉得 nano 不带 DLL」，
  而是 tpc 自己的 `NativeDependencyAuditor::assertWindowsImports()` 见到 `php(?:x|\d.*)?\.dll` 直接抛错
  ⇒ nano 产物若能链上 php*.dll 就根本编不出来。`usage` 加 `--nano` 一行并写明只有 macOS 验过。
  `run`/`package` 未改：两者都按 `build/<name>` 定位产物，mac 侧 PHP/PHPX 本就静态链入、没有 dylib 要搬。
- [x] 32.2 tpc 侧把 `php-builder:` 在 nano 下关掉（这是「接进 qtphp」的真正前置，否则现有 mac 入口 yml 一
  用就 rc=255）：`CompilerBase::isPhpBuilderBuild()` 改为 `$this->phpBuilderEnabled && !$this->isNanoMode()`，
  两处同步（`typephp-qt/vendor/swoole/typephp/src/CompilerBase.php`、`typephp-compiler/src/CompilerBase.php`）。
  为什么加在 `isPhpBuilderBuild()` 而不是别处：`src/` 下 14 处判定（实测，非初记的 12 处，见 F36 §5）全走这一个方法，
  而 `configurePhpBuilder()`
  只写配置、`preparePhpBuilderEnvironment()` 在执行期按 `isPhpBuilderBuild()` 触发 ⇒ 配置层没有 off switch，
  唯一干净的落点就是这个判定。**只关 `isNanoMode()`、不关 `isNanoPolicyMode()`**：后者仍链宿主 libphp，
  那条路上 `php-builder:` 的语义必须原样保留。
  **上游回归实测（A/B，非推理）**：在 `typephp-compiler` 跑 `php vendor/bin/phpunit --filter '(PhpBuilder|Nano)'`
  ⇒ 打补丁与 `git show HEAD:src/CompilerBase.php` 两臂**逐位相同**（`Tests: 73, Assertions: 206, Errors: 3, Failures: 1, Deprecations: 7`，
  4 个坏用例名字也一致）⇒ 这一行不产生回归。那 4 个坏点是环境的坑：3 个 ERROR 是 `swoole/php-nano` 在该仓库 vendor 里没装
  （`ComposerNativePackage.php:82` 抛），1 个 FAILURE 是 `CompilerBaseApiTest.php:1117` 的 `/var/folders` vs `/private/var/folders`
  mac 符号链接问题 —— 副作用是该用例在 1117 就断了，走不到 1119 的 `assertTrue(isPhpBuilderBuild())`，
  所以「非 nano 下 php-builder 仍生效」的正向证据只能来自 T1。整跑 `--filter CompilerBaseApiTest` 会中途死掉且不打印摘要
  （死在 `testParseProjectYamlLoadsDocumentedCompilerOptions`，HEAD 版同样死 ⇒ 既有问题）⇒ **上游这套自检本机不能全绿，别当门禁**。
  测完 `src/CompilerBase.php` 按 `/tmp` 快照还原、`cmp` 校验逐字节一致。
  副作用：30.1 记的「入口 yml 必须去掉 `php-builder:` 段」这条前置从此作废（见上表 30.4）。
- [x] 32.3 真机三向验证（同一 `examples/hello/build/` 目录，不新开 build-dir）：
  · **T1 embed 基线复跑**（改动后先确认没伤到默认路径）：`php bin/qtphp build examples/hello` rc=0，
    产物 25,569,608 B，链接命令仍带 `-lphp`/`-lphpx` 与 `~/.typephp/php-builder/...` ⇒ 新门对非 nano 无影响。
  · **T2 nano**：`php bin/qtphp build examples/hello --nano` ⇒ 日志 `php-builder` **0 次**、无 curl/404、
    250 TU 全过、`Auditing Nano runtime dependencies` 后 rc=0；产物 **4,403,640 B**、`strip -u -r` 后 3,672,352 B；
    `otool -L` 只剩 QtWidgets/QtGui/QtCore（6.11.2）+ Foundation/AppKit/WebKit + brew libiconv.2 + libc++/libSystem
    + CoreFoundation/libobjc；rsp 里 250 个 `.o` 含 `generated/nano-entry-hello.o` 与 4 个 external（含 `qt_webview_wk.mm`）。
    运行侧：`--selftest` **25** 例 rc=0、`--difftest` **20** 例 rc=0、`--shot` cocoa `2728fb1a6a0652…e4d7`、
    offscreen `6c4832a6b73814…111b` ⇒ 两条都与 embed 基线逐字节相同。
  · **T3 切回 embed（增量缓存跨模式）**：`php bin/qtphp build examples/hello` ⇒ 日志报 `for 12 files` /
    `Successfully compiled 12 files`（不是 no-op），`php-builder` 重新出现 2 次、链接命令含 `-lphp`，
    产物回到 **25,569,608 B**（与基线等值），`--shot` cocoa 仍 `2728fb1a6a06…` ⇒ 复用旧对象没有改变行为。
    **口径差如实记**：mtime 落在该窗口、且在 16:43 之后的 `.o` 只有 8 个
    （`external/2108d59f3aa44f11/qt_{bridge,webview,webview_wk,widgets}` + `generated/extension-hello`
    + `generated/src/{main,QtApp,WidgetTree}`），与 log 的「12 files」差 4 个，我没逐文件核对是哪个口径，
    不把 8 当成 12 的解释。
  · **回归**：`php -l bin/qtphp` 无错；`php bin/qtphp test` ⇒ **127 tests / 215 assertions OK**；
    `php bin/qtphp lint` ⇒ 契约一致；`python3 docs/check-anchors.py docs/src` ⇒ rc=0，「检查了 16 个目标文件」。
    注意：`check-anchors.py` 的正则只吃 `](/xxx.md#frag)` 形式的跨页链接，本轮新增的页内 `](#nano)`
    **不在它的检查范围内** ⇒ 不能说它是「被脚本验过的」。
- [ ] 32.4 未验（不成立即不立项）：`.app` 打包链 × nano 产物（`qtphp package` 在 nano 产物上没跑过，
  `macdeployqt` 的行为未知）；Windows/Linux 侧 nano（`--nano` 只是原样透传）。
  【32.6 更新】体积差改按 F37 §1 口径：**32,712 B**（qtphp 4,403,640 vs 裸 tpc 4,370,928，同入口 yml / 同 O2 /
  同 250 TU），已排除 optimize（O0 档 6.99 MB 差量级不符）、cxx-std、tpc 入口（两边都是 `vendor/bin/tpc.php`）、
  `php-builder:` 段四项；剩「目标名/产物路径相关」与「增量复用来源不同」两候选，**仍标未归因**。
- [ ] 32.5 待用户点头：`typephp-compiler` 两处改动（`Translator.php` deps 宏降级、`CompilerBase.php` php-builder 门）
  是否提交/提交信息；本仓库 12 个改动文件是否提交（`git status --porcelain` 实测：README.md、bin/qtphp、
  docs/src/reference/cli.md、docs/src/zh/reference/cli.md、docs/src/advanced/headless.md、
  docs/src/zh/advanced/headless.md、examples/hello/src/main.php、src/QtApp.php、tests/QtAppTest.php、
  task_plan.md、findings.md、progress.md）；`stash@{0}: autostash` 处置。
  （原附带问题「要不要把 `-O2`/`cxx-std` 写进入口 yml 固化」已由 32.6 执行，不再待定。）
- [x] 32.6 `-O2`/`cxx-std` 固化进入口 yml（Session 28，用户令「把 -O2/cxx-std 写进入口 yml 固化」；详见 F37）：
  `examples/hello/project.yml` 改 `cxx-std: c++17` + 新增 `optimize: 2`（公共段，三平台入口都 include 它）；
  `qtphp new` 模板同步（`bin/qtphp` heredoc）；`bin/qtphp` nano 分支注释从「覆盖 yml 的 c++20」改写为
  「兜底老 yml」，**CLI 覆盖保留**；中英 cli.md 与 README 同步。**验收**：embed 重编 12 TU（pch 重建）⇒
  25,569,608 B 不变、四件套全过；nano 重编 250 TU ⇒ 4,403,640 B 不变、四件套全过；**裸 `tpc --nano`
  （无任何 CLI 覆盖）通过 `Translator.php:661` 严格校验**并编出可用产物 ⇒ cxx-std 直证；CLI `-O0` 覆盖对比
  6,989,240 B vs 4,370,928 B（+60%）⇒ `optimize: 2` 直证；`qtphp new` 冒烟通过（生成物含新键）。
  **副作用/发现**：体积差仍**未归因**（新边界见 32.4）；上游缓存缺陷（换 output 名 → undefined symbols）
  复现并记录（F37 §2）；打包验收前清 `build/cache/{objects,incremental,link}` 从干净状态重建。

## Errors Encountered

| Error | Attempt | Resolution |
|---|---|---|
| 脚手架模板改成 `echo $failed ? "selftest failed: ", $app->lastError(), "\n" : "selftest passed\n";` 后 `qtphp new`→`build` 报 `Fatal error: Syntax error, unexpected ','`（build rc=1） | 1 | AOT 前端不接受「三元 + 逗号实参表」的 `echo`。改回先赋值再 if/else 两支各自 echo ⇒ build rc=0。记入 F34 §3.2 |
| 想用 `$state['progress'] - $state['progress']` 造运行期零除数当负控制 ⇒ 编译通过但自检照旧 `selftest passed`、rc=0 | 1 | AOT 不把整数除零降级成 `DivisionByZeroError`（`%` 直接落到 C 取模）。负控制改用显式 `throw new \Exception(...)`，被 `safely()` 的 `\Throwable` 接住才写进 `lastError`（F34 §3.1） |
| 注入一处 handler 异常后 `--selftest` 打出 21 条 FAIL、行号全同 | 1 | 不是用例真炸：`QtApp::safely()` 从不清 `lastError`，旧异常冒充「最近一次」。改为每次分发前清空 + 新增单测锁定（F34 §4） |
| 第一次跑 `check-anchors.py` 用 `… 2>&1 \| tail -6; echo rc=$?` ⇒ 脚本 IndexError 却被报成 rc=0 | 1 | 重犯教训 8：测退出码不给被测命令加管道。改 `out=$(cmd 2>&1); rc=$?` 后为 anchors rc=0 / links 需要 dist 目录（产物级检查，本轮未跑） |
| 坏锚点注入第一次用 `perl -pi -e 's{\Q…\E}{…}/'` ⇒ perl syntax error，替换没发生，那次「负向 anchors rc=0」是**无效读数** | 1 | 改用 python 落注入并先 `assert s.count(old)==1` ⇒ 真注入后 anchors rc=**1** 并精确指到该行（F34 §5）。教训：负向控制要先确认「改动确实生效」，否则测的是 shell 的 bug |
| 负控制第一次读成 `rc=0`：命令写成 `php bin/qtphp package … \| tail -8`，取的是 `${pipestatus[2]}`（`tail` 的退出码） | 1 | 改成 `cmd > log 2>&1; echo $?` 再单独看 log ⇒ 真实 `rc=1`。教训：量退出码时不给被测命令加管道（F32） |
| 假设「nano 起不来是因为本仓库 vendor 的 phpx 太旧（2.7.0）」 | 1 | 否证：`2.7.0` 是姊妹仓库自己的 vendor，本仓库 `composer.lock` 实为 `swoole/phpx` **v2.9.3** + `swoole/typephp` v0.9.4（F35 §2）。假设当场作废、不带走 |
| 假设「该按文档 `composer require --dev swoole/php-nano:^1.0.2` 正常声明，手工塞 vendor 才是原因」 | 1 | 否证：php-nano 的 28 个 component 里没有任何一项对外叫 "Core"，唯一叫 "Core" 的 `zend_builtin_module` 是 `static`（符号不导出）⇒ 无论怎么声明都进不了启动数组（F35 §2） |
| 假设「php-nano 的 `standard` 也带 `REQUIRED("random")+REQUIRED("uri")`，所以可能还有第二个不匹配」 | 1 | 否证：那是 `#else` 分支（`basic_functions.c:489`）；nano 以 `-DPHP_NANO=1` 编 345 行那套，宏展开后 deps 为 NULL ⇒ 唯一不满足的仍是 "Core"（F35 §2） |
| 按 Qt4 记忆写 `QAbstractAnimation::NotRunning` 与 `anim->jumpToEnd()` ⇒ 两条编译错 | 1 | Qt6 枚举是 `{Stopped, Paused, Running}`（无 `NotRunning`）；`jumpToEnd()` 只在 `QPropertyAnimation`，通用写法是 `setCurrentTime(totalDuration())` + `stop()`（F28 §5a） |
| 猜「`QApplication::setEffectEnabled(QT_UI_EFFECT_ANIMATE_UI_CHANGE, false)` 能关 QLineEdit 淡入淡出」 | 1 | Qt6 的 `Qt::UIEffect` 只有 menu/combo/tooltip/toolbox 五种，无该值。改走 `findChildren<QAbstractAnimation *>()` 把在跑的动画推到终点，不依赖专有开关 |
| 给 includes 加 `<QAbstractAnimation>` 时误删 `#include <QDesktopServices>` | 1 | 下一次编辑立即补回；`git diff cpp-src/qt_bridge.cc` 全文复核确认两者都在 |
| 把 `--shot` 泵帧按 webview 后端收口成 `supportsJs ? 120 : 3` ⇒ 关开关后 cocoa 8 次出 4 种哈希 | 1 | 差异全在 `(622,66)-(636,80)` 的 QLineEdit 清除按钮淡入淡出 ⇒ 泵帧数管的是动画停下，不是后端异步；先改回无条件 120（F28 §4），再由 24.10 用冻结动画把帧数降回 3（真解） |
| ObjC++ 一次报 12 条错：`@interface` 进匿名 namespace、ivar 名 `link` 撞 POSIX `::link`、`QUrl::toNSString` 不存在、Qt6 `toNSString()` 不收参 | 1 | ObjC 类移到全局作用域；handler 只持宿主指针、回调 `report*` 成员函数（不再放 ivar 队列）；`toNSString()` 无参；`QUrl` 走 `[NSURL URLWithString:]` |
| 链接报 `Undefined symbols: _OBJC_CLASS_$_NSString/NSURL/NSView, _NSKeyValueChangeNewKey` | 1 | `.mm` 直接使这些类 ⇒ 必须显式 `-framework Foundation -framework AppKit`；QtGui 依赖 AppKit 不等于 ld 会替目标文件去它依赖里找符号 |
| `QT_QPA_PLATFORM=offscreen --shot` SIGSEGV（rc=139，PNG 不生成） | 1 | offscreen 的 `winId()` 是非 ObjC 指针；`attach()` 首行按 `QGuiApplication::platformName()` 闸门，非 cocoa 直接返回（控件保持空白，与「原生子 view 不进 grab()」的产物一致） |
| `--shot` PNG sha256 跨次不稳定：先记成 caret/hover，改完 `qtFreezeTransientUi` 仍翻 | 2 | 真噪声是**两层**：应用自己的 1 秒心跳文案 + 输入框焦点有无。旧写法「重设焦点」不收敛（冻结开/关跑出同一对哈希），改成 grab 前 `clearFocus()`、抓完还原才收敛（F28 §3、24.5） |
| 反证「摘掉冻结 16/16 同哈希」⇒ 差点判定验收标准落空 | 1 | forcing function 缺失：不额外延时时 grab 太快，噪声没机会出现。加 `SHOTDELAY` 每 10ms 泵一帧后立刻能按需复现 |
| 差点把「稳定下来」归因给 grab 前的 `repaint()` | 1 | 控制组证伪：④ 相对 ③ 在第一次 grab **之前**只多两次 `focusWidget()` 读取，不可能改像素；去掉 repaint 的 ⑤ 同样 15 样本 1 种 ⇒ 那是**环境漂移** |
| harness 用运行期 `setFocus()` 造脏，三种模式都「收敛」 | 1 | 假阴性：cocoa 上运行期 `setFocus()` 不产生焦点描边（`none` 也只有 1 种 = 根本没造出脏）。改用 `WA_ShowWithoutActivating` 做进程级两个起点 |
| `project.macos.yml` 之外漏记：`build/` 增量在源码回退时不重写生成物 | 1 | 见 F28 §2：字面量池只增不减 ⇒ 自证干净只能删 `build/` 全清重建 + 行为比对 |
| `QObject::connect` 上下文传 `QtWindowBox*` 失败 | 1 | `QtWindowBox` 继承 `php::Box` 而非 `QObject`，context 改用 `window_` |
| `QLayout::addStretch` 不存在 | 1 | 用 `addItem(new QSpacerItem(...))`；`addWidget` 的 stretch 参数需 `QBoxLayout` |
| `link-libs: Qt6Core` 被当 `.obj` | 1 | 写成 `Qt6Core.lib` |
| 示例全局 `$state` → stray code | 1 | 移进 `main()`，闭包 `use (&$state)` |
| 混合花括号/非花括号 namespace | 1 | 统一风格 |
| `main()` 不在全局 → Embed SAPI 报错 | 1 | 移到全局作用域 |
| 闭包 `$event` 未定义 | 1 | 加显式类型 `function (array $event)` |
| `QtApp.cc` 找不到 `php_qt_*` | 1 | 桥接调用加 `\` 全局前缀 |
| `cstring: No such file` | 1 | CLI 只查 `cl.exe` 误判，改查 `INCLUDE` 并自动 call vcvars64 |
| `parameter 1 must be array, got bool` | 1 | `Array*` 隐式转 bool，9 处 `&spec` 全部去掉 |
| 第二次渲染崩溃 `0xC0000005` | 1 | 自动 id 每帧递增 → 控件反复重建 + 悬垂指针；改为结构路径稳定 id |
| `qtphp new` 模板编译失败 | 1 | 模板同步修正（全局作用域、main 签名、闭包类型） |
| `qtphp package` 后 exe 缺 DLL | 1 | 部署逻辑补 Qt DLL + `platforms/qwindows.dll`；去掉已不需要的 bridge DLL |
| 点按钮报 `expects exactly 0 arguments, 1 given` | 1 | AOT 对闭包实参个数精确校验；注册时反射探测 arity，按实际个数调用 |
| `--selftest` 卡死不退出 | 1 | 模态对话框在无头环境阻塞；新增 `QtApp::headless()` |
| `Cannot re-assign typed object $ref` | 1 | AOT 类型推断限制；`ReflectionMethod`/`ReflectionFunction` 分用两个变量 |
| **Windows `build` 报 `PHPX runtime library was not found at: ...\phpx\build\phpx.dll`** | 1 | composer 驱动（`vendor/bin/tpc.php`）被选中，而它依赖的 `vendor/swoole/phpx` 源码树不含编译产物；原生 tpc.exe 发行包才是自包含的。改为**按运行时体检选路**（F24） |
| `--difftest` 在 Windows 上「崩溃」（`0xC0000409`），而 `--selftest` 正常 | 1 | **自己造的**：注入诊断标记时用 python heredoc 把 `"\n"` 写成了字面换行，破坏了字符串字面量。`php -l` 仍报「无语法错误」（因为换行在双引号里合法），但语义已变。教训：注入诊断代码后必须**跑一次再下结论**，且别在受损文件上叠加 Edit —— 还原后干净重建，两者都 14/14、20/20 |
| macOS `doctor` 报「tpc 未找到」 | 1 | composer 只发 `bin/tpc.php`（`bin/tpc` 被跳过）；`findTpc()` 加 `.php` 候选并用 `PHP_BINARY` 起 |
| macOS `build` 报 `Source file not exists: D:/git/php/...` | 1 | `sources` 改成相对 yml 目录的路径（tpc 本就按 `projectDir` 解析，Windows 同样成立） |
| macOS 链接前即失败：`Neither libphp.dylib nor libphp.a found` | 1 | 未解：宿主 PHP 无 embed SAPI，需 tpc `--php-builder` 现编私有运行时（非交互终端必须显式传参） |
| 只给 `-I framework/Headers` 时 `QtWidgets/qapplication.h` not found；只给 `-F` 时裸 `<QApplication>` not found | 2 | `-F$Q/lib` 与三个 `*.framework/Headers` 的 `-I` **同时**给（F11.4） |
| phpx 静态库编译报 `gmpxx.h` / `mpfr.h` not found | 1 | vendor 缺陷：`phpx/sapi-static/CMakeLists.txt` 没抄根 `CMakeLists.txt:287` 的 brew 前缀块，而 tpc 的 cmake 调用写死、注入不了 flag ⇒ 构建时给 `CPATH=/opt/homebrew/include`（gmp/mpfr 已装且已软链） |
| 编译期 `QtWidgets/qabstractitemview.h` not found（framework 转发头内部用限定名） | 3 | `-F$QT/lib` 除 `ld-flags` 外**还必须进 `cxx-flags`** |
| 链接缺 `_libiconv` / `_libiconv_open` / `_libiconv_close` | 1 | 私有 PHP 链的是 brew libiconv（keg-only，不在 `/opt/homebrew/lib`）；`ld-flags` 补 `-L/opt/homebrew/opt/libiconv/lib -liconv` + rpath |
| `--selftest` 在 `copy_btn` 处永久阻塞（`sample` 抓到栈：`notify`→`showNewMessageBox`→`QDialog::exec`） | 1 | 无系统托盘时 Qt 把 `QSystemTrayIcon::showMessage` 回退成**模态**消息框；`QtApp::notify()` 是唯一漏掉 `headless` 守卫的阻塞调用，补上后 10/10 通过 |
| mac 依赖自检把 `libc++/libxml2/libz/libSystem` 等 6 条报成缺失 | 1 | macOS 11 起系统 dylib 只在 dyld shared cache 里、磁盘无文件 ⇒ 跳过 `/usr/lib/`、`/System/Library/`，只核对第三方绝对路径（F11.6） |
| `bin/qtphp` 解析期挂掉：`syntax error, unexpected token "/"` | 1 | docblock 里写路径 `~/.typephp/php-builder/php-*/install/lib`，其中 `*/` **提前闭合注释**。注释里提 glob 路径要么写 `php-<版本>-<指纹>/…`，要么别带 `*/` |
| 11.3 前置误判：「`deployRuntimeDlls()` 完全静默复制 0 个文件」 | 1 | 复核代码：末尾确有 `$copied > 0` 的兜底 warning，但原因不明（分不清「没目录」和「有目录没 DLL」），且 `findTpcDir()` 返回 null 时拼出 `/phpx.dll`。改成按标记文件筛目录 + 分原因告警 |
| 11.4 整条计划项做空：仓库根 `project.yml` 不存在 | 1 | 计划项来自 README 项目树与 Session 1 文件清单，而磁盘/ git 都没有该文件（`.gitignore` 反而把它列为生成物）。**教训**：动手前先 `find`/`git log -- <path>` 核对文件是否真存在，README 的结构图不是证据 |
| `qtphp run .` 报 `sh: ./build/.: is a directory`（rc=126）、`qtphp package .` 报「未找到 bundle 描述文件」 | 1 | `basename('.')` 返回 `.`，产物名拼成 `./build/.`；且 `file_exists('./build/.')` 因它是目录而**为真**，前置校验形同虚设 ⇒ 新增 `projectDir()`（`rtrim`+`realpath`）供 `cmdRun`/`cmdPackage` 使用。**注意**：生成的 `package.bat` 与 README 都用 `.` 调用，这条在 Windows 上同样中招 |
| `--difftest` 首轮 7/12 失败：重渲染后表格/树选中全丢、`patch` 非结构属性把控件清空 | 1 | 四个独立根因（见 F13 与 12.1）：重建排在 `applyNodeProps` 之后、每帧无条件重建、`patch()` 传的是操作数组而非 `props`、结构键跳过表未按类型区分。逐个定位后修法各见 12.1；**教训**：声明式框架的「保留状态」承诺只能用真实控件二进制验，FakeBridge 层测不到 |
| `qtIsStructuralKey()` 首版把 `rows` 判成全局结构键 → `QTextEdit` 的行数属性被静默作废 | 1 | 结构键集合按 `type` 分派（`qtIsStructuralKey(type,key)` / `qtStructuralKeys(type)`），`table` 才认 `columns/rows/row_ids`、`tree` 才认 `headers/nodes`，其余类型一律返回 false |
| clang++：`no matching member function for call to 'get'`（`spec.get(key)`，key 是 `QString`） | 1 | `php::Array::get` 只接 `const char*`/`size_t`；改为 `const QByteArray name = key.toUtf8(); spec.get(name.constData())` |
| 容器里 `apt-get update` → `Unable to connect to mirrors.aliyun.com`，但 DNS 能解析 | 3 | 不是 apt 源问题：这台机器的 Apple Container **拿不到 NAT egress**（全新机器同样 FAIL、`mode=bridged` 仍是 host-only、`system stop/start` 无效）。改成在宿主网关 IP 上起只读转发代理，apt/curl 指过去（F20） |
| `container exec tgl -- bash -c …` → `failed to find target executable --` | 1 | `container exec` 不接受 `--` 分隔符，直接 `container exec <name> <cmd…>`；`container create` 的镜像是**位置参数**不是 `--image` |
| 代理已 LISTEN、`nc` 也证实容器能连上网关端口，`tgl` 里 apt 仍报 `Unable to connect to 192.168.64.1:3128` | 1 | `container system start` 后 `default` 网段迁到 `192.168.65.0/24`，且两台机器同为 `.2` 造成 ARP 冲突 ⇒ 删掉探测机、代理按新网关 IP 重绑 |
| Linux 首轮编译 `fatal error: mpfr.h: No such file or directory` + `gmpxx.h` | 1 | phpx 的 `big_float.cc`/`big_int.cc` 要 gmp/mpfr 头；macOS 上 brew 顺带装了，Linux 得显式 `apt install libgmp-dev libmpfr-dev`（F21） |
| 放了 `~/.typephp/archives/php-8.5.11.tar.xz` 仍见 `curl … php.net/releases/index.php` | 1 | tpc 每次构建都要先取索引拿 pinned sha256 才决定复用本地包（`OfficialPhpSource::prepareLocked()`）⇒ 纯离线机器第一次 build 必失败 |
| Debian 下 `-I/usr/include/qt6` 找不到（想写架构无关路径） | 1 | 实测 `/usr/include/qt6` 不存在，只有 `/usr/include/<三元组>/qt6`；而 tpc 的 yml 只对 `sources` 支持 `PHP_OS_FAMILY` 条件、路径不插值 ⇒ 三元组只能在 `qtphp new` 生成时写死 |
| Debian 12 slim 镜像里没有 `patchelf`，Linux 打包第一步就断 | 1 | `apt install patchelf` → `0.14.3-1+b1`；`packageLinuxDir()` 前置探测并给出该提示，README 的 apt 清单同步加上 |
| `du -ch $(ldd \| 绝对路径)` 量出闭包只有 2.5 MB | 1 | `/lib/aarch64-linux-gnu/libQt6*.so.6` 是**软链**，`du` 按链接算 ⇒ `readlink -f` 后实测 89 个对象 83.2 MB（`libicudata` 一个 29.8 MB） |
| 手敲 `ldd \| grep -v "$(pwd)/lib/"` 想筛「落在产物外的依赖」，结果一行都没滤掉 | 1 | `$ORIGIN` 是按传入路径的 dirname **文本展开**的，跑 `./hello` 时得到 `dist/hello/./lib/…` ⇒ 自检改成先 `realpath` 再比前缀（`verifyLinuxPackage()`），否则换个调用形式就误报 |
| `apt-get install -y patchelf`（remove 之后重装）报 `Could not connect to 192.168.65.1:3128` | 1 | `.deb` 已不在 apt 缓存里 ⇒ 重装要先起 F20 的宿主转发代理；顺带证实容器里装好的包是**跨会话留存**的 |
| 按 PHP `--enable-mbstring` 的习惯把 `libonig-dev` 的探测头写成 `onigmo.h` | 1 | 实测 Debian 12 只有 `/usr/include/oniguruma.h`，`onigmo.h` 不存在 ⇒ 探 `oniguruma.h`，否则 `doctor` 永久误报缺失（F23） |
| macOS 上 `webview` 的 `url` 探针渲染成**一片空白**，`--shot` 仍 rc=0 | 1 | **我自己的路径写错**：`qtResolveUrl` 走 `qtResolvePath`，相对 **exe 目录**（`build/`）解析，而 `assets/` 是部署到 `build/assets/` 的 ⇒ 裸文件名 `webview_url_probe.html` 指向了不存在的路径。改 `assets/webview_url_probe.html` 后正常出图（含相对 `<img>`）。**真正值得记的是缺陷形态**：文件不存在时 QTextBrowser 后端**零诊断**（不报错、不警告、退出码 0），而 WebView2 后端会发 `loaded` + `success=false` ⇒ 两后端在「可诊断性」上不对等（F26 尾部） |
| mac 上 webview 喂 `url => 'https://…'` 渲染不出，Qt 打 `QTextBrowser: No document for https://…`，`--shot` 仍 rc=0 | 4 | 逐个排除外部原因：本机有网（`curl` 200）、链了 `QtNetwork.framework`（补进 `link-libs` 后 PNG **一字不变**）、非 TLS（换 `http` 同样失败）、非异步没等到（`QT_TEXTBROWSER_SYNCHRONOUS=1` 无效），再用 `file://` 作对照能出图 ⇒ **Qt 6 的 `QTextBrowser` 只加载本地文件，远程 url 是能力缺口不是 bug**。被追问「是不是拿纯字符串测的」后重做到对象级（独立 Qt 程序，不经 PHP/AOT）：`QUrl` 解析正常（`scheme=https host=example.com isLocalFile=0`）、警告由 `setSource` 同步打出、真事件循环 6 秒文档仍 0 字符、同进程 `QNetworkAccessManager` 拿到 200/577 字节、`otool -L QtWidgets` 的 NEEDED 里没有 QtNetwork。scheme 矩阵另查出 **`data:`（含 base64）同样空白** ⇒ 非 Windows 后端**只有 `file` 可用**。连带查出文档过度承诺：`docs/src/zh/widgets/webview.md:44` 写「带 scheme 的原样使用」（F27 第 3 条） |
| 新克隆在 Windows 上 `build` 必报 `LNK1181: 无法打开 WebView2Loader.dll.lib` | 1 | `.gitignore:9` 的 `*.lib`（本意是排 MSVC 构建产物）**没有锚定到根**，把 `third_party/webview2/x64/WebView2Loader.dll.lib` 一起吞了 ⇒ `git ls-files third_party/webview2/` 里只有头文件和 loader DLL，偏偏缺 `project.yml:39` 与 `bin/qtphp:470` 写死要链的那一个。加 `!third_party/**/*.lib` 放行（dummy 文件三重确证：`check-ignore` rc=1、`git add -n` 可收、`/build/` 规则未削弱）。但**本机磁盘上根本没有这个文件**（`find third_party -type f` 只有 5 项：nuspec、README、两个 header、`WebView2Loader.dll` 194,912 B）⇒ **那 3.5 KB 的 import lib 只能从 vendor 它的 Windows 机器补交**（F27 第 5 条） |
| 连跑 6 次 `--shot` 得到 **两种** PNG sha256（5×`fc9319b597…` / 1×`0876b10bf0…`），差点把「哈希不同」当成回归 | 3 | 用 PIL `ImageChops.difference().getbbox()` 定位到两处**外部状态**：行 62–83 是 QLineEdit **光标闪烁**相位，行 362–380 是「打开日志窗口」按钮的 **hover/焦点描边**；PNG 里没有 `tIME` chunk ⇒ 差异是真实像素。**结论：sha256 相等只能单向用**（相等 ⇒ 无变化，成立；不同 ⇒ 有回归，不成立，~1/6 概率纯属噪声）。要让 CI 拿 sha256 当基线，得先 `QApplication::setCursorFlashTime(0)` 并固定焦点（F28 第 1 节，未做） |
| 「还原探针后重编复验」的产物里仍查得到 `--linkprobe`/`WVURL`/`webview_url_probe`，且 `build/extension-hello.cc` 的 mtime 停在探针期 | 2 | 一次性标记实验可复现：**增量构建在源码回退到「曾构建过的状态」时不重写生成文件**，字面量池只增不减（残留只在 `php::Str{ZEND_STRL(...)}` 池内、无代码引用），而编出来的代码是新的（还原后的产物与全清重建产物的标题行区域 `bbox=None`）。⇒ 本轮改成**删掉整个 `build/` 全清重建**重测：`--selftest` 25/25、`--difftest` 20/20、`--shot` rc=0，产物内探针字符串 0 处。教训：**别用 `strings 产物` 自证干净**，要全清重建 + 行为比对（F28 第 2 节） |

## Decisions Log

- 采用「声明式控件树 + id diff」而非「命令式句柄」：状态保留 + 写法最省事，是"方便封装"的核心价值。
- **源码内联为唯一方案**（实测证伪预编译库路线）：tpc `-m lib` 只从**有函数体**的 PHP 实现导出签名，
  而桥接 stub 按契约必须空体，故生成的 stub 恒为空。桥接 `.cc` 直接列进应用 `sources`。
- `qtphp` CLI 用 PHP 写（跨平台、可复用 composer 生态），不写 bash/bat 为主入口；bat 仅作为生成项目的便捷壳。
- FakeBridge 用**全局函数**而非类：与真实桥接形态一致，领域层代码在测试与 AOT 下都成立。
- 无 id 节点的 id 由 C++ 按**结构路径**生成，保证 diff 稳定。
- **事件处理器 arity 在注册时探测**：用反射而非"传参失败再回退"，避免异常做控制流导致处理器前半段重复执行。
- **提供 `--shot` 与 `--selftest` 两个无头开关**：FakeBridge 跑在 ZendPHP 上无法复现 AOT 的严格性，必须用真实二进制验收。
- **跨平台 = 平台入口 yml + 回落，不给 project.yml 加"条件字段"**（Session 3）：tpc 只有 `sources`/`objects`
  支持 `if:`，`include-paths`/`link-libs`/`cxx-flags` 是逐项 `(string)` 直取（见 F11.3）。所以
  `project.yml` 保持「公共段 + Windows 默认段」，macOS 用 `project.macos.yml` 通过 `include` 继承后
  **整体替换**列表；`cmdBuild` 按 `PHP_OS_FAMILY` 挑入口，无平台文件时回落 —— Windows 与 `build.bat` 零改动。
- **平台文件命名与打包流程对齐上游 `typephp-compiler/examples/qt-taskboard`**（Session 3，用户指定参考）：
  后缀用 `macos`/`windows`/`linux` 而不是 `PHP_OS_FAMILY` 原值（`Darwin ≠ macos`）；Qt 的 `-F/-I/-D` 放
  `cxx-flags`、framework 用 `-Wl,-framework,X`；打包按 `package-macos-app.sh` 的路子（checked-in
  `Info.macos.plist` → `macdeployqt -always-overwrite -no-codesign` → ad-hoc `codesign --force --deep --sign -`
  → `plutil -lint` + `codesign --verify`）。上游用共享 `libphp.dylib`，我们是 `--enable-embed=static`
  静态链入，所以 bundle 不需要搬 PHP/PHPX。
- **脚手架无条件生成双平台入口，不按宿主平台裁剪**（11.6）：`qtphp new` 在 Windows 上也写出
  `project.macos.yml` + `Info.macos.plist`，反之亦然 —— 项目迟早要在另一台机器上编，
  而 `cmdBuild` 只在 Darwin 才选 mac 文件，多出来的文件对 Windows 是纯静态资源、零风险；
  按宿主裁剪反而会让「Windows 建的仓库到 mac 上编不动」这种问题复现。
  brew 前缀从 `findQt()` 反推而不是写死 `/opt/homebrew`，是为了 Intel Mac 也拿到正确路径。
- **`.php` 的 tpc 入口一律用 `PHP_BINARY` 起**：composer 只发 `bin/tpc.php`，把它当可执行文件调必失败。
- **「tpc 可执行文件在哪」与「PHP 运行时库在哪」分成两个解析器**（11.3）：composer 发行包里
  `vendor/bin/tpc.php` 旁边 0 个库文件，`dirname(tpc)` 这个用途从根上就是错的。
  新 `findRuntimeLibDir()` 用**标记文件**判定（`phpx.dll`/`php8ts.dll`/`libphp.a`/`libphp.dylib`），
  顺序为 native tpc 同目录 → `TPC_RUNTIME_DIR` → `~/.typephp` 私有运行时；定位不到就让调用方
  **点名原因**地告警，不再靠末尾 `$copied > 0` 的笼统兜底。
- **macOS 只装 `qtbase` 不装 `qt`**：桥接只用 QtCore/QtGui/QtWidgets，`brew install qt` 会多拖 38 个 formula（含 qtwebengine）。
- **表格/树的重建由「结构键是否变化」驱动，选中恢复在重建函数内部完成**（12.1）：结构键（`table` 的 `columns/rows/row_ids`、
  `tree` 的 `headers/nodes`）从 `applyNodeProps` 里摘出来、走 `structuralChanged()` 复用同一份 `propSigs_` 签名，
  避免「属性签名表」和「重建判定」两套状态各记各的而漂移。选中一律按 `Qt::UserRole` 里的 **id** 找回
  （`row_ids`/节点 `id` 缺失才退化成索引），且整段恢复包在 `QSignalBlocker` 内 —— 否则重放选中会被当成用户操作发出 `select` 事件。
  重建成品必须排在 `applyNodeProps` **之前**：`current` 是属性，晚于重建就会被抹掉。
- **无头验收两条路都走：build 目录二进制 + 打包产物**（Session 7–8）：产物默认多带一个 156 KB 的
  `libqoffscreen.dylib`，换来「CI 上没有 GUI 会话也能验证 .app 起得来、handler 挂得上」——
  这个框架的卖点就是无头可测，而产物因 macdeployqt 只拷 cocoa 而一设 offscreen 就 SIGABRT，不划算（F16/F17）。
  裸环境（`env -i` + cocoa）那条仍然保留，它管的是「装到别人机器上还能不能跑起来」。
- **平台不支持就明确报错，不借用邻近平台的流程**（12.6）：`PHP_OS_FAMILY !== 'Windows'` 这种写法把 Linux
  当成 macOS，报出来的错（缺 macdeployqt / Info.macos.plist）指不到真正缺的东西。改成显式三路判断，
  同时把「Linux 支持」记成一个成建制的缺口（F19）而不是顺手糊掉。
- **Linux 自包含靠 patchelf 的 DT_RPATH，不靠链接期 rpath**（12.8）：两条理由。① `ld.so` 的 RUNPATH
  只对本对象自己的 NEEDED 生效，RPATH 才沿依赖链传递 —— 只在可执行文件上设 RUNPATH，`libQt6Gui` 的
  `libglib/libEGL/libfontconfig` 仍回落系统 ld.so.cache，本机一切正常、换台没 Qt 的机器才炸；
  所以用 `patchelf --force-rpath` 并**在自检里读 `readelf -d` 断言有 `(RPATH)` 且无 `(RUNPATH)`**。
  ② 链接期 `-Wl,-rpath` 覆盖不到 Qt 平台插件（插件不是我们链出来的，`libqxcb.so` 那 14 个 xcb 依赖
  根本不在 exe 闭包里），而插件同样需要 `$ORIGIN/../../lib`。附带好处：`build/` 下的开发产物保持原样，
  只有 package 阶段动产物。
- **能上真机就不再用反证**（12.7）：F19 那三条在 macOS 上只能靠「临时改条件逼出分支」间接验，用户点出
  Apple Container 可以模拟之后，Linux 侧换成真 Debian 12 arm64 编译真验收。代价是先解决容器没有
  NAT egress（宿主只读转发代理，F20），收益是把「成建制缺口」一次性收窄成「只剩打包路线」，
  并且白捡一条兼容性证据：**Qt 6.4.2（Debian 12）与 6.11.2（brew）上 14+20 条断言完全一致**（F21）。

## 目标产物（交付标准）

- [x] `composer.json` 可 `composer validate`，含 `bin`、PSR-4、scripts
- [x] `bin/qtphp doctor|new|build|run|package|test|lint` 全部可用
- [x] 示例应用能 `build`（tpc 编译成功）→ `--shot` 出 PNG → `package` 自检通过
- [x] `qtphp test` 在无 Qt 环境下全绿（**127 tests / 215 assertions**）
- [x] 示例 `--selftest` 在真实 AOT 二进制下全部事件通过
- [x] 示例 `--difftest` 在真实 AOT 二进制下 20/20 通过（表格/树 diff + `patch` 的 `call` 行为验收）
- [x] `--shot` 出图可当 CI 基线：三类瞬态（墙钟文案 / 焦点 / 瞬态动画）全部在 `snapshot()` 里按掉，
      配方只需 `runFrames(3)`，cocoa 与 offscreen 各 ×8 收敛为 1 种哈希（24.5、24.9、24.10 / F28）。
      **Windows / Linux 侧的同配方收敛未验**（需真机或 tgl 容器复跑）
- [x] 示例演示多窗口 / 托盘 / 定时器，`--selftest` **25/25** 覆盖其 handler（Phase 20/21 补手势与信号后从 14 涨到 25）
- [x] `webview` 控件在 macOS 原生二进制上真机渲染 —— `html` 与 `url`（本地文件，相对 exe 目录）两条分支都出图确认，且 `project.macos.yml` 零改动即自动落到 QTextBrowser 后端（24.1/24.2）
- [x] macOS 有**支持 JS 的第三后端 WKWebView**（系统 WebKit，零安装）：`html`/`url`（远程）/`zoom` 与 `navigating`/`loaded`/`title`/`link` 四事件全部真机读回，`qtphp new` 生成的 mac 入口默认启用（24.8）
- [x] `qtphp package` 的产物自带 offscreen 插件 ⇒ bundle 本身可在无 GUI 会话下验收（12.4）
- [x] mac `package` 会在 bundle 内真跑一遍 offscreen `--selftest` 并断言 stderr 无告警 ⇒
      「资源加载不上但像素照旧」这类缺陷有自动化判据，不再靠人眼扫日志（29.1 / F32）。
      **Linux / Windows 侧同款判据未做**
- [x] 三个无头开关（`--selftest`/`--difftest`/`--shot`）**失败即 rc=1**，`qtphp run` 原样透传，
      `package` 把 rc 当主判据 ⇒ CI 判退出码即可，不必 grep 输出（31.1 / F34，mac 正反两向实测；
      Linux / Windows 侧同一语义未跑）
- [x] Linux（Debian 12 arm64 + Qt 6.4.2）真机 `build`→`run`→offscreen `--selftest`/`--difftest`/`--shot` 全绿（12.7）
- [x] Linux 真机 `package` → `dist/<name>/` 自包含（`ldd` 闭包无一行落在产物外 + DT_RPATH 断言）⇒ 产物 `env -i` offscreen 下 14/14 + 20/20 + 出图全 rc=0，且渲染与开发产物逐字节一致（12.8）
- [x] README 说清 5 分钟上手路径

## 规模（实测）

| 部分 | 行数 |
|---|---|
| `cpp-src/`（qt_common.h + qt_bridge.cc + qt_widgets.cc + qt_webview.cc + qt_webview_wk.mm） | 2981 |
| `src/`（QtApp + WidgetTree + FakeBridge） | 1112 |
| `bin/qtphp` | 2122 |
| `php-src/qt.stub.php` | 157 |
| 合计 | 6372 |
