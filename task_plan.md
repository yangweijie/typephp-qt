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

## Errors Encountered

| Error | Attempt | Resolution |
|---|---|---|
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
- [x] `qtphp test` 在无 Qt 环境下全绿（**112 tests / 183 assertions**）
- [x] 示例 `--selftest` 在真实 AOT 二进制下全部事件通过
- [x] 示例 `--difftest` 在真实 AOT 二进制下 20/20 通过（表格/树 diff + `patch` 的 `call` 行为验收）
- [x] 示例演示多窗口 / 托盘 / 定时器，`--selftest` 14/14 覆盖其 handler
- [x] `qtphp package` 的产物自带 offscreen 插件 ⇒ bundle 本身可在无 GUI 会话下验收（12.4）
- [x] Linux（Debian 12 arm64 + Qt 6.4.2）真机 `build`→`run`→offscreen `--selftest`/`--difftest`/`--shot` 全绿（12.7）
- [x] Linux 真机 `package` → `dist/<name>/` 自包含（`ldd` 闭包无一行落在产物外 + DT_RPATH 断言）⇒ 产物 `env -i` offscreen 下 14/14 + 20/20 + 出图全 rc=0，且渲染与开发产物逐字节一致（12.8）
- [x] README 说清 5 分钟上手路径

## 规模（实测）

| 部分 | 行数 |
|---|---|
| `cpp-src/`（qt_common.h + qt_bridge.cc + qt_widgets.cc） | 2052 |
| `src/`（QtApp + WidgetTree + FakeBridge） | 1032 |
| `bin/qtphp` | 1893 |
| `php-src/qt.stub.php` | 151 |
| 合计 | 5128 |
