# Findings — typephp-qt

> 调研结论、外部事实、踩坑记录。外部抓取内容只写本文件，不写 task_plan.md。

## F1. 工具链事实（本机实测）

| 项 | 值 | 来源 |
|---|---|---|
| `tpc.exe` 版本 | `TypePHP Compiler (AOT) v0.9.4` | `D:\git\php\tpc_v0.9.4_windows_x64\tpc.exe --version` |
| Qt | `D:\tools\Qt\6.9.3\msvc2022_64` | 环境变量 `QT_DIR` |
| MSVC | `...\2022\BuildTools\VC\Tools\MSVC\14.44.35207\bin\Hostx64\x64\cl.exe` | `where cl` |
| `windeployqt` | 未在 PATH，需 `<QT_ROOT>\bin\windeployqt.exe` | `where windeployqt` 无输出 |
| `qmake` | 未在 PATH | `where qmake` 无输出 |

结论：CLI 的 `doctor` 不应依赖 PATH，必须能按 `QT_DIR` / 配置查找。

## F2. TypePHP 编译模型（来自 aot-docs + 实测）

- `bin` 模式：入口文件必须提供**全局** `main()`，签名 `main(int $argc, array $argv): void`。
- `lib` 模式（`-m lib`）：生成 `<name>.stub.php`（顶部带 `@import-library`）+ `.dll/.lib`(Win) 或 `.so`(Linux)。
  **但只从有函数体的 PHP 实现导出签名** —— 纯 C++ 桥接用不了，见 F7。
- 库内部不导出的符号用 `#[\NoExport]`。
- `.stub.php` 中函数体必须为空；PHP `foo_bar` ⇄ C++ `php_foo_bar`。
- `project.yml` 支持 `sources:` 列**目录**或**具体文件**（`.cc` 也可列进去，这是源码内联的基础）、
  `build-dir`、`include-paths`、`link-paths`、`link-libs`、`cxx-flags`。
- 跨文件声明靠 `sources` 列表传递，不靠 `require`（见 8.3）。
- `link-libs` 里写库名必须带 `.lib` 后缀，否则被当成 `.obj`。

## F3. Qt 桥接硬规则（来自 skill，逐条都是真踩过的坑）

1. stub 函数体必须空。
2. C++ 符号必须带 `php_` 前缀。
3. `QApplication` 懒创建、且只创建一次；先于任何 widget。
4. **事件循环必须自己驱动**：`QEventLoop` + `QTimer::singleShot(16,...)`，不得 `app.exec()`。
5. 所有信号处理只入队，不改状态。
6. Windows 需要运行时 ini（`PHPRC` + 清空 `PHP_INI_SCAN_DIR`）来加载 PHP 扩展。
7. GUI 子系统加 `--no-console`（先带控制台调通再加）。
8. MSVC 编 Qt 必须 `/Zc:__cplusplus` + `/permissive-`。
9. 必须 `windeployqt`，否则 "could not find or load the Qt platform plugin windows"。
10. 边界一律 UTF-8（`fromUtf8` / `toUtf8`），否则中文乱码。
11. Qt 不保证传递包含：用到谁就 include 谁（`verticalHeader()` → `<QHeaderView>`，`SelectRows` → `<QAbstractItemView>`）。
12. 程序化重建视图时必须 `QSignalBlocker`，否则自己的 selection 信号回灌成事件死循环。

## F4. Windows 打包事实（来自 build-and-deploy.md）

- 普通 Widgets 应用的运行时 DLL 闭包（`dumpbin /dependents` 得出）：
  `phpx.dll php8ts.dll libmpdec++-4.0.1.dll libmpdec-4.0.1.dll gmp-10.dll mpfr-6.dll`
- `windeployqt` 只覆盖 Qt，**不覆盖** TypePHP/PHPX 运行时与自家 `assets/`。
- 自检办法：把 `PATH` 缩减到 `C:\Windows\System32;C:\Windows` 再跑，检查 ① 退出码 ② stdout/stderr 无 `PHP Startup`/`Fatal error`（PHP 把启动警告写 **stdout**）③ 确实产出截图 PNG。
- cmd 批处理坑：必须 CRLF；块内用裸 `exit /b`（`^&` 会被当字面参数）；结尾裸 `endlocal` 会吞掉退出码；`set X=v && ...` 会带上 `&&` 前的空格 —— 一律用 `set "X=v"`。

## F5. 参考实现（本机既有项目）

- `D:\git\php\typephp-gui` —— **不同路线**（WebView2 + tinyjsapp 帧协议），但可借鉴：
  - composer 是 `type: project` 模板，**不是 Packagist 库**；AOT 路径靠 `bootstrap.php` 的 require 链，PSR-4 只服务 IDE/系统 PHP。
  - 提供 `bin/run-backend.php`：**用系统 PHP 直接跑同一份逻辑，不编译也能调试** —— 本框架的 FakeBridge 思路同源，但我们要做到"桥接函数也可替换"，更彻底。
  - 验证哲学值得抄：正向断言 + **负控**（故意让预期失败的用例失败），否则"全绿"可能只是因为探针没生效。
- `D:\git\php\aot-docs` —— 官方文档源，`composer.md` / `library.md` / `project-yml.md` 是权威依据。

## F6. 待验证假设（需实测）

| # | 假设 | 验证方式 | 结果 |
|---|---|---|---|
| H1 | `tpc` 能编译 `cpp-src/` 下**多个** `.cc` 并正确链接 | 放 2 个 .cc 编译 | ✅ 通过（qt_bridge.cc + qt_widgets.cc） |
| H2 | 同一份 C++ 桥能被 `-m lib` 编成库并被应用链接 | 编 lib + 消费项目 | ⚠️ **不可行**，见 F7 |
| H3 | `php::Array` 支持嵌套数组/任意深度 | 编译期测 | ✅ 通过 |
| H4 | 声明式 diff 渲染能保留控件状态 | 运行验证 | ✅ 通过（稳定 id 是前提，见 F8） |
| H5 | 项目目录名含 `-` 不影响 `name:`/exe | 编译验证 | ✅ 通过 |

## F7. tpc `-m lib` 无法导出纯 C++ 桥接（实测）

**现象**：库项目里 `src/vec.stub.php` 声明了 `vec_new`/`vec_get`，C++ 提供 `php_vec_new`/`php_vec_get`。
`tpc project.yml -m lib` 编译链接都成功，但生成的 `vec.stub.php` **恒为空**（只有 `/** @import-library */`）。

**原因**：tpc 只从**有函数体的 PHP 实现**导出签名。桥接的 `.stub.php` 按契约必须函数体为空，
因此不会被导出 —— 这是 tpc 的固有行为，不是配置问题。

**结论**：`library.md` 描述的库路线适用于「PHP 实现 + 少量 C++ 原语」，不适用于「纯 C++ 桥接」。
本项目采用**源码内联**：桥接 `.cc` 直接列进应用的 `sources`，一次编译同时产出桥接实现与应用逻辑。

顺带确认：lib 模式生成的 `build/include/php_<name>_<file>_decl.h` 带
`TYPEPHP_<NAME>_API`（dllimport/export 宏），消费方加 `/DTYPEPHP_<NAME>_EXPORTS` + 该 include 路径
确实能链接 —— 但这要求消费方知道库的内部头文件名，太脆弱，故不采用。

## F8. AOT 致命坑（都实际导致过崩溃 / 编译失败 / 功能失效）

### 8.1 `Array*` 隐式转 `bool` 导致运行时抛错

`php::Array` 有到 `bool` 的隐式转换，所以 `qtPropInt(&spec, "size", 0)` 传 `Array*` 会变成
`Array(true)` —— 一个"值是 bool 的数组"，取值时报
`parameter 1 must be \`array\`, got \`bool\``。

**规则**：辅助函数一律按 `const Variant&` / `const Array&` 收参，**不要写 `&spec`**。

### 8.2 自动 id 每帧递增 → 控件反复重建 → 悬垂指针崩溃

最初 `internAutoId()` 用递增计数器。无 id 的节点（`spacer`/`separator`/容器）每帧拿到**新 id**，
于是每帧都创建新控件、`removeStale()` 删掉旧的。第二次渲染时父布局仍持有悬垂 item → 访问违例
（退出码 `0xC0000005`）。

**规则**：无 id 节点的 id 必须**按结构路径稳定生成**（`_p0.1.2`），且 `removeStale()` 要先
`layout->removeWidget()` / `tabs->removeTab()` / `setParent(nullptr)` 再 `deleteLater()`。

**表现特征**：`run(1)` 正常、`run(2)` 崩溃 —— 单帧看不出问题，第二帧才暴露。

### 8.3 全局 `require_once` 是 stray code

tpc 要求全局作用域**只有声明**。`require_once` 是语句，放全局会报
`All execution code must be within a function, found stray code`。
放在函数体内也不行（同样报错）。

**规则**：跨文件可见性靠 `project.yml` 的 `sources` 列表建立，不靠 `require`。

### 8.4 闭包实参个数必须精确匹配（点按钮就崩的元凶）

**现象**：普通 PHP 下测试全绿，AOT 编译后点任意按钮弹
`stdClass::{closure}() expects exactly 0 arguments, 1 given`。

**原因**：ZendPHP 对用户函数的多余实参是**静默忽略**的，AOT 编译后的闭包则做**精确校验**，
多传一个就抛 `ArgumentCountError`。`QtApp` 原先统一用 `$handler($event)` 调用，
于是所有 `function () {...}` 形式的 0 参处理器全部报错。

**解决**：注册时用反射探测一次必需参数个数，分发时按实际个数调用：

```php
if (is_array($handler)) {
    $methodRef = new \ReflectionMethod($handler[0], $handler[1]);
    return $methodRef->getNumberOfRequiredParameters();
}
$functionRef = new \ReflectionFunction($handler);
return $functionRef->getNumberOfRequiredParameters();
```

实测 AOT **支持反射**，且对闭包 / 方法数组 / 函数名字符串三种 callable 都能正确返回参数个数。

**不用"传参失败再回退"的原因**：异常做控制流会重复执行处理器前半段；
且 handler 内部自己抛 `ArgumentCountError` 时会被误判。

**注意**：`$ref = new ReflectionMethod(...)` 之后不能再用同一变量接 `ReflectionFunction`，
AOT 报 `Cannot re-assign typed object`，必须用两个变量。

### 8.5 模态对话框在无头环境永久阻塞

`QMessageBox::exec()` / `QFileDialog::getOpenFileName()` 会阻塞直到用户交互。
CI 或 `--selftest` 这类无终端场景下会**永久挂住**（表现为进程不退出、无输出）。

**解决**：`QtApp::headless(true)` —— 消息框直接返回 `$spec['default']`，
文件/目录对话框返回空，不触碰 Qt 模态 API。

### 8.6 其他 AOT 约束（逐条都踩过）

- `main()` 必须是**全局**函数，签名 `main(int $argc, array $argv): void`；命令行参数从这里拿，
  `global $argv` 在 AOT 下会崩（`0xC0000409`）。
- 闭包参数需**显式类型标注**：`function ($event)` → `function (array $event)`，否则
  `The variable \`$event\` is undefined`。
- 命名空间内的文件调用桥接函数要写 `\qt_xxx()`，否则生成的 C++ 是 `php_qt_xxx(...)` 且无
  全局限定，编译期报"找不到标识符"。
- 静态方法（`WidgetTree::label`）在 AOT 下被生成成实例方法（多传 `this_`），功能正常但
  调用方需持有对象实例。
- `-m lib` 模式编译单元会带 `/FI all_decl.h`，`bin` 模式不带，跨文件声明靠 `sources` 传递。
- 同一变量不能先绑定 `ReflectionMethod` 再绑定 `ReflectionFunction`，报
  `Cannot re-assign typed object`；必须分用两个变量。
- AOT **支持反射**：`ReflectionFunction` / `ReflectionMethod` 的
  `getNumberOfRequiredParameters()` 对闭包、方法数组、函数名字符串都返回正确值。

## F9. 打包与部署事实（实测修正）

- exe 的真实依赖（源码内联后）：`phpx.dll` `php8ts.dll` `libmpdec++-4.0.1.dll`
  `Qt6Core.dll` `Qt6Gui.dll` `Qt6Widgets.dll` `MSVCP140.dll` `VCRUNTIME140.dll`
  —— **不再需要 `qtphp-bridge.dll`**（源码内联方案下桥接已编进 exe）。
- 开发期直接跑 `build/` 下的 exe 也必须部署 `platforms/qwindows.dll`，否则 Qt 报
  "could not find or load the Qt platform plugin windows"。
- 精简 PATH 自检：`PATH=C:\Windows\System32;C:\Windows` + `PHPRC` + 清空
  `PHP_INI_SCAN_DIR`，退出码 0 且 PNG 生成即视为自包含通过。
- `windeployqt --no-translations` 可显著减小 dist 体积。
- tpc 需要 MSVC 环境；只查 `cl.exe` 不够（它常在系统 PATH 上但 `INCLUDE` 未设），
  必须查 `INCLUDE` 环境变量，否则报 `cstring: No such file or directory`。

## F10. 设计取舍记录

- **为什么用声明式 id-diff 而不是命令式句柄**：句柄模式下 PHP 要自己维护 widget 生命周期、增量同步，写法冗长且易漏；声明式只要"描述当前该长什么样"，diff 在 C++ 做，最省应用代码 —— 这正是"方便封装"的核心。
- **控件树里的容器**：`vbox / hbox / grid / form / group / frame / scroll / tabs / stack / split`。布局属性用 `grow`、`row`/`col`/`row_span`/`col_span`、`align`、`size`。
- **事件统一扁平约定**：`{type, id, value, payload}`，让应用侧 `handle()` 是一个 switch。
- **FakeBridge 而非 mock 框架**：直接提供一份纯 PHP 的 `qt_*` 全局函数实现，把事件脚本化注入，领域层测试零依赖。
- **源码内联而非预编译库**：见 F7 —— 预编译库路线在 tpc 上走不通，且源码内联永远与 Qt/PHPX 版本同步。
- **事件 arity 注册时探测**：见 8.4 —— 用反射而非异常回退，避免处理器前半段重复执行。
- **两个无头开关**：`--shot` 做视觉验收、`--selftest` 做行为验收。FakeBridge 跑在 ZendPHP 上无法复现 AOT 的严格性，必须用真实二进制兜底。

## F11. macOS 工具链实测（Session 3，2026-10-02）

### 11.1 tpc 在 macOS 上确实能跑（此前认知需修正）

- `php vendor/bin/tpc.php --version` → `TypePHP Compiler (AOT) v0.9.4`；编译时打印
  `Initialized platform/backend: macOS + Clang (clang++)`（Apple clang 21.0.0，arm64）。
- composer 包只发 `bin/tpc.php`（纯 PHP 驱动，`src/compiler.php`），`bin/tpc` 是各平台预编译二进制，
  安装时被跳过：`Skipped installation of bin bin/tpc for package swoole/typephp: file not found in package`。
  ⇒ 这就是 `findTpc()` 一直报「tpc 未找到」的原因，不是没装。
- `typephp-tinygui/tools/build-macos.sh` 的 mac 路线是 **clang++ 原生壳 + 系统 PHP shebang 后端**，
  脚本注释自己写明「tpc AOT is a Windows-only toolchain」；Linux 侧也只有 shebang + `--nano` 实验
  （卡在三道上游墙）。⇒ 本项目从没在 mac 上做过 tpc AOT，`~/.typephp` 运行时缓存不存在是预期的。

### 11.2 macOS AOT 的第一道闸：宿主 PHP 缺 embed SAPI

`prepareRuntimeDependencies()` 报：
```
The host PHP embed library is missing: Neither libphp.dylib nor libphp.a found.
Run tpc in an interactive terminal to enable php-builder, or pass
--php-builder='extensions: []; zts: off'
```
- Homebrew php 8.5.7 的 Cellar 里确实没有任何 `libphp*`（只建 cli SAPI）。
- 出路是 tpc 自带的 **php-builder**：从官方 php-src 现编一个私有运行时，缓存目录
  `OfficialPhpSource::defaultCacheDirectory()` = `$HOME/.typephp`（`SapiPhpBuilder` 只支持 Linux/Darwin）。
- 非交互终端不会弹 confirm，必须显式传 `--php-builder=...`（`SourcePipelineTrait.php:610`）。
- 本机 `bison/autoconf/re2c/cmake/ninja/openssl@3` 齐，`pkg-config` 缺 —— 尚未验证 php-builder 是否强制要它。

### 11.3 project.yml 的平台条件能力（读源码 + 实测确认）

| 键 | 支持 `if:` 条件 | 依据 |
|---|---|---|
| `sources`、`objects` | ✅ `- path: X` + `if: PHP_OS_FAMILY == "Darwin"`（也接受 `when`） | `ProjectYamlLoader::parsePathEntry()`，合法 OS：Windows/BSD/Darwin/Solaris/Linux/Unknown |
| `include-paths`、`link-paths`、`link-libs`、`cxx-flags`、`ld-flags` | ❌ 逐项 `(string)` 直取，条件 map 会坏 | `Translator.php:4447/4527/4552/4561` |
| `include:` | ❌ 只能是字符串/字符串列表 | `ProjectYamlLoader.php:48-60` |

合并语义（决定文件怎么切分）：
- `include` 的目标**先**合并，本体配置**覆盖**被包含文件；
- **list 值是整体替换、不是追加**（`mergeConfig` 只对非 list 数组做深合并）
  ⇒ 平台入口文件必须把 `include-paths` 全量写全，不能只写增量。
- 所有路径（`sources`/`include-paths`/`link-paths`/`output`/`build-dir`）相对 **yml 所在目录**解析
  （`Translator.php:4181 resolvePath($path, $projectDir)`）
  ⇒ `../../src/QtApp.php` 可以替掉 `D:/git/php/typephp-qt/src/QtApp.php`，Windows 同样成立。这一条单独就消掉了大部分硬编码。

### 11.4 Homebrew qtbase 是 framework 布局，include 要 `-I` 与 `-F` 双给

- `brew install qt` = 38 个 formula（含 qtwebengine）；本项目只要 QtCore/QtGui/QtWidgets，
  装 **`qtbase`**（6.11.2，14 个依赖）即可。
- brew 的 qtbase **没有** `include/QtCore`（`include/` 下仅 `QtDeviceDiscoverySupport`、`QtFbSupport`），
  模块头在 `lib/QtWidgets.framework/Headers/`。
- 桥接源码写裸 `#include <QApplication>`，而 `Headers/QApplication` 的内容是
  `#include <QtWidgets/qapplication.h>` ⇒ 只给 `-I .../framework/Headers` 时限定名找不到，
  只给 `-F lib` 时裸名找不到。**两者同时给才成立**（实测）：
  ```
  -F$Q/lib -I$Q/lib/QtCore.framework/Headers -I$Q/lib/QtGui.framework/Headers -I$Q/lib/QtWidgets.framework/Headers
  ```
- 链接走 framework：`-framework QtWidgets -framework QtGui -framework QtCore`；
  `/tmp/qtlink.cpp`（QApplication + QLabel）编译链接 rc=0，`otool -L` 显示三个 framework 均按
  `Versions/A/<name>`（current 6.11.2）解析。tpc 侧 `link-libs` 会被并入库列表
  （`NativeCommandOptionsTrait.php:164`），Windows 用 `Qt6Core.lib` 已实测有效；mac 的 framework
  形式改走 `ld-flags`（`-F` + `-framework`）。**tpc 端到端产出 mac 二进制尚未验证**（卡在 11.2）。
- 噪声：anaconda 的 Qt **5.15.2** 在 PATH 上遮蔽 brew 的 `qmake/qtpaths`，且代际与项目锁定的 6.x 不同；
  构建一律用 `/opt/homebrew/opt/qtbase` 绝对路径，不依赖 PATH。
- 好消息：`cpp-src/` 全文没有 `Q_OBJECT`/`signals:`/`slots:`（`QtWindowBox` 继承 `php::Box` 而非 QObject），
  ⇒ macOS 侧**不需要 moc**，tpc 只需正常编译 3 个翻译单元。


### 11.5 macOS AOT 全链路打通后的四条硬事实（2026-10-02 实测）

1. **phpx 的 `sapi-static/CMakeLists.txt` 缺 Homebrew 前缀探测**（根 `CMakeLists.txt:287` 有，静态那份没有），
   而 tpc 调 cmake 的参数写死（`-S sapi-static -B … -DPHPX_ROOT -DPHPX_PHP_PREFIX`），注入不了 flag。
   ⇒ 唯一可行的补法是给 clang 环境变量：`CPATH=<brew>/include`、`LIBRARY_PATH=<brew>/lib`（clang 每次都读，
   不需要重跑 cmake configure）。已固化进 `bin/qtphp` 的 `cmdBuild()`（非 Windows 分支自动注入）。
   gmp/mpfr 会软链进 `/opt/homebrew/include`，**libiconv 是 keg-only 不会** —— 所以它要单独给 `-L`。
2. **`-F` 必须编译期与链接期双给**：framework 的转发头（`QtWidgets/QAbstractItemView` 等）内部写的是
   限定名 `#include <QtWidgets/qabstractitemview.h>`，只给 `-I *.framework/Headers` 时这些转发头自己编不过。
   tpc 的 `cxx-flags` 会原样出现在 clang++ 命令行上，所以 `-F$QT/lib` 要同时进 `cxx-flags` 和 `ld-flags`。
3. **tpc 会自动补齐私有运行时的链接项**：实测链接行自带 `-L…/install/lib -L/opt/homebrew/lib -lphpx -lphp
   -lgmp -lgmpxx -lmpfr -lc++ -liconv…`（`-liconv` 也在内，但 `-L` 指向 `/opt/homebrew/lib` 找不到 keg-only 的
   `libiconv.dylib` ⇒ 仍需在 `ld-flags` 里显式 `-L/opt/homebrew/opt/libiconv/lib -liconv`）。
   另外 tpc 只给 `install/lib` 写 rpath，Qt framework 与 libiconv 的 rpath 要自己加，否则运行时 `dyld` 找不到。
4. **`QSystemTrayIcon::showMessage()` 在没有系统托盘时会回退成模态 `QMessageBox`**（offscreen 平台实测：
   栈为 `showNewMessageBox → QDialog::exec → QEventLoop`，永久阻塞）。
   ⇒ `QtApp::notify()` 必须和 `message()/pickFile()/saveFile()/pickDirectory()` 一样受 `headless` 守卫，
   这不是 mac 专有，Linux CI / 无托盘环境同样会挂。

**验收结论**：`qtphp build examples/hello` 在 Apple Silicon 上产出 `build/hello`（Mach-O arm64 / 24 MB），
`QT_QPA_PLATFORM=offscreen` 下 `--selftest` 10/10 干净退出、`--shot` 出 760×560 正常界面；
删掉 `phpx-build` 缓存后**不带任何手工环境变量**裸跑 `qtphp build` 仍成功 ⇒ 路线可复现。

### 11.6 macOS 依赖自检不能照抄「DLL 名单」思路（11.1 实测）

- 正确做法是让动态链接器自己报清单：`otool -L <exe>`，逐条核对**第三方绝对路径**是否存在
  （brew 前缀、keg-only 的 `libiconv`、`~/.typephp` 私有运行时都在这里）。
- **必须跳过 `/usr/lib/` 与 `/System/Library/`**：macOS 11 起这些 dylib 只存在于 dyld shared cache，
  磁盘上没有文件却加载正常。实测不跳过时 `libc++.1 / libresolv.9 / libxml2.2 / libsqlite3 / libz.1 / libSystem.B`
  六条全部误报为缺失。
- `@rpath/...` 与 `@executable_path/...` 形式静态检查无意义，交给 dyld 解析。
- 负向验证手段：`cp` 一份产物再 `install_name_tool -change <真路径> /nonexistent-dir/... <副本>`，
  即可确认检查器真能报出缺失，而不必去动 brew 里的文件。
- 想单测 `bin/qtphp` 里的函数：它在末尾全局作用域 `exit(main($argv))`，且用 `dirname(__DIR__)` 定位根目录，
  所以临时副本必须放在 `bin/` 下并去掉最后一行（`sed '$d' bin/qtphp > bin/.tmp.php`），否则 `require` 直接炸。

### 11.7 上游样板 qt-taskboard 给出的 mac 约定 + macdeployqt 实测边界（11.2）

参考实现：`typephp-compiler/examples/qt-taskboard/{project.macos.yml,Info.macos.plist,package-macos-app.sh,README.md}`。
照它改的三件事：

1. **平台文件后缀是 `macos`/`windows`/`linux`，不是 `PHP_OS_FAMILY` 原值** —— `Darwin` ≠ `macos`，
   直接用 `strtolower(PHP_OS_FAMILY)` 会永远命中不到上游风格的文件名。
2. **Qt flag 的放法**：`-F`、三个 `*.framework/Headers` 的 `-I`、`-DQT_*_LIB` 全进 `cxx-flags`；
   framework 链接用 `-Wl,-framework,X`（比裸 `-framework X` 更稳，某些 tpc 版本会把它当单个 arg 传）。
3. **打包流程**：checked-in `Info.macos.plist` → `macdeployqt <bundle> -always-overwrite -no-codesign`
   → 手工 `install_name_tool` 修补 → `codesign --force --deep --sign -`（ad-hoc，本地自测够用）
   → `plutil -lint` + `codesign --verify --deep --strict`。上游要搬 `libphp.dylib`/`libphpx.dylib`，
   我们 `--enable-embed=static` 全静态，**bundle 不需要搬 PHP/PHPX**。

`macdeployqt` 实测比预想的勤快：不只 Qt framework，连 `libgmp/libiconv/glib/freetype/dbus/double-conversion/b2`
这些**非 Qt 的 brew 依赖**和 `platforms/libqcocoa.dylib`、`imageformats/`、`styles/` 插件都一起搬进
`Contents/Frameworks` 与 `Contents/PlugIns` 并改好引用 —— 所以 `vendorBundleDeps()` 的补搬逻辑实测没触发（留着兜底）。
最终 `Hello.app` 99.4 MB，`otool -L` 主二进制除 `/usr/lib`、`/System/Library` 外**零绝对路径依赖**，
`env -i`（无 PATH / 无 QT_QPA_PLATFORM / 无 DYLD_*）下 `--selftest` 10/10 通过 ⇒ 真自包含，且走的是默认 cocoa 平台插件。

### 11.8 「tpc 在哪」≠「运行时库在哪」（11.3）

composer 版 tpc 是**纯 PHP 驱动**：`find vendor/swoole/typephp -iname '*.dll'` 实测为空，
`vendor/swoole/typephp/bin/` 只有 `tpc.php`、`extractor.php` 等脚本。所以旧 `findTpcDir()`
返回 `dirname(vendor/bin/tpc.php)` = `vendor/bin`，`deployRuntimeDlls()` 在它下面找 6 个 DLL
必然全数落空（`findTpcDir()` 为 null 时更糟：拼出 `/phpx.dll` 这种路径）。

真正的 PHP/PHPX 运行时只有两个来源，按**标记文件**筛才可靠：

| 来源 | 标记文件 | 位置 |
|---|---|---|
| 解发型 tpc 发行包 | `phpx.dll` / `php8ts.dll` | native `tpc.exe` 同目录 |
| `php-builder` 自建私有运行时 | `libphp.a`（实测 62 MB，静态） | `$HOME/.typephp/php-builder/php-<版本>-<指纹>/install/lib` |

- 私有运行时的根目录由 `vendor/swoole/typephp/src/Build/OfficialPhpSource.php:24` 决定：
  `getenv('HOME') . '/.typephp'`，`SapiPhpBuilder.php:89` 再拼 `/php-builder`。
  Windows 上 `HOME` 常缺，所以 `findRuntimeLibDir()` 回退读 `USERPROFILE`。
- 可能存在**多份**指纹目录（换 PHP 版本即换），取 `filemtime` 最新的一份。
- 新增 `TPC_RUNTIME_DIR` 环境变量作为人工覆盖口（与 `QT_DIR` 同一套思路）。
- mac 路线其实**不需要**部署运行时库：`libphp.a`/`libphpx.a` 全静态链进产物，动态依赖只有 brew 的
  Qt/iconv/gmp/mpfr，由 11.2 的 `macdeployqt` 负责。所以 `deployRuntimeDlls()` 仍只在 Windows 分支调用，
  这个解析器在 mac 上的消费者是 `doctor`（报告私有运行时位置，避免用户对着
  `Neither libphp.dylib nor libphp.a found` 发呆）。
- 顺带发现并删掉一处死代码：`cmdNew()` 的 `$tpcDir = findTpcDir() ?? 'D:/git/php/tpc_v0.9.4_windows_x64';`
  生成的 `project.yml` 模板里**从未插值** `{$tpcDir}`（只用 `$pkgDir` 与 `$qtDir`）。
- PHP 注释陷阱：docblock 里写 glob `php-*/install/lib`，其中的 `*/` 会提前闭合注释，
  下一行直接 `syntax error, unexpected token "/"`。注释里提到该路径要写成 `php-<版本>-<指纹>/…`。

### 11.9 文档里的幻影文件：仓库根 `project.yml`（11.4）

`task_plan.md` 的 11.4 说「仓库根 `project.yml` 仍硬编码 D:/ 路径」，实测该文件**不存在**：

- `find . -name '*.yml' -not -path './vendor/*'` → 只有 `examples/hello/project.yml` 与
  `examples/hello/project.macos.yml`。
- `git log --all --name-status -- project.yml` → 零记录（从未入库）。
- `.gitignore:13` 反而把 `/project.yml` 归在「Generated per-project files」下 —— 即使本地临时建过，
  也永远不会被提交，因此任何依赖它的步骤都不可复现。

出处是 README 的项目结构图和 Session 1 的「文件清单」，两处都已按磁盘实况改正。

**也不值得补一份**：桥接 stub 的函数体按契约必须为空，`tpc -m lib` 对空体 stub 生成的库恒为空（F7 实测证伪），
所以「单独验桥接能否编译」在 tpc 里没有独立形态 —— 两个 `.cc` 只能作为应用 `sources` 的一部分参与编译，
正确验证路径就是 `qtphp build examples/hello`（Windows/macOS 都已实测）；
符号层面的契约由 `qtphp lint` 直接读 `php-src/qt.stub.php` + `cpp-src/*.cc`，不经过任何 yml。

**教训**：计划项引用文件路径时，先 `find`/`git log -- <path>` 证实存在，再谈改它。

### 11.10 参数透传与退出码：不依赖真实产物就能验（11.5）

`cmdRun()` 的透传/退出码逻辑不值得为它编一个失败的应用场景 —— 用**假产物**最快：

```bash
mkdir -p /tmp/fakeproj/build
printf '#!/bin/sh\necho "args: $*"\nexit 7\n' > /tmp/fakeproj/build/fakeproj
chmod +x /tmp/fakeproj/build/fakeproj
php bin/qtphp run /tmp/fakeproj --selftest '--shot /tmp/a b.png'; echo "rc=$?"
# → args: --selftest --shot /tmp/a b.png   rc=7
```

一次同时验到三件事：参数原样到达、含空格参数未被拆散（`escapeshellarg` 生效）、`passthru()` 的
退出码逐位传出。注意取退出码别经过管道 —— zsh 是 `$pipestatus`（1 起始），bash 才是 `$PIPESTATUS`。

相关既有事实：`qtphp new` 生成的 `run.bat` 早就写了 `build\{$name}.exe %*`，
所以「透传」在 Windows 的 `.bat` 路线一直可用，缺的只是 `qtphp run` 这一层。

### 11.11 `basename('.')` 把 `.` 当名字：`.` 形式的项目路径全线失效（11.5 顺带）

`qtphp <run|package> .` 是 README 教的、也是 `qtphp new` 生成的 `package.bat` 实际执行的写法
（模板里就是 `php ".../bin/qtphp" package .`），但它一直坏：

- `basename('.')` 返回 `.`（PHP 不把它当「当前目录的名字」），于是产物名 = `.` →
  exe 路径拼成 `./build/.`。
- 更阴的是 `file_exists('./build/.')` **为真** —— 那确实存在，只是是个目录。所以
  「未找到可执行文件」这道前置校验完全没拦住，错误漂到后面：mac 上 `package .` 报
  「未找到 bundle 描述文件」，`run .` 直接 `sh: ./build/.: is a directory`（rc=126）。
- `cmdBuild .` 不受影响：它只拼 `./project.yml`，随后 `realpath()` 交给 tpc，路径本来就等价。

⇒ 用 `realpath()` 规范化目录（不存在则原样返回，让下游报「未找到」），别信 `basename()` 能吃 `.`。
同类陷阱：`basename('foo/')` 正常返回 `foo`，所以只有 `.`/`..` 这种相对目录名会翻车 ——
凡是要从「用户传进来的目录路径」推导**产物文件名**的地方都得先规范化。

**Windows 侧同样成立**（按模板代码推断，本机无法实机验证）：`package.bat` 用的就是 `.`。
