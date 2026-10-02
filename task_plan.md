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

### macOS（Phase 10–11 交付环境，2026-10-02 实测）

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

## 目标产物（交付标准）

- [x] `composer.json` 可 `composer validate`，含 `bin`、PSR-4、scripts
- [x] `bin/qtphp doctor|new|build|run|package|test|lint` 全部可用
- [x] 示例应用能 `build`（tpc 编译成功）→ `--shot` 出 PNG → `package` 自检通过
- [x] `qtphp test` 在无 Qt 环境下全绿（**103 tests / 162 assertions**）
- [x] 示例 `--selftest` 在真实 AOT 二进制下全部事件通过
- [x] README 说清 5 分钟上手路径

## 规模（实测）

| 部分 | 行数 |
|---|---|
| `cpp-src/`（qt_common.h + qt_bridge.cc + qt_widgets.cc） | 1855 |
| `src/`（QtApp + WidgetTree + FakeBridge） | 963 |
| `bin/qtphp` | 924 |
| `php-src/qt.stub.php` | 137 |
| 合计 | 3879 |
