# TypePHP\Qt

基于 TypePHP (AOT) + Qt 6 的原生桌面 GUI 应用快速开发框架。

📖 **完整文档：https://yangweijie.github.io/typephp-qt/**（英文，30 页）· [中文文档](https://yangweijie.github.io/typephp-qt/zh/)（29 页）—— 分指南、控件目录、深入原理、参考手册四部分。文档源文件在 [`docs/`](docs/)。

## 特性

- **声明式 UI** — 用 PHP 数组描述控件树，C++ 侧按 id 做差异更新，控件状态（输入光标、表格选中、滚动位置）在重渲染后保留
- **状态驱动** — 事件处理器只改状态，`view()` 注册的构建函数每帧按新状态重新描述界面
- **PHP 主循环** — Qt 事件泵由 PHP 驱动，业务逻辑全部留在 PHP 里，可单测
- **源码内联** — 桥接 C++ 直接参与应用编译，不依赖预编译二进制，永远不会和 Qt/PHPX 版本脱节
- **一键打包** — `qtphp package` 组装自包含产物并自检：Windows 出 `dist/` 目录（windeployqt + PHP/PHPX 运行时 + 平台插件），macOS 出 `dist/<Name>.app`（macdeployqt + ad-hoc 签名，PHP 侧全静态无需搬运行时），Linux 出 `dist/<name>/`（`ldd` 传递闭包搬进 `lib/` + `patchelf` 把 DT_RPATH 改成 `$ORIGIN/lib` + `qt.conf` 指插件目录）
- **无头测试** — 纯 PHP 桥接替身，不需要 Qt 或编译器；三个内置开关做自动化验收：`--shot` 出 PNG、`--selftest` 逐个触发事件、`--difftest` 验表格/树的 diff 边界
- **内嵌网页** — `WidgetTree::webView()`；Windows 上是 WebView2（完整 Chromium、支持 JS），其余平台自动退回 QTextBrowser（HTML 子集），同一份 PHP 代码两边都能跑

## 平台支持

| 平台 | 编译 / 运行 / 无头验收 | 打包 `qtphp package` | 依赖 |
|---|---|---|---|
| Windows | ✅ | ✅ `dist/` 目录（windeployqt） | Qt 6 + MSVC |
| macOS (Apple Silicon) | ✅ | ✅ `dist/<Name>.app`（macdeployqt + ad-hoc 签名） | `brew install qtbase libiconv` |
| Linux (Debian/Ubuntu) | ✅ | ✅ `dist/<name>/`（ldd 依赖闭包 + patchelf 改 DT_RPATH + `qt.conf`） | 见下 |

**只用到 Qt 的 `Core` / `Gui` / `Widgets` 三个模块**（没有 QML/Quick、Network、Sql），
所以最小安装就够。各系统的安装方式、其他发行版的包名、非默认 Qt 版本怎么指定、链接报错怎么查，
见 **[Qt 的安装与编译](https://yangweijie.github.io/typephp-qt/zh/guide/qt-setup.html)**。
`qtphp` 自动探测的是 Qt **6.9.3** 的常见路径；其他版本用 `QT_DIR` 指定（优先级高于自动探测）。

Linux 侧的实测环境是 Debian 12 arm64 + `qt6-base-dev 6.4.2` + `cmake 3.25.1`：
`build` 产出 ELF PIE 可执行文件，`QT_QPA_PLATFORM=offscreen` 下三个验收开关全部 rc=0
（当时示例的 `--selftest` 是 14 条用例、`--difftest` 20 条，示例后来扩充过，用例数已增长）。
`package` 出 `dist/hello/`（实测 85 个 `.so` + 10 个 Qt 插件，140.3 MB），
产物在 `env -i QT_QPA_PLATFORM=offscreen` 下同样全 rc=0。
首次编译要现编私有 PHP embed 运行时，
除 Qt 外还需这些包（phpx 的 gmp/mpfr 与 PHP 源码的构建流程需要 bison/re2c 等工具，brew 在 mac 上是顺带装好的）：

```bash
apt install -y qt6-base-dev cmake g++ pkg-config bison re2c autoconf xz-utils patchelf \
  zlib1g-dev libxml2-dev libsqlite3-dev libonig-dev libgmp-dev libmpfr-dev
```

### tpc 从哪来

`qtphp` 按「**带运行时**」优先挑编译器，两条供给路线都支持：

| 路线 | 位置 | 运行时来源 |
|---|---|---|
| 原生发行包 | 解压目录里的 `tpc.exe` / `tpc` | **自包含**：`phpx.dll`、`SDK/` 就在可执行文件旁边 |
| composer 驱动 | `vendor/bin/tpc.php` | `vendor/swoole/phpx` 源码树，**首次构建时现编**私有 embed 运行时 |

两者不通用：composer 驱动去找 `vendor/swoole/phpx/build/phpx.dll`，而该源码包不含编译产物 ——
所以机器上**同时存在**两者时，若 composer 驱动还没建好运行时，`build` 会报
`The PHPX runtime library was not found at: …\phpx\build\phpx.dll`。
`qtphp` 会自动跳过这种「装得出却跑不通」的候选，改用带运行时的那个；也可以用环境变量显式指定：

```bash
TPC=/path/to/tpc.exe   qtphp build .   # 直接指定编译器（跳过体检）
TPC_DIR=/path/to/dir   qtphp build .   # 指定安装目录
```

`qtphp doctor` 会打印实际选中的 tpc 与运行时库目录，排查时先看这两行。

## 5 分钟上手

### 1. 安装

```bash
composer require yangweijie/typephp-qt
```

### 2. 创建项目

```bash
qtphp new myapp
cd myapp
```

### 3. 写代码

编辑 `src/main.php`：

```php
<?php

declare(strict_types=1);

use TypePHP\Qt\QtApp;
use TypePHP\Qt\WidgetTree;

function main(int $argc, array $argv): void
{
    // 应用状态：事件改它，视图读它
    $state = ['name' => 'World', 'greeting' => 'Hello, World!', 'clicks' => 0];

    $app = new QtApp();
    $app->create(['name' => 'MyApp']);
    $app->createWindow('My App', ['width' => 720, 'height' => 480, 'centered' => true]);

    // 每帧按 $state 重新描述界面
    $app->view(function () use (&$state): array {
        return WidgetTree::vbox([
            WidgetTree::label($state['greeting'], ['id' => 'greeting']),
            WidgetTree::hbox([
                WidgetTree::lineEdit($state['name'], ['id' => 'name_input']),
                WidgetTree::button('打招呼', ['id' => 'greet_btn']),
            ]),
            WidgetTree::label('点击次数：' . $state['clicks'], ['id' => 'clicks']),
        ]);
    });

    // 事件处理器只改状态
    $app->on('greet_btn', 'click', function () use ($app, &$state) {
        $state['name'] = $app->text('name_input');
        $state['greeting'] = 'Hello, ' . ($state['name'] === '' ? 'World' : $state['name']) . '!';
        $state['clicks']++;
    });

    $app->run();
}
```

### 4. 构建 / 运行 / 打包

```bash
qtphp build .       # 编译（Windows 编译后自动部署运行时 DLL 到 build/）
qtphp build . --nano # nano 模式：php-nano + PHPX 源码直接编进产物，不链任何 PHP 运行时（仅 Apple Silicon 实测）
qtphp run .         # 运行；其后的参数原样透传给应用，如 `qtphp run . --selftest`
qtphp package .     # 打包自包含产物：Windows → dist/，macOS → dist/<Name>.app，Linux → dist/<name>/
```

`--nano` 下同一份入口 yml 直接可用（tpc 会忽略 `php-builder:` 段；C++ 标准由入口 yml 固化在 nano
要求的 `c++17`），也不需要额外的 nano 专用配置。`examples/hello` 实测（清 `build/cache` 后的干净首建口径）：
产物 **4,370,328 B**（`strip -u -r` 后 3,639,472 B），同项目默认（embed）模式是 25,569,608 B；macOS 打包
产物 `dist/Hello.app` 为 **64.1 MiB**（embed 版 83.6 MiB，−23%）。`otool -L` 只剩 Qt 三件套 + 系统框架 +
brew libiconv + libc++/libSystem，`--selftest` / `--difftest` / `--shot` 出图与 embed 基线逐字节相同。
注意：同一 `build/` 先跑过 embed 再切 `--nano` 会多带 ~33 KB（4,403,640 B，字面量字符串表风味）——
清掉 `build/cache/` 下的 `objects`/`incremental`/`link` 后重建即回干净口径，功能无差异。详见
[CLI 参考 → nano](https://yangweijie.github.io/typephp-qt/zh/reference/cli.html#nano)。

`qtphp new` 生成的新项目同时带 `project.yml`（Windows 段）、`project.macos.yml`（mac 入口）、
`project.linux.yml`（Linux 入口，Debian 多架构路径按生成机推导）与 `Info.macos.plist`（打包 `.app` 用），
Windows 便捷脚本 `build.bat` / `run.bat` / `package.bat` 也一并给出。macOS 与 Linux 上首次 `qtphp build`
会让 tpc 从 php-src 现编私有 embed 运行时并缓存在 `~/.typephp`（缓存目录名带平台指纹，两平台互不复用），
之后所有构建复用。注意 tpc 每次构建都要先访问 php.net 的 releases 索引核对源码 SHA-256，
纯离线机器第一次构建会失败。

## 项目结构

```
typephp-qt/
├── bin/qtphp              # CLI（doctor/new/build/run/package/test/lint）
├── cpp-src/               # C++ 桥接（参与应用编译）
│   ├── qt_common.h        # 共享头：转换工具、Box 类、ChildSlot
│   ├── qt_bridge.cc       # 窗口/渲染 diff/装饰/对话框/包装符号
│   └── qt_widgets.cc      # 控件工厂/属性应用/取值
├── php-src/qt.stub.php    # 桥接契约（PHP 签名，函数体必须为空）
├── src/                   # PHP 框架层
│   ├── QtApp.php          # 应用框架（事件循环/错误兜底）
│   ├── WidgetTree.php     # 声明式控件树构建器
│   └── FakeBridge.php     # 纯 PHP 桥接替身（测试用）
├── tests/                 # 单元测试
└── examples/hello/        # 示例应用
    ├── project.yml        # Windows 编译入口（`build.bat` 直接用）
    ├── project.macos.yml  # macOS 入口：include 上面的公共段再整体替换 Qt 段
    └── project.linux.yml  # Linux 入口：同上，走 Debian 多架构的 -I/-lQt6Xxx（无 framework）
```

桥接 C++ 的编译验证就是 `qtphp build examples/hello` —— 两个 `.cc` 是示例 `sources` 的一部分，
Windows / macOS / Linux 各自走自己的入口 yml，没有单独的仓库根编译配置。
契约（stub ↔ C++ 实现）层面用 `qtphp lint`，它直接读 `php-src/qt.stub.php` 与 `cpp-src/*.cc`。

## 架构

```
        PHP（大脑，可单测）          C++ 桥（神经，极薄）           Qt（脸）
   ────────────────────────   ────────────────────────   ───────────────────
   TypePHP\Qt\QtApp         ─►  qt_window_render(tree) ─►  QMainWindow/QLayout
   TypePHP\Qt\WidgetTree        qt_window_poll_event()     QWidget 子树
   （声明式控件树 + 状态）       （id→widget 表 + diff）     （只显示/只上报）
```

- **桥接契约**：`php-src/qt.stub.php` 声明签名 ⇄ `cpp-src/*.cc` 实现 `php_` 前缀符号
- **渲染**：PHP 传控件树数组，C++ 按节点 `id` 做 diff —— 新增则建、消失则删、存在则只更新变化属性
- **无 id 节点**：C++ 用**结构路径**（`_p0.1.2`）当稳定 id，保证每帧映射到同一控件
- **事件**：所有信号只入队，PHP 主循环取走分派；处理器异常弹错误框但不中断循环

## 多窗口 / 托盘 / 定时器

一个 `QtApp` 实例 = 一个窗口。副窗口就再 `new QtApp()` 并 `createWindow()`：
`qt_app_create` 幂等，`QApplication` 全程只有一个。

**但 `run()` 只泵它自己那个窗口**，所以多窗口要自己按帧轮流泵（示例 `main()` 末尾就是这个循环）：

```php
while ($app->isOpen()) {
    $app->runFrames(1);                       // 主窗口：泵事件 + 分派 + 按状态重渲染
    $log = $state['log'];
    if ($log instanceof QtApp) {
        if ($log->isOpen()) {
            $log->runFrames(1);
        } else {
            $log->destroy();                  // 被关掉就摘掉，别攒僵尸窗口
            $state['log'] = null;
        }
    }
}
```

- **定时器**：`setTimer('clock', 1000)` 注册/改间隔（间隔 ≤ 0 即停），到点发
  `['type'=>'timer','id'=>'clock']`，所以用 `on('clock','timer', …)` 接。
- **托盘**：`setTray(['icon'=>…, 'tooltip'=>…, 'visible'=>…, 'menu'=>[…]])`。
  激活发的是**不带 id** 的 `['type'=>'tray']`，只能用 `onAny('tray', …)` 接，
  `value` 是手势（`left` / `right` / `double` / `middle`）。
  传了 `menu` 就由 Qt 托管右键菜单（右击弹菜单、不再发 `right`），菜单项走普通的 `menu` 事件：

  ```php
  $app->setTray([
      'icon' => 'assets/icon.png',
      'menu' => [
          ['type' => 'item', 'id' => 'tray.show', 'text' => '显示主窗口'],
          ['type' => 'item', 'id' => 'tray.quit', 'text' => '退出'],
      ],
  ]);
  $app->on('tray.quit', 'menu', function () use ($app) { $app->close(); });
  ```

  `icon` 可以不传 —— 桥接会兜底用窗口图标，窗口也没图标时用系统标准图标：
  **macOS/Linux 上无图标的托盘项根本不显示**，兜底不是美化，是可用性。
  相对路径的 `icon` 按**可执行文件所在目录**解析（macOS `.app` 内再兜一层 `Contents/Resources`），
  所以 `build/`、`dist/` 和打包好的 `.app` 里都成立
  （`qtphp build` 会自动把 `assets/` 拷进 `build/`）。
- **看不到托盘图标？** 先看 Windows 是否把新图标收进了溢出区（`^` 箭头后面）——
  查注册表 `HKCU:\Control Panel\NotifyIconSettings` 里该 exe 的 `IsPromoted`，
  空值就表示在溢出区。这是 Windows 的默认行为，不是框架问题。详见[文档](https://yangweijie.github.io/typephp-qt/zh/guide/dialogs.html)。
- 系统托盘不可用时（如无桌面会话的 CI）`show()` 是空操作，不会报错；`notify()` 在无托盘时
  回退成消息框，因此 `headless(true)` 下它直接返回，避免阻塞。

## 事件类型

| 事件 | 触发控件 | 说明 |
|------|----------|------|
| `click` | button, link | link 的 `value` 是 href |
| `press` / `release` | button | 按下 / 抬起 |
| `change` | lineedit, textedit, spin, doublespin, slider, combo | `value` 为新值 |
| `submit` | lineedit | 回车触发 |
| `commit` | lineedit | 失焦或回车，表示「这一格编辑完了」 |
| `toggle` | checkbox, radio, 可切换 button / group | `value` 为 `'0'`/`'1'` |
| `select` | list, table, tree | `value` 为行/项 id |
| `activate` | list, table, tree | 双击 |
| `itemClick` | list | 每次点击都发（重复点同一行也发） |
| `cell` | table | 单元格被编辑（需 `editable`），`payload.row`/`payload.col` |
| `expand` / `collapse` | tree | 展开 / 折叠，`payload.expanded` |
| `tab` | tabs, stack | `payload.index` |
| `close` | tabs | 关闭按钮（需 `closable`），`payload.index` |
| `menu` | 菜单项（含托盘菜单） | `payload.checked` |
| `timer` | 定时器 | 见 `setTimer()` |
| `tray` | 系统托盘 | `value` 为手势：`left`/`right`/`double`/`middle` |
| `loaded` / `navigating` | webview | URL（`loaded` 带 `payload.success`） |
| `title` | webview | 文档标题 |

`on($id, $type, …)` 与 `onAny($type, …)` 可以同时注册，**两个都会触发**（先特例、后通配）——
所以 `onAny` 适合放埋点、日志这类横切关注点。

## 控件目录

**容器**：`vbox` `hbox` `grid` `form` `group` `frame` `scroll` `tabs` `tab` `stack` `page` `split` `spacer` `separator`

**控件**：`label` `button` `lineedit` `textedit` `spin` `doublespin` `slider` `progress` `checkbox` `radio` `combo` `list` `table` `tree` `image` `link`

**内嵌网页**：`webview`（后端编译期按平台三选一：Windows = **WebView2**（完整 Chromium + JS）；macOS = 系统 **WKWebView**（JS + 远程 `https://`）；其余平台 = **QTextBrowser**（HTML 子集，无 JS，不支持远程 `url`）。运行时用 `QtApp::webViewBackend()` / `webViewSupportsJs()` 查询并降级）

## 常用属性

所有节点通用：`id` `visible` `enabled` `tooltip` `style` `size` `min_size` `max_size` `align` `grow`

容器额外：`title` `margin` `spacing` `row` `col` `row_span` `col_span`
（`margin` 支持整数四边同值，或 `[上, 右, 下, 左]` 四元组）

输入类：`text` `placeholder` `readonly` `password` `clear_button` `max_length` `editable`

数值类：`value` `min` `max` `step` `decimals` `prefix` `suffix`

列表类：`items` `current` `columns` `rows` `row_ids` `nodes` `headers` `multi` `select_mode` `closable`

`editable` 用在 `combo` 上开启手输，用在 `table` 上开启单元格就地编辑（并触发 `cell` 事件）。

`current` 是**声明式选中**，取值口径按控件类型不同：`table` 传行 id（不给 `row_ids` 时行 id 就是索引字符串）、
`tree` 传节点 id（不给 `id` 时退化成节点文本）、`list` 传行索引、`combo`/`tabs`/`stack` 传索引或文本。
不传 `current` 时，重渲染会按**行 id / 节点 id** 找回上一次的选中，所以插行、换数据都不会选中错位。

## 增量补丁

`QtApp::patch(array $ops)` 走 `qt_window_patch`，只碰点名的控件，用于日志流、进度刷新这类热路径。
每条操作是两种形态之一：

```php
$w->patch([
    ['op' => 'set',  'id' => 'status', 'props' => ['text' => '已完成']],
    ['op' => 'call', 'id' => 'log', 'method' => 'appendRows',
        'args' => [[['10:32', '启动'], ['10:33', '就绪']], ['l1', 'l2']]],
]);
```

`set` 的 `props` 与声明式属性同一套语义（结构字段 `rows`/`columns`/`row_ids`/`nodes`/`headers`
会触发整表/整树重建，并按行 id 保留选中）。

`call` 的 `args` 是**位置参数**，已实现六个方法：

| method | args | 作用域 |
|--------|------|--------|
| `appendRows` | `args[0]`=行列表（每行是单元格列表），`args[1]`=可选行 id 列表 | 仅 `table` |
| `clear` | 无 | 表格去行、树/列表/下拉去条目、文本类置空 |
| `setText` | `args[0]`=文本 | `label` `button` `lineedit` `textedit` `checkbox` `radio` |
| `setValue` | `args[0]`=值 | 进度条/滑块/数字框按数值，输入类按文本 |
| `select` | `args[0]`=id 或索引 | 与该控件的 `current` 属性完全同一套语义 |
| `focus` | 无 | 把键盘焦点交给该控件 |

**`call` 是命令式旁路**：它改控件，不改你的树。执行后被改属性的 diff 签名会作废，
下一次 `render()` 一律以树为准重新同步 —— 所以追加的行、清空的内容都只在这次渲染之前有效，
要长期存在就得写回树里。未知 `method`、未知 `id` 静默忽略，与未知属性一致。


## 命令

| 命令 | 说明 |
|------|------|
| `qtphp doctor` | 检查工具链（PHP / tpc / PHP 运行时库 / Qt / C++ 编译器 / PHPUnit）。Qt 位置按平台探：Windows 装到 `C:/D:` 盘、macOS 是 brew keg-only、Linux 是 Debian 多架构 `/usr`；C++ 依次试 `clang++`、`g++`；Linux 额外查 12 项构建/打包前置（`bison`/`re2c`/`autoconf`/`pkg-config`/`xz`/`patchelf` + gmp/mpfr/onig/libxml2/sqlite3/zlib 头），缺哪些就打出 apt 包名与可直接粘贴的 `apt install -y …`（只 WARN，不影响 rc） |
| `qtphp new <name>` | 创建新项目（`project.yml` + `project.macos.yml` + `project.linux.yml` + `Info.macos.plist` + Windows 三个 `.bat`） |
| `qtphp build <path> [--nano]` | 编译。入口 yml 按平台挑选（`project.macos.yml` / `project.linux.yml` → 回落 `project.yml`）；Windows 编译后自动部署运行时 DLL 与 `qwindows`/`qoffscreen`/`qminimal` 三个平台插件（offscreen 是无头验收的前提），并把 `assets/` 拷进 `build/`。`--nano`：不链 PHP 运行时，产物小一个量级（Windows 下相应跳过 DLL 部署；仅 Apple Silicon 实测） |
| `qtphp run <path> [应用参数…]` | 运行产物，其后的参数原样透传（`--selftest` / `--shot out.png`）；启动前做依赖自检（Windows 查 DLL，macOS 用 `otool -L` 查 bundle 外绝对路径，Linux 用 `ldd` 查 `not found`） |
| `qtphp package <path>` | 打包自包含产物并自检：Windows → `dist/` 目录，macOS → `dist/<Name>.app`（macOS 会额外补 `libqoffscreen.dylib`，让产物能无头跑 `--selftest`/`--difftest`），Linux → `dist/<name>/`（`lib/` 装 `ldd` 传递闭包、`plugins/` 装 `platforms`+`xcbglintegrations`、`qt.conf` 指插件目录，需要 `patchelf`） |
| `qtphp test` | 运行测试 |
| `qtphp lint` | 校验 stub ⇄ C++ 符号一致 |

## 测试

```bash
qtphp test
```

116 个测试全部走纯 PHP 桥接替身，**不需要 Qt 或编译器**。

视觉验收用无头截图（`qtphp run` 会把其后的参数原样透传给应用）：

```bash
qtphp run examples/hello --shot out.png    # Windows / macOS 通用
./build/hello --shot out.png               # 或直接跑产物（macOS 无 .exe）
```

## AOT 注意事项

- `main(int $argc, array $argv): void` —— 全局函数，命令行参数从这里拿（不要用 `global $argv`）
- 全局作用域只能有**声明**，可执行语句必须写在函数里（`require_once` 也是语句，不能放全局）
- 闭包参数需要**显式类型标注**（如 `function (array $event)`）
- 跨文件调用桥接函数时用 `\qt_xxx()` 前缀，避免命名空间解析问题
- **闭包实参个数必须精确匹配**：AOT 下 `function () {}` 被传入一个实参就会抛
  `ArgumentCountError`（普通 PHP 会静默忽略）。`QtApp` 已在注册时用反射探测参数个数，
  两种写法都能用，但自己写的回调也要留意这一点。

## 无头模式

模态对话框在 CI / 无终端环境会永久阻塞，用 `headless()` 绕开：

```php
$app->headless(true);   // message() 返回 default，文件对话框返回空
```

配合三个内置开关做自动化验收：

```bash
qtphp run <path> --shot out.png   # 渲染几帧后存 PNG 退出（视觉验收）
qtphp run <path> --selftest       # 逐个触发所有事件，验证每个 handler 可调用
qtphp run <path> --difftest       # 表格/树的差异更新边界（选中、行 id、列数、补丁）
```

三个开关都按**退出码**报告结果：全部通过是 `0`，任何一条 `FAIL` 或出图失败是 `1`，
`qtphp run` 原样透传 —— CI 判 `rc` 就行，不必 grep 输出文本。

示例应用在 Windows / macOS / Linux 上都能通过三个开关（Windows 实测 `--selftest` 25/25、
`--difftest` 20/20，全部 rc=0）。

`--selftest` 能在无头环境覆盖"闭包参数个数不匹配"这类只在 AOT 下暴露的问题。
`--difftest` 只能跑在真 Qt 上 —— diff 引擎与 `patch()` 的 `call` 都在 C++ 里，PHPUnit 摸不到它；
示例 `examples/hello/src/main.php` 的 `diff_test()` 是那 20 条断言的落点（含 8 条命令式 `call`，
其中一条专门验「`clear`/`appendRows` 之后重渲染必须以树为准」），照着写自己应用的边界断言即可。

**产物也能无头验收**：三个开关本身不挑平台；要在无 GUI 会话（CI）里跑就设 `QT_QPA_PLATFORM=offscreen`。

Windows 上 `qtphp build` 会把 `qwindows.dll` / `qoffscreen.dll` / `qminimal.dll` 一起部署到
`build/platforms/`，所以开发产物直接就能跑无头验收（早期版本只部署 `qwindows.dll`，
导致 offscreen 下三个开关全部 `0xC0000409` 崩溃）。

::: warning offscreen 下 `--shot` 检查不了中文
Qt 的 offscreen 平台插件走自己的字体枚举，可能取不到中文字体，于是 `--shot` 出的图里中文是方框。
`--selftest` / `--difftest` 不受影响（它们不渲染像素）。界面有中文又想看图时，`--shot` 就**别**加
`QT_QPA_PLATFORM=offscreen`（需要有桌面会话）。

macOS 另有一处：`macdeployqt` 只按目标平台拷 `libqcocoa.dylib`，裸产物设 offscreen 会被 Qt 直接
abort（rc=134）—— 所以 `qtphp package` 在 macdeployqt 之后会把 `libqoffscreen.dylib` 补拷进
`Contents/PlugIns/platforms/` 并把 Qt 引用改写成 `@executable_path`（约 +156 KB）。
:::

两条验收路：

```bash
QT_QPA_PLATFORM=offscreen ./build/hello --selftest    # 开发产物：靠开发机的 Qt 插件目录
env -i QT_QPA_PLATFORM=offscreen PATH=/usr/bin:/bin HOME="$HOME" \
    dist/Hello.app/Contents/MacOS/hello --selftest    # 打包产物：插件已在 bundle 内
cd dist/hello && env -i QT_QPA_PLATFORM=offscreen ./hello --selftest   # Linux 打包产物
```

Linux 侧不用像 mac 那样单独补一个 offscreen —— `package` 直接把系统 `platforms/` 整目录
（含 `libqoffscreen.so`）搬进 `dist/<name>/plugins/`、依赖闭包搬进 `lib/`，靠 `qt.conf` 与
`$ORIGIN/lib` 的 DT_RPATH 定位；自检对**可执行文件和每个插件**各跑一次 `ldd`，每一行都必须落在产物内
（glibc 家族除外）—— 插件的 xcb 那批依赖不在可执行文件的闭包里，只对插件自己跑 `ldd` 才看得见。
有漏的会直接报「仍指向产物之外」并 `rc=1`，而不是假装打包成功。

## License

MIT
