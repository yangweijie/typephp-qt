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

## F12. macOS 启动期噪声：`error messaging the mach port for IMKCFRunLoopWakeUpReliable`

用户直跑 `examples/hello/build/hello` 时终端打出这一行，怀疑是缺陷。实测结论：**无害，且不是我们报的**。

| 证据 | 结果 |
|---|---|
| 全仓 grep `IMK`（`bin` `src` `cpp-src` `php-src` `tests` `examples`） | 唯一命中是 `examples/hello/build/cache/.../stable-ids.json` 里的 base64 键（生成物），**源码零命中** |
| 复现频率（cocoa 真平台，非 offscreen） | 10:22:35 用户那次、10:23:49 我这次各 1 条；随后 5 次 `--shot`、1 次拷进临时 `.app/Contents/MacOS/` 启动、1 次真窗口存活 5 s 的运行 **全部 0 条** ⇒ 间歇性，约 2/8 |
| 功能是否受影响 | 每条都 `rc=0`；`--shot` 每次出图（19 KB PNG）；`QT_QPA_PLATFORM=offscreen` 下从不出现 ⇒ cocoa 专有 |
| 用户那次是否崩/卡 | 事发约 3 分钟后 `pgrep -x hello` 仍能抓到 PID 67060 ⇒ 事件循环活着；之后进程消失（窗口被关） |

**归属说明（这是推断，不是查出来的）**：`IMKCFRunLoopWakeUpReliable` 是 Apple InputMethodKit
的 mach port 名，故这条 NSLog 来自系统输入框架而非 Qt 或本仓库。想证实到具体 dylib 需要
`strings` 系统框架二进制，而 macOS 11+ 起它们在 dyld shared cache 里、磁盘上取不到 ——
本机实测 `strings` 三个框架均 0 命中，所以止步于「字符串语义」这一层。

**处置**：不处理。从终端直跑才会看见；`dist/Hello.app` 双击时 stderr 无人接。开发期想要干净日志：
`./build/hello 2>/dev/null`，或走 `qtphp run` / 打包产物。

## F13. 表格/树 diff 的四个真实缺陷（Phase 12.1，`--difftest` 实测）

`--difftest` 首跑 **7/12 失败**，逐条追到 C++，四个根因：

1. **重建顺序反了**（主因）。`buildNode()` 先 `applyNodeProps()`（其中 `current` → `selectRow()`/`setCurrentItem()`），
   后 `qtRebuildTable()`/`qtRebuildTree()` —— 而重建里是 `setRowCount(0)` / `tree->clear()`。
   于是**声明式选中永远无效**，连第一次渲染都不生效（不是「重渲染才丢」）。
   ⇒ 表格/树的重建挪到 `applyNodeProps()` **之前**。

2. **重建无条件跑**。`buildNode()` 尾部的重建没有任何签名守卫，每帧整表/整树全量重画：
   大表每帧 O(n) 重建、选中/展开态被抹、item 指针全换。
   ⇒ 新增 `QtWindowBox::structuralChanged(id, node, type)`：对 `columns/rows/row_ids`（table）
   与 `headers/nodes`（tree）算签名，变了才重建。签名存进**既有的** `propSigs_[id]` ——
   普通属性循环会跳过这些键，所以两套签名不会互相覆盖。

3. **`patch()` 把错误的节点交给重建**：`qtRebuildTable(this, table, spec)` 里 `spec` 是 op 本身
   （`['op'=>'set','id'=>…,'props'=>[…]]`），而 `qtField(node,'rows')` 读的是**顶层** `rows` —— 永远读不到。
   结果 `setRowCount(0)` 后面没数据 ⇒ **一次只改 `enabled` 的补丁把整张表清空**（树同理，是 `clear()`）。
   ⇒ 改为传 `props`，且顺序与 render 路径一致（先重建再逐属性）。

4. **结构字段的跳过表是类型无关的**。`applyNodeProps()` 硬编码跳过 `rows/columns/row_ids/nodes/headers`，
   而 `rows` 对 **QTextEdit 是普通属性**（可视行数，`qtApplyProp()` 里有实现）⇒ 走 render 时这条实现是死代码。
   ⇒ 换成 `qtIsStructuralKey(type, key)` + `qtStructuralKeys(type)`（`qt_common.h`），按类型判定。

**选中保留的口径**（重建里新增）：清内容前记住 `currentRow` 首列的 `Qt::UserRole`（行 id），
填完后**按 id** 找回 —— 按索引记会在插行后选中错位；整段在 `QSignalBlocker` 内，不产生假 `select` 事件。
树同理，用 `QTreeWidgetItemIterator` 按节点 id 找回。
`qtRebuildTable` 还补了「没给 `columns` 时按最宽一行扩列」—— 控件构造是 `QTableWidget(0,1)`，
不扩列则第 2 列之后的 `setItem` 静默失败。

**顺带**：`columns.isEmpty()` 与 `rows` 缺失时**不再清表**（早先 `setRowCount(0)` 在 `isArray()` 检查之前）。

`--difftest` 12/12 过、`--selftest` 仍 10/10、`--shot` 760×560、PHPUnit 103 例全绿。

**仍未覆盖的边界**（下一步候选）：`FakeBridge` 的 `qt_fake_default_value()` 对 `table`/`tree`/`list`/`combo`
返回 `null`，与真实桥接的数组/字符串形态不一致 —— 纯 PHP 测试里读这些控件的值会拿到跟生产不同的形状。

## F14. `patch()` 的 `call` 操作落地（Phase 12.2，`--difftest` 实测）

原状态：`qt_window_patch` 的 `call` 分支只有 `Q_UNUSED(method); Q_UNUSED(args);` —— 契约里写了、README 没写、
调用方拿不到任何行为。现实现六个方法（`appendRows` / `clear` / `setText` / `setValue` / `select` / `focus`），
`args` 统一按**位置参数**取（`args[0]`、`args[1]`），避免为每种方法发明一套对象形状。

**命令式旁路必须让 diff 签名作废**，这是这一条真正的技术含量：
`propSigs_` 记的是「上次应用过的值」，命令式改控件不经过它。不作废的话 —— `clear` 把表清空后，
下一次 `render()` 拿同一棵树算出的 `rows` 签名与存储值**相等** ⇒ `structuralChanged()` 返回 false ⇒ 不重建 ⇒
**表永久是空的**。新增 `QtWindowBox::forgetProps(id, keys)` 摘掉被命令式改过的键
（`appendRows`/`clear` 摘结构键，`setText` 摘 `text`，`select` 摘 `current`…），
缺失键的签名比较是 `""` vs 真实签名（含 undef 也是 `"\x01"`），必然不等 ⇒ 下一次 render 以树为准重新同步。
文档口径同步写进 stub 与 README：命令式的改动只在下一次整树渲染前有效。

`select` 不另写一套选中逻辑，直接 `qtApplyProp(..., "current", args[0])` —— 与声明式同一实现，
避免 table 按行 id、tree 按节点 id、list 按索引这些口径在两条路径上漂移。
`setValue` 对 `QLineEdit`/`QTextEdit` 走文本、其余走 `value`，与 `qtWidgetValue()` 的读数口径对齐。

**鉴别力反证（关键，否则断言可能是空跑）**：把 `forgetProps()` 临时改成空操作重编译，
`--difftest` 第 16 条「table 命令式改过后重渲染以树为准」精确失败为 `{"row":-1,"value":""}`
—— 正是预测的「永久空表」失效模式；恢复后 20/20。这条反证说明该断言真的在验签名作废，而不是碰巧通过。
（`appendRows`/`clear`/`select` 三条形同成功地被保留：它们验的是方法本身，不依赖作废。）

实测（`QT_QPA_PLATFORM=offscreen`）：`--difftest` 20/20、`--selftest` passed、`--shot` 760×560 PNG、
`qtphp test` 108 tests / 173 assertions、`qtphp lint` 契约一致。

FakeBridge 侧同步实现六个方法，并加了一条与真实桥接一致的守卫：控件 id 未知时整条 `call` 跳过
（真实桥接是 `if (!widget) continue;`），否则纯 PHP 测试会给不存在的控件凭空写出状态、验不出「打错 id」这类问题。
`focus` 在假桥里记成 `props['focused'] = true` —— 桥接契约没有「查询焦点」的函数，真实 Qt 侧只能断言它不改值。

## F15. 多窗口 / 托盘 / 定时器（Phase 12.3，示例 + 两处真实缺口）

**多窗口的真实约束**：`QtApp::run()` 的循环条件是 `\qt_window_is_open($this->handle())`，
每帧只 `process_events` + `drainEvents` **自己那个窗口**的事件队列。所以第二个窗口的控件信号
会入它自己的队列而没人取 —— 界面能画（Qt 全局事件被主窗口的泵带起来了），但 PHP 侧 handler 永远不触发。
⇒ 多窗口必须自己按帧轮流泵；为此给 `QtApp` 补了 `isOpen()`（3 行，包已有的 `qt_window_is_open`），
否则示例连「主窗口还开着吗」都问不出来。`--selftest` 里 `open_log_btn`/`close_log_btn` 两条用例
就是走这条真路径（`new QtApp()` + `createWindow` + `render` + `patch/appendRows` + `close`）。

**泵循环 + 真实 QTimer 的存活实测**：`QT_QPA_PLATFORM=offscreen ./build/hello` 后台跑 3 秒
（1 秒一跳 ⇒ 期间至少触发 3 次），进程仍存活、日志只有 1 行字体噪声、无 PHP 错误。
这条只证明「泵循环与 QTimer 接线不崩」；tick handler 可调用由 `--selftest` 的 `timer clock` 用例证明，
`appendRows` 落进真实表格由 `--difftest` 第 13/14 条证明 —— 三件事各有一条证据，没有一条能替另一条背书。

**托盘兜底图标不是美化**：`setTray()` 原来只在 `icon` 非空时 `setIcon()`，
而 macOS/Linux 上**无图标的 `QSystemTrayIcon` 根本不显示** ⇒ 不传 icon 的「托盘演示」是看不见也点不到的空壳。
现改为：给了就用给的，没给用窗口图标，窗口也没图标时用 `style()->standardIcon(SP_ComputerIcon)`（需 `#include <QStyle>`）。

**托盘事件不带 id**：`enqueue("tray")` 的 id 是空串，而 `QtApp::handleEvent()` 的分支是
`if ($id !== '' && isset($handlers[$id][$type]))` ⇒ 空 id 只能由 `onAny('tray', …)` 接住。
这是读代码读出来的口径，测试里用 `dispatch(['type'=>'tray'])`（不带 id）验了一遍。

**示例里 `patch/appendRows` 的一个使用前提**：副窗口 `render()` 之后必须**先泵一帧**再 `appendRows` ——
`patch` 查的是 `widgets_` 里已存在的控件，没泵过就找不到 `log_tbl`，第一条日志会被静默丢掉
（`patch` 对未知 id 静默忽略，与未知属性一致）。所以 `open_log_btn` 里是
`render() → runFrames(1) → log_append(...)`。

**AOT 侧新验证到的两点**（此前仓库里没有任何先例）：`date('H:i:s')` 在 tpc 编译产物里可正常调用；
把 `QtApp` 实例存进 `$state['log']`（`array<string,mixed>`）再取出用 `instanceof` 判类型可行。

实测（`QT_QPA_PLATFORM=offscreen`）：`--selftest` **14/14**（新增 4 条）、`--difftest` 20/20、
`--shot` 760×560（新增「实时」分组已在图上）、`qtphp test` **112 tests / 183 assertions**、`qtphp lint` 契约一致。

## F16. 打包链复验（12.3 之后）：bundle 不带 offscreen 插件

`php bin/qtphp package examples/hello` → `dist/Hello.app`（CLI 报 99.6 MB，`du -sh` 83M）。
产物本身没问题，**但它的无头能力和 build 目录里的二进制不一样**：

```
Contents/PlugIns/platforms/  →  只有 libqcocoa.dylib

$ env -i QT_QPA_PLATFORM=offscreen Hello.app/Contents/MacOS/hello --selftest
qt.qpa.plugin: Could not find the Qt platform plugin "offscreen" in ""
This application failed to start because no Qt platform plugin could be initialized.
Available platform plugins are: cocoa.
true rc=134          # SIGABRT
```

- **为什么 build 目录的 `hello` 能 offscreen**：它从开发机的 Qt 安装解析插件
  （`/opt/homebrew/share/qt/plugins/platforms/` 里有 `libqoffscreen.dylib`、`libqminimal.dylib`），
  而 macdeployqt 只按目标平台拷 `libqcocoa.dylib`。⇒ **offscreen 依赖开发机，不随 bundle 走**。
- **`headless(true)` 与 QPA 平台无关**（`src/QtApp.php:192`）：它只让 `message()`/`confirm()` 直接返回默认值，
  避免 `exec()` 弹模态永久阻塞。别把它读成「无头模式开关」—— 我这次就先读错了。
- **正确口径**：对 bundle 做裸环境验收要用 cocoa（需要 GUI 会话）：
  `env -i PATH=/usr/bin:/bin HOME=$HOME Hello.app/Contents/MacOS/hello --selftest` →
  **14/14 ok、rc=0**；`--difftest` → **20/20、rc=0**；`--shot` → 760×560，「实时」分组在图上（读图确认）。
  CI 若想对 bundle 无头验收，得自己把 `libqoffscreen.dylib` 拷进 `Contents/PlugIns/platforms/`（本轮未做）。
  **→ 这条已在 12.4 由 `package` 自动完成，见 F17。**
- **方法论坑**：`cmd | tail` 之后 `$?` 是 `tail` 的退出码 —— 上面那次 SIGABRT 差点被记成 rc=0。
  要测退出码必须先 `out=$(cmd 2>&1); rc=$?` 再打印。

## F17. 让 bundle 支持无头验收：补拷 offscreen 插件（Session 8）

`bin/qtphp` 的 `packageAppBundle()` 在 macdeployqt 之后新增 `vendorHeadlessPlugin()`：把
`libqoffscreen.dylib` 拷进 `Contents/PlugIns/platforms/`，并把 `@rpath/Qt{Core,Gui,Widgets}` 引用
`install_name_tool -change` 成 `@executable_path/../Frameworks/...`；随后把该插件也塞进
`vendorBundleDeps()` 的队列，兜住它可能带的绝对路径依赖。找不到插件只 `warning` 不失败 ——
打包不该因为缺一个测试用插件而中断。

**为什么必须自己改写引用**：macdeployqt 部署 cocoa 时**并不修 `LC_RPATH`** —— bundle 里的
`libqcocoa.dylib` 至今带着 `@loader_path/../../../../lib`（在 bundle 内算出来是 `dist/lib`，不存在）。
它是把引用直接改写成 `@executable_path/../Frameworks/...` 才生效的。照抄这个做法，别指望 rpath。

**只拷 offscreen、不拷 minimal**：brew 的 `libqoffscreen.dylib`（156 KB）依赖只有 `@rpath/QtCore`+`@rpath/QtGui`
+ 系统框架，改写完就完全自包含；而 `libqminimal.dylib` 还带绝对路径 `/opt/homebrew/opt/freetype/lib/libfreetype.6.dylib`，
多拖一个 dylib 且 offscreen 已够用 —— 没需求就不搬。

实测（`env -i`，PATH/HOME 之外全清空）：
```
php bin/qtphp package examples/hello   → 「已补无头验收插件: libqoffscreen.dylib」，99.7 MB（+0.1 MB）
bundle --selftest  (QT_QPA_PLATFORM=offscreen) → 14 行 ok + "selftest passed"，真 rc=0
bundle --difftest  (同上)                        → 20 行 ok + "difftest passed"，真 rc=0
bundle --shot      (cocoa，不设 offscreen)        → rc=0，760×560 PNG 正常（仅 F12 那行 IMK 噪声）
otool -L PlugIns/platforms/libqoffscreen.dylib   → 只剩 @executable_path/../Frameworks/... 与系统框架
codesign --verify --deep --strict                 → OK
php bin/qtphp lint → 契约一致；php bin/qtphp test → OK (112 tests, 183 assertions)
```
**Windows 侧未做**：`windeployqt` 那条路径没动，本机无 Windows 环境可验，offscreen 是否随
`windeployqt` 一起部署尚未实测。

## F18. Windows/Linux 打包分支的边界（Session 9）

**windeployqt 的默认插件清单没查到权威结论**：Qt 文档只说它"automatically collects all required
Qt libraries, plugins"、Windows 平台插件名叫 `qwindows.dll`，没说 offscreen/minimal 是否在列
（想读 `qttools/src/windeployqt/main.cpp` 原文，raw.githubusercontent 抓取超时）。
⇒ 不押注它的行为：`vendorWindowsOffscreenPlugin()` 写成**幂等补拷** —— `dist/platforms/qoffscreen.dll`
已存在就直接返回，缺了才从 `<qt>/plugins/platforms/` 拷，源也找不到就 warning 不失败。
补拷本身是安全的：`qwindows.dll` 今天就从 `platforms/` 里加载、并解析到 exe 同目录的 `Qt6*.dll`，
新插件走的是同一套解析路径。**本机无 Windows 环境，这条分支未实测。**

**Linux 走不到打包**（按代码路径推断，未实测）：`cmdPackage()` 的分派是
`if (PHP_OS_FAMILY !== 'Windows') return packageAppBundle(...)` —— 非 Windows 一律当 macOS，
而 `packageAppBundle()` 开头就要求 `macdeployqt` 可执行 + 项目里的 `Info.macos.plist`，
Linux 上两者都不存在 ⇒ 直接 `error` 退出。`resolveProjectYaml()` 那边倒是已经预留了
`project.linux.yml` 的后缀映射，所以缺的是 package 这条链。

## F19. Linux 侧的三处「非 Windows 即 macOS」假设（Session 10）

1. **`cmdPackage()` 的分派**（已修）：原来是 `if (PHP_OS_FAMILY !== 'Windows') return packageAppBundle(...)`
   ⇒ Linux 上会去要 `macdeployqt` 与 `Info.macos.plist`，报错指不到真正缺的东西。
   现改成显式三路：Darwin → `.app`；Windows → `dist/` 目录；其余 →
   `未实现 <平台> 平台的打包` 并 rc=1。
   **实测方式**：本机是 Darwin，用 12.2 那套鉴别力反证 —— 临时把 Darwin 分支的条件改成永不成立的
   `'TEMP-NEGATIVE-TEST'` 逼新分支执行 → 打出 `[ERROR] 未实现 Darwin 平台的打包…`、`rc=1`、`dist/` 未被碰；
   还原后 `grep -rn TEMP-NEGATIVE-TEST` rc=1（干净），macOS 重打包 + bundle offscreen `--selftest` 14/14 rc=0 全绿。
   注意这验的是**分支可达与退出码**，不是 Linux 上的真实行为（消息里的平台名取自 `PHP_OS_FAMILY`）。
2. **`checkSharedLibraryDeps()` 是 Mach-O 专属**（未修）：`otool -L` + `\.dylib` + `/System/Library/` 白名单，
   Linux 上 `otool` 不存在 → `shell_exec` 空 → **静默返回 []**，也就是 `qtphp run` 的依赖自检在 Linux 等于没做。
   真要支持得换 `ldd` + `libX.so` 形态。
3. **`cmdBuild()` 的 brew 前缀注入**（无害但未修）：`PHP_OS_FAMILY !== 'Windows'` 时跑 `brew --prefix`，
   Linux 上取不到就跳过；但 Linux 的 Qt 链接方式（`-lQt6Core` + rpath，非 framework）与 macOS 完全不同，
   `project.macos.yml` 那套 `-Wl,-framework,X` 在 Linux 上不成立。

⇒ 结论：**Linux 支持是一个成建制的缺口，不是一行判断**。本轮只把"报错指错方向"这一处修掉，
没有假装实现打包。

## F20. Apple Container 在这台机器上没有 NAT egress，用宿主侧转发代理绕过（Session 11）

**症状与定位（全部实测）**：容器里 DNS 能解析（`getent hosts mirrors.aliyun.com` 出 IP），但任何
出站 TCP 都超时 —— `223.5.5.5:443`、`223.5.5.5:80`、`mirrors.aliyun.com:443`、`192.168.31.1:53` 全 FAIL；
而 `192.168.64.1:53`（vmnet 网关）**OK**，宿主 `nc` 监听 `192.168.64.1:19022` 时容器能连上并送到数据。
⇒ 不是 DNS、不是容器网络栈、不是 macOS 应用防火墙（`socketfilterfw --getglobalstate` = disabled）。

逐条排除：
1. **不是单台机器坏了**：`container create` 全新机器（同镜像）同样 FAIL。
2. **不是路由缺失**：容器 `/proc/net/route` 有默认路由指向网关，ARP 表里有网关 MAC。
3. **`--option mode=bridged` 不真桥接**：`container network create brhome` 成功，但拿到的还是
   `192.168.65.0/24` 这种 host-only 段，不是局域网 `192.168.31.x`，egress 照样 FAIL。
4. **`container system stop && start` 没修好**：egress 仍 FAIL，而且副作用是 **`default` 网段从
   `192.168.64.0/24` 迁到 `192.168.65.0/24`**。
5. **没有特权组件**：`/Library/PrivilegedHelperTools/` 里没有 container/vmnet 助手，
   `systemextensionsctl list` 只有一个小米相机扩展 ⇒ vmnet NAT 走的是系统 `vmnetd`，本机它不给转发。
   机器上同时跑着深信服 aTrust 零信任（`aTrustXtunnel`），高度可疑但**未证明**，也不是我能动的东西。

**绕行办法**：既然「容器 → 宿主网关 IP」是通的，就在宿主上监听那个网关地址做一个只读转发代理
（`/tmp/qtphp-linux/proxy.py`，只允许 `GET`/`HEAD`/`CONNECT`，其它方法 405），容器把 apt 指向它：

```
printf 'Acquire::http::Proxy "http://192.168.65.1:3128/";\nAcquire::https::Proxy "http://192.168.65.1:3128/";\n' \
  > /etc/apt/apt.conf.d/99hostproxy
```

实测结果：`apt-get update` 成功，`Fetched 9279 kB in 11s (808 kB/s)`（含 `bookworm/main arm64 Packages 8689 kB`），
`https://packages.sury.org/php` 也通（说明 CONNECT 隧道 + TLS 有效）⇒ **Linux 侧现在能真装东西、真编译了**。

两个必须记住的坑：
- **代理绑的地址会随重启变**：`container system start` 后网关从 `192.168.64.1` 变成 `192.168.65.1`，
  代理得跟着重绑（脚本已支持 `python3 proxy.py <ip> [port]`）。
- **同一网段里两台机器拿到过同一个 IP**：重启后 `tgl` 与探测机都显示 `.2`，ARP 冲突让 `tgl` 连代理
  报 `Unable to connect to 192.168.64.1:3128`。删掉探测机（`container stop` → `container delete`）即恢复。
  另外 `container exec` **不接受 `--` 分隔符**（`failed to find target executable --`），`container create`
  的镜像是**位置参数**而非 `--image`。

**磁盘账**：容器根盘是 `/dev/vdb 504G / 502G avail`，但它落在宿主 `~/Library/Application Support/com.apple.container`
（实测 9.2 GB），宿主启动卷只剩 **9.9 Gi**（`/Volumes/data` 还有 391 Gi，可容器存储不在那）
⇒ 往容器里装 GB 级东西实际吃的是快满的启动卷，装前必须 `df`。

**下次复用这套环境的命令**（代理脚本在 `/tmp/qtphp-linux/proxy.py`，重启 Mac 会没，需要重写）：
```bash
container start tgl
GWAY=$(ifconfig | awk '/^bridge[0-9]+/{b=$1} /inet 192\.168\.6[0-9]\.1/{print $2; exit}')   # 网关会变，每次查
python3 /tmp/qtphp-linux/proxy.py "$GWAY" &                      # 只读转发代理（GET/HEAD/CONNECT）
python3 -m http.server 8000 --bind "$GWAY" --directory /tmp/qtphp-linux/serve &   # 给容器发文件
container exec tgl bash -c 'cd /work/qt && export http_proxy=http://'"$GWAY"':3128 https_proxy=$http_proxy \
  && php bin/qtphp build examples/hello'
```
容器里 apt 的代理写死在 `/etc/apt/apt.conf.d/99hostproxy`，网关一变就要改这个文件（`Unable to connect to <旧IP>:3128` 就是它）。

## F21. Linux 侧首次真实跑通：Debian 12 arm64 + Qt 6.4.2（Session 11）

环境：Apple Container 里的 `debian:bookworm-slim`（aarch64），`g++-12`、`cmake 3.25.1`、
`qt6-base-dev 6.4.2+dfsg-10`、宿主 PHP 8.4.25 跑 CLI。网络靠 F20 的宿主转发代理。

**编译链全通，实测数字**：
- tpc 在 Linux 上自建私有 embed 运行时成功：`/root/.typephp/php-builder/php-8.5.11-142201d298b578e5`。
  compat 哈希与 macOS 的 `5852a1ce211cc711` **不同** ⇒ 缓存按平台分桶，两边互不污染。
- 产物：`ELF 64-bit LSB pie executable, ARM aarch64 … not stripped`，58 MB；
  `ldd` 里 `libQt6{Widgets,Gui,Core,DBus}.so.6`、`libonig.so.5` 全部解析到 `/lib/aarch64-linux-gnu`，无 `not found`。
- `QT_QPA_PLATFORM=offscreen`：`--selftest` **14/14 rc=0**、`--difftest` **20/20 rc=0**、
  `--shot` 出 **760×560 PNG rc=0**（读图确认：菜单、标题、输入行、设置/进度/实时三个分组、
  底部三按钮、状态栏「就绪」都在）。
- `php bin/qtphp test` → OK (112 tests / 183 assertions)；`lint` → 契约一致；
  `run examples/hello --selftest` → rc=0；`new probeapp` 生成的 `project.linux.yml` 里
  三元组推导正确（`/usr/include/aarch64-linux-gnu/qt6`）。
- **Qt 版本差是白捡的兼容性证据**：Debian 12 是 6.4.2，本机 brew 是 6.11.2，
  两套断言（14 + 20）在 6.4.2 上同样全绿 ⇒ 声明式 diff 引擎没踩到 6.4→6.11 的行为差。

**Linux 独有的硬前置（macOS 上是 brew 顺带装的，Linux 必须显式列）**：
- 首轮编译死在 `fatal error: mpfr.h: No such file or directory` 与 `gmpxx.h`（phpx 的
  `src/core/big_float.cc` / `big_int.cc`）⇒ 要 `libgmp-dev libmpfr-dev`。
- 编 PHP 源码要 `build-essential pkg-config bison re2c autoconf xz-utils zlib1g-dev libxml2-dev
  libsqlite3-dev libonig-dev`（`libonig` 是 `--enable-mbstring` 的依赖，缺了 configure 就废）。

**tpc 每次构建都会先联网取元数据**：进程里看到
`curl --fail --location --retry 3 https://www.php.net/releases/index.php?json…`，
即使 `~/.typephp/archives/php-8.5.11.tar.xz` 已经放好也一样 —— 它要先查索引拿到 pinned sha256，
再决定要不要复用本地包（`OfficialPhpSource::prepareLocked()`：`hash_file('sha256', $archive) !== $release['sha256']`
才重下）。⇒ **纯离线机器上第一次 build 一定失败**，得先把索引和包都预热。

**Debian 的 Qt 没有架构无关的头文件路径**：`/usr/include/qt6` 不存在（实测 `No such file`），
只有 `/usr/include/<三元组>/qt6`；而 tpc 的 yml 只对 `sources` 支持 `PHP_OS_FAMILY` 条件表达式
（`ProjectYamlLoader::replaceOsFamilyComparisons()`），路径字符串不做插值
⇒ 三元组只能在**生成时**写死进 `project.linux.yml`，这就是 `linuxMultiarchTriple()` 存在的原因。

**offscreen 下的三条噪声**（都无害，别当错误）：
`QStandardPaths: XDG_RUNTIME_DIR not set`、
`QObject::connect: No such signal QPlatformNativeInterface::systemTrayWindowChanged(QScreen*)`
（托盘在无平台原生接口时的探测）、`This plugin does not support propagateSizeHints()`。

**CLI 这轮为 Linux 落地的四处**（都改完就在 macOS 侧复验过没回归）：
`findQt()` 多架构分支、`doctor` 认 g++（原来只认 clang++，Linux 上必然报「未找到 C++」）、
`checkLinuxLibraryDeps()` 走 `ldd`（只认 `not found` 与不存在的非系统绝对路径）、
`cmdNew()` 生成 `project.linux.yml`。
`qtphp package` 在**真 Linux** 上第一次跑到 12.6 那条分支：`[ERROR] 未实现 Linux 平台的打包…`、`rc=1`
—— F19 的鉴别力反证到这里换成了真实环境证据。**Linux 打包路线仍未实现**，这是下一步。

## F22. Linux 打包在真机上跑通：`dist/<name>/` = ldd 闭包 + DT_RPATH + qt.conf（Session 12）

环境仍是 F20/F21 那台 `tgl`（Debian 12 arm64 + Qt 6.4.2）。新装一个包：
`apt install patchelf` → `patchelf 0.14.3-1+b1`（slim 镜像里**没有**，是 Linux 打包的新硬前置）。

**先实测再定方案**（原来 `build/hello` 的动态段）：
- `(RUNPATH) /root/.typephp/php-builder/php-8.5.11-142201d298b578e5/install/lib`，
  NEEDED 直连 11 个：`libQt6{Widgets,Gui,Core}`、`libstdc++`、`libxml2`、`libsqlite3`、`libz`、
  `libonig`、`libm`、`libgcc_s`、`libc`。**`ldd` 传递闭包 51 行**，全部解析到 `/lib/aarch64-linux-gnu`，无 `not found`。
- **ELF 里没有 libgmp/libmpfr** —— 那两个 `-dev` 包只是 phpx 的**编译期**头依赖
  （`big_float.cc`/`big_int.cc` 要 `mpfr.h`/`gmpxx.h`），不进运行时闭包。打包因此不用搬它们。
- 系统 `libqoffscreen.so` 自己**没有** RPATH/RUNPATH，NEEDED 是 X11/GLX + Qt6Gui/Core + libc
  —— 这些在可执行文件的闭包里已经先加载了，所以 offscreen 插件即使不改写也能 dlopen 成功。
  `libqxcb.so` 另有 14 个 xcb 家族依赖（`libQt6XcbQpa`、`libxcb-*`、`libSM/ICE`、`libxkbcommon-x11`），
  **不在**可执行文件闭包里 ⇒ 插件必须自己带 rpath，链接期给 yml 加 `-Wl,-rpath` 这条路覆盖不到它。

**闭包尺寸的坑**：`du -ch $(ldd … | 绝对路径)` 只算出 **2.5 MB**，因为 `/lib/aarch64-linux-gnu/libQt6*.so.6`
是**软链**；`readlink -f` 后才是真身 —— 89 个对象实测量 **83.2 MB**（`libicudata.so.72.1` 一个就 29.8 MB）。
先按这个数决定「`platforms/` 整目录全拷」不心疼：8 个平台插件 + 2 个 `xcbglintegrations` 只多几个 xcb 库。

**为什么必须 DT_RPATH 而不是 DT_RUNPATH**：`ld.so(8)` 里 RUNPATH **只作用于本对象自己的 NEEDED**，
RPATH 才沿依赖链传递。只在可执行文件上设 `$ORIGIN/lib` 的 RUNPATH，`libQt6Gui` 自己的
`libglib-2.0`/`libEGL`/`libfontconfig` 会**回落到系统 ld.so.cache** —— 本机看着一切正常，换台没装 Qt
的机器就炸。所以 `patchelf --force-rpath --set-rpath`，并且在 `verifyLinuxPackage()` 里
**直接读 `readelf -d` 断言有 `(RPATH)` 且没有 `(RUNPATH)`**（patchelf 老版本会留下两者共存，那 DT_RPATH 白写）。

**实现（`bin/qtphp` 新增 6 个函数，`cmdPackage()` 的 Linux 分支从报错改成派发到 `packageLinuxDir()`）**：
产物布局 `dist/<name>/{<name>, lib/*.so, plugins/{platforms,xcbglintegrations}/*.so, qt.conf, assets/}`；
`elfDeps()` 用 `ldd` 拿 `soname => /abs`（落地名用 **soname**，不是 `readlink` 后的真名，否则按 soname 找不到）；
`isSystemSoname()` 把 glibc 家族（libc/libm/libdl/librt/libpthread/libresolv/libnsl/libutil/libgcc_s/ld-linux）
留给目标系统，**libstdc++ 要搬**（它按 GLIBCXX 符号版本卡 ABI，目标机旧版会缺符号）；
`linuxQtPluginDirs()` 认 Debian 的 `lib/<三元组>/qt6/plugins` 与扁平 `plugins/` 两种布局；
`qt.conf` 写 `[Paths] Plugins = plugins`，让 Qt 不靠环境变量就能找到插件目录。
**没走链接期 rpath**：插件不是我们链出来的，且开发产物（`build/`）保持原样、只在 package 阶段动产物。

**实测验收（全部真 rc）**：
```
php bin/qtphp package examples/hello   → rc=0，85 个 .so + 10 个插件，dist/hello 140.3 MB
readelf -d dist/hello/hello            → (RPATH) [$ORIGIN/lib]，无 RUNPATH
ldd 里落在产物外的行（glibc 家族除外）  → 空
ldd plugins/platforms/libqxcb.so 落在产物外的行 → 空（插件也自包含，RPATH=$ORIGIN/../../lib）
cd dist/hello && env -i QT_QPA_PLATFORM=offscreen ./hello --selftest  → 14 条 ok，rc=0
                                              --difftest             → 20 条 ok，rc=0
                                              --shot                  → rc=0，31841 B PNG
```
自检里那句「每一行都落在产物内」**不是摆设**：另造一次「搬运循环故意漏掉 `libQt6XcbQpa.so.6`」的分支逼验，
输出 `[ERROR] 仍指向产物之外： - libqxcb.so 的 libQt6XcbQpa.so.6 => /lib/aarch64-linux-gnu/…`
（外加两个 xcbglintegrations 插件各一行）并且 `rc=1` —— 这条正是可执行文件的 `ldd` 看不见、只有对插件
自己跑 `ldd` 才会暴露的依赖。第一次逼验（把 `libxcb.` 加进跳过名单）**没触发**，因为搬运和自检共用
`isSystemSoname()`，跳过的同时也就免检了 —— 要漏检必须让「非 system 的 soname 不在 lib/ 里」。

打包产物的 PNG 与开发产物的 PNG **逐字节一致**（sha256 `c79f6cc5cd4e…43a3`，两边同一个值）⇒
搬库+改写 rpath 没有改变渲染结果。重复 `package`（`dist/hello` 已存在 → 整棵重建）rc=0；
在项目目录内 `php ../../bin/qtphp package .` rc=0（`basename('.')` 那个老坑没复发）。
macOS 侧复验没回归：`php -l`、`test` 112/183、`lint` 契约一致、`doctor` 全部 rc=0。

**没测到的（别当已验证）**：`xcb` 插件在**真实 X/Wayland 桌面**上起不起 —— 容器里没有 X server，
只证明了它的依赖能在产物内解析；`libqeglfs/libqlinuxfb/libqvnc` 等只是搬进去了，运行未测；
产物**跨发行版**（Ubuntu 24.04 / Fedora）未测，Debian 12 → 12 之外只靠「glibc 家族留系统 + 其余全搬」这个假设。

## F23. Linux 前置落到 `doctor`：探测点的真实位置与两条分支的逼验（Session 13）

12.8 收尾时留的「下一步」第一条。`doctor` 原来在 Linux 上只会说 Qt/C++ 找到了没有，
缺 `mpfr.h`、缺 `patchelf` 这类要等到 `build`/`package` 撞墙才暴露（F21 首轮就是死在 `mpfr.h`）。

**探测点先实测再写死**（都在 `tgl` 上量的，别照抄网上的路径）：

| 包 | 实测落点 | 备注 |
|---|---|---|
| `libgmp-dev` | `/usr/include/aarch64-linux-gnu/gmp.h` + `/usr/include/gmpxx.h` | **gmp.h 只在多架构目录下** ⇒ 搜索目录必须带 `<三元组>` |
| `libmpfr-dev` | `/usr/include/mpfr.h` | F21 那条死因 |
| `libonig-dev` | `/usr/include/oniguruma.h`；`/usr/include/onigmo.h` **不存在** | 所以探 `oniguruma.h`，照 `--enable-mbstring` 的习惯写 `onigmo.h` 会永久误报缺失 |
| `libxml2-dev` | `/usr/include/libxml2/libxml/parser.h` | 嵌套目录，相对 `/usr/include` 写 |
| `libsqlite3-dev` / `zlib1g-dev` | `/usr/include/sqlite3.h` / `/usr/include/zlib.h` | |
| 命令 | `bison`/`re2c`/`autoconf`/`pkg-config`/`patchelf`/`xz` 全在 `/usr/bin` | `xz-utils` 这个包名对应 `xz` 命令 |

**两个分支都用真机逼验过，不是写完就当它对**：
- 命令分支：`apt-get remove -y patchelf` → `[WARN] Linux 构建前置缺失: patchelf` + `apt install -y patchelf`；装回来恢复「齐全」。
- 头文件分支：往探测表里塞一条必然找不到的 `libbogus-dev => definitely-not-here.h` → 报出 `libbogus-dev`，
  还原后「齐全」。多架构搜索那条分支则是**隐式验证**的：`gmp.h` 只存在于 `<三元组>` 目录，而检查判「齐全」，
  说明它确实去那个目录找了。

**apt 的 .deb 缓存别指望**：`remove` 之后 `install` 报
`Failed to fetch … Could not connect to 192.168.65.1:3128` ⇒ 缓存里没有，得先把 F20 的宿主转发代理起回来。
（顺带说明 `tgl` 里装的东西是真的留在容器里跨会话用，不是每次重装。）

**保持原语义**：这些一律 `warning`，不进 `$allOk` —— `doctor` 的 rc 只由 error 级项决定，
Linux 前置缺失不该让 `qtphp doctor` 变成失败退出（`build`/`package` 会在真正需要时报错并给同一条 apt 提示）。
Linux 上因此多一行 `[OK] Linux 构建前置: 齐全`（7 行检查项 + 汇总）；macOS 侧输出与行数不变。

## F24. 两条 tpc 供给路线不能混用（Session 14）

系统里可能同时存在**两个** tpc，它们的运行时来源完全不同，选错就编不过：

| | 原生发行包 | composer 驱动 |
|---|---|---|
| 位置 | 解压目录，如 `tpc_v0.9.4_windows_x64/tpc.exe` | `vendor/bin/tpc.php` → `vendor/swoole/typephp/bin/tpc.php` |
| 运行时 | **自包含**：`phpx.dll`/`SDK/` 就在可执行文件旁边 | 依赖 `vendor/swoole/phpx` **源码树** |
| 可用性 | 解压即用 | 源码树**不含** `build/phpx.dll`、`lib/phpx.lib`，需 phpx 工具链自建 |

**症状**：composer 驱动被选中时报
`Fatal error: The PHPX runtime library was not found at: ...\phpx\build\phpx.dll`
（`vendor/swoole/phpx` 只有 `CMakeLists.txt`/`src`/`include`，没有编译产物）。

**定位链**：`Windows.php` 的 `getBuildLibraryWarnings()` 检查
`<phpxDir>\build\phpx.dll` 与 `<phpxDir>\lib\phpx.lib`；`<phpxDir>` 来自
`PhpxLocator::resolve()` —— 它找的是 **`vendor/swoole/phpx`**（受 `PHPX_HOME` 覆盖）。

**修法**：不做「谁优先」的硬性排序 —— 两条路线在各自平台上都是对的（macOS/Linux 全链路
本来就建立在 composer 驱动 + tpc 自建私有运行时之上，见 F11.3）。改成**按运行时体检**：
选中的候选若旁边没有运行时，而候选表里另有带运行时的，就改用后者。

`tpcHasRuntime()` 的判据与 `findRuntimeLibDir()` 的标记集保持一致（`phpx/`、`SDK/`
目录或 php 运行时 DLL/静态库）；**`.php` 驱动一律返回 false** —— 它的运行时在
`~/.typephp/php-builder`，可能尚未构建，正是本次故障来源。`TPC`/`TPC_DIR` 显式指定时
不做体检，照用。

顺带删掉了原先硬编码的 `D:/git/php/tpc_v0.9.4_windows_x64/tpc.exe`，改为
`nativeTpcSearchDirs()`（`~/tpc*`、`~/.typephp/tpc`、`/opt/tpc` 等惯例位置）+
`whichAll()`（Windows 的 `where` 可能返回多个，全部纳入候选）。

**未测**：macOS / Linux 上「无原生发行包、只有 composer 驱动」的退回路径 ——
逻辑上仍走原顺序，但本窗口无 mac/Linux 环境复验。

## F25. 桥接「能力静默丢失」审计（Session 21）

起因：托盘只转发了 Qt 5 种激活方式里的 1 种（Session 20）。怀疑同类问题还有别处，
于是把「桥接连了哪些 Qt 信号 / 实现了哪些属性」与「文档声称支持什么」做三方交叉比对。

**方法**：`grep` 出全部 `QObject::connect` 与属性键，再与 `docs/src/**` 里的
事件表 / 属性表求差集。**「文档说有、代码没有」= 撒谎；「代码有、文档没写」= 漏了。**

### 查实的 5 个缺陷

| # | 缺陷 | 后果 |
|---|---|---|
| D1 | `margin` / `spacing` 文档写了 4 处、有示例，**代码里根本没实现** | 写了没效果，完全静默 |
| D2 | `checkable` 按钮/group 不发 `toggle`（`QPushButton::toggled` 没连） | `widgets/display.md` 教用户用它，实际拿不到状态 |
| D3 | `build` 不部署 `qoffscreen.dll` | **`QT_QPA_PLATFORM=offscreen` 下三个无头开关全崩**（`0xC0000409`），而文档正是让 CI 这么跑 |
| D4 | `onAny` 被 `on` 静默吃掉（`handleEvent` 命中特例后 `return`） | 埋点/日志/全局快捷键类代码静默失效；**文档明写「两个都会触发」** |
| D5 | `editable` 不支持 table | 新加的 `cellChanged` 永不触发（表格默认只读） |

### 冲突：测试 vs 文档
`testSpecificHandlerWinsOverWildcard` 断言的正是 D4 的旧行为，
与文档「两个都会触发」直接矛盾。经确认采用**文档语义** → 改代码 + 改测试。

### 新增能力（用户要求「尽量补全信号」）
`press` `release`（button）、`commit`（lineedit 失焦）、`itemClick`（list 单击）、
`cell`（table 编辑）、`expand` `collapse`（tree）、`close`（tabs 关闭按钮）、
`toggle` 扩展到 checkable button / group。全部沿用 `{type,id,value,payload}` 约定。

### 一个排查陷阱：tpc 增量缓存
一度以为 `spacing` 的实现有 bug（一加属性就崩）。实际是 **tpc 复用了上一次带崩溃代码的
编译产物**；改 `main.php` 强制重建后就正常了。`qtphp` 自己不管理这个缓存。
→ **改 C++ 后行为诡异时，先 `rm -rf build` 从零重建再判断。**

### 已知限制（非缺陷，已写入文档）
**offscreen 下 `--shot` 渲染不出中文**（方框）：Qt offscreen 平台插件的字体枚举
取不到中文字体。`--selftest` / `--difftest` 不受影响（不渲染像素）。
要检查中文渲染，`--shot` 不要加 offscreen。
