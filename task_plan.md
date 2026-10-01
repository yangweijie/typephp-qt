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

- Qt: `D:\tools\Qt\6.9.3\msvc2022_64`（环境变量 `QT_DIR`）
- TypePHP: `D:\git\php\tpc_v0.9.4_windows_x64`（`tpc.exe` v0.9.4，`phpx/`）
- MSVC: `D:\Program Files (x86)\Microsoft Visual Studio\2022\BuildTools\...\vcvars64.bat`
- 平台: Windows 10.0.26100 / cmd.exe

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

## Decisions Log

- 采用「声明式控件树 + id diff」而非「命令式句柄」：状态保留 + 写法最省事，是"方便封装"的核心价值。
- **源码内联为唯一方案**（实测证伪预编译库路线）：tpc `-m lib` 只从**有函数体**的 PHP 实现导出签名，
  而桥接 stub 按契约必须空体，故生成的 stub 恒为空。桥接 `.cc` 直接列进应用 `sources`。
- `qtphp` CLI 用 PHP 写（跨平台、可复用 composer 生态），不写 bash/bat 为主入口；bat 仅作为生成项目的便捷壳。
- FakeBridge 用**全局函数**而非类：与真实桥接形态一致，领域层代码在测试与 AOT 下都成立。
- 无 id 节点的 id 由 C++ 按**结构路径**生成，保证 diff 稳定。
- **事件处理器 arity 在注册时探测**：用反射而非"传参失败再回退"，避免异常做控制流导致处理器前半段重复执行。
- **提供 `--shot` 与 `--selftest` 两个无头开关**：FakeBridge 跑在 ZendPHP 上无法复现 AOT 的严格性，必须用真实二进制验收。

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
