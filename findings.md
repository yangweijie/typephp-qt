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

## F26. webview：三种实现路线的真实代价（Session 24）

要做「内嵌网页」时，面前有三条路，代价差一个数量级：

| 方案 | 额外下载 | 能力 | 打包增量 | 跨平台 |
|---|---|---|---|---|
| QTextBrowser | **无**（QtWidgets 自带） | HTML 子集 + 图片 + 链接，**无 JS** | 0 | ✅ 全平台 |
| QtWebEngine | ~300MB → 解压 **1.5–2GB** | 完整 Chromium | +几十 MB（含 `QtWebEngineProcess.exe`） | ✅ |
| 系统 WebView2 | 运行时**系统已有**，SDK 需自取 | 完整 Chromium（Edge 内核） | **195KB**（loader DLL） | ❌ 仅 Windows |

**选型**：QTextBrowser 做跨平台默认 + WebView2 做 Windows 增强，同一份 PHP 代码两边都能跑。
这样**既不需要用户装 1.5–2GB 的 QtWebEngine，也不需要平台外的额外步骤**。

### WebView2 SDK 怎么拿、留哪些
Windows SDK **不含** `WebView2.h`（实测 `Windows Kits\Include` 里没有），要从 NuGet 取：
```bash
curl -sL -o wv2.zip https://www.nuget.org/api/v2/package/Microsoft.Web.WebView2
```
（2026-10 取到 1.0.4258.31）。包里只需要三样：

| 文件 | 大小 | 说明 |
|---|---|---|
| `build/native/include/WebView2.h` | 2.9MB | COM 接口定义 |
| `build/native/include/WebView2EnvironmentOptions.h` | 19KB | 选项实现 |
| `build/native/x64/WebView2Loader.dll.lib` | 3.5KB | import lib |
| `build/native/x64/WebView2Loader.dll` | 195KB | 随应用分发 |

**11MB 的 `WebView2LoaderStatic.lib` 不用 vendor** —— import lib + 195KB DLL 小得多，
而且那个 DLL 本来就要随应用部署。压缩后整个 vendor 目录约 200KB，入库毫无压力。

### WebView2 的五个工程约束（都踩过）
1. **本项目不走 moc**，所以 `Q_OBJECT` 用不了 → 用 `dynamic_cast` 代替 `qobject_cast`。
2. **没有 WIL 头**（`wil::unique_cotaskmem_string` 编译不过）→ 手写
   `takeCoTaskMemString(LPWSTR)`：取 QString 后 `CoTaskMemFree`。
3. **COM 初始化是异步的** —— 控件同步建得出来，但「环境 → 控制器 → webview」三级对象是回调产出的。
   做法：先返回一个占位 QWidget（diff 需要稳定指针），回调完成后把真 webview 贴进去；
   就绪前到达的 `url`/`html`/`zoom` 记进 pending，就绪后补发。**应用侧看不出异步。**
4. **`ICoreWebView2Environment` 全进程只该建一次**（由用户数据目录唯一确定），
   否则每个 webview 拉起一组浏览器进程。用懒加载单例。
5. **环境创建签名是 4 个参数**：`CreateCoreWebView2EnvironmentWithOptions(browserExeFolder,
   userDataFolder, options, handler)`。

### 最费时的坑：尺寸塌陷伪装成「渲染失败」
症状：webview 区域一片空白，看起来像没渲染。
真相：**导航是成功的**（`NavigationCompleted success=1`），只是控件被压成 **694×16**。
两层原因叠加：
- WebView2 画在自己的子窗口里，对 Qt 的 `sizeHint` **毫无贡献**；占位标签一删、内层布局为空、
  sizeHint 归零，外层布局就把它压扁 → 需要 `QSizePolicy::Expanding` + `sizeHint()` 覆写
- 窗口内容比窗口高时 Qt 会压缩「可压缩」的项，而 `setMinimumSize(1,1)` 等于允许压到 0
  → 改为 `setMinimumHeight(120)`：宁可让布局紧张，也别让 webview 消失

**教训：遇到「控件空白」，先量尺寸，再怀疑渲染链路。**

### `--shot` 截不到 WebView2（已知限制，非缺陷）
WebView2 画在独立子窗口，`QWidget::grab()` 不含那块区域 → PNG 里是空白。
**QTextBrowser 后端能截到**（Qt 自己画的，实测截图确认）。
`--selftest` / `--difftest` 不受影响（不看像素）。

### `qtphp lint` 的返回类型白名单
正则只认 `Variant|void|Bool|String|Int|Array`。写 `bool php_qt_foo()` 会被判成
「C++ 中缺失的实现」。**用 `Bool`（php::Bool）**，与既有实现一致。

### macOS 本机复验（Session 25，Apple Silicon + brew Qt 6.11.2）
上面那些坑都是在 Windows 上踩的，QTextBrowser 分支当时只是「关掉 `/DQT_WEBVIEW2` 重编」间接验的。
mac 上走**真实入口** `project.macos.yml` 复验，结论：**一行平台 yml 都不用改**。原因是 tpc 的
`include` 合并语义帮了忙 —— `sources` 是**继承**的（所以 `qt_webview.cc` 自动进 mac 编译），
而 `cxx-flags`/`link-libs`/`link-paths` 是**整体替换**（所以 `/DQT_WEBVIEW2`、`/I…webview2/include`、
`WebView2Loader.dll.lib` 全被 mac 段清掉）⇒ 编译期自然落到 `#else` 的 QTextBrowser 分支。
**反过来说**：如果哪天 mac 段改成「往 cxx-flags 里追加」，就会把 MSVC 的 `/D` 开关带进来直接编译失败。

实测数字（全部真 rc）：`build` rc=0（11 个 TU，产物 Mach-O arm64 25.5 MB）；
`QT_QPA_PLATFORM=offscreen` 下 `--selftest` **25/25 rc=0**、`--difftest` **20/20 rc=0**；
cocoa 真窗口 `--shot` 3 秒出 760×720 PNG rc=0；`./build/hello` 实跑起窗口、终止后 rc=0；
`qtphp test` 123/201、`qtphp lint` 26 个函数契约一致。读图确认分组标题为
`WebView（backend=textbrowser，js=不支持）`，`html` 里的 `<h1>`/`<b>`/中文均渲染。

**`url` 分支此前在 mac 上从未被碰过**（示例只用了 `html`），临时换探针验通：
`url => 'assets/webview_url_probe.html'` → `qtResolveUrl()` 判无 scheme → `QUrl::fromLocalFile(qtResolvePath())`
→ `QTextBrowser::setSource()`，出图含 `<code>` 等宽、**相对 `<img src="icon.png">` 按文档 base URL 解析正确**、
`<a href>` 成可点链接。探针已还原（`git checkout` + 删临时文件 + 重编复验 25/25、20/20、出图与还原前一致）。

### 两个后端的「可诊断性」不对等（url 写错时暴露）
探针第一次写成裸文件名（`qtResolvePath` 相对 **exe 目录**，而 assets 部署在 `build/assets/`）⇒
文件不存在 ⇒ **QTextBrowser 后端一片空白、无报错、无警告、`--shot` 仍 rc=0**。
同样情况下 WebView2 后端会发 `loaded` 事件且 `payload.success=false`。
⇒ 跨后端写应用时**不能假设「白屏 = 渲染失败」**：textbrowser 上白屏还可能是「路径根本不存在」，
而这条在 mac/Linux 上没有任何信号。可选改进（**未做**）：`qtResolveUrl` 在本地文件不存在时
`qWarning` 一句，或给 QTextBrowser 也发一个 `loaded(success=false)`。

### 原来「仍未在 mac 上验的」三条 —— 已全部收口（见 F27 第 1–3 条）
- `anchorClicked` → `link` 事件的真实信号接线 ✅ **验通**（应用内 `sendEvent` 合成 + 鉴别力反证；
  `--selftest` 走 `dispatch()` 注入不了 Qt 事件，所以这条只能靠临时探针）
- 远程 `url`（`https://…`）❌ **Qt 6 `QTextBrowser` 根本不支持**（不是配置问题），连带查出文档过度承诺
- `zoom` ✅ **确认「除了忽略没有别的路径」**（设与不设 PNG sha256 逐字节相同）

## F27. 那三条「未验」补完 + WebView2 分支的静态审查（Session 25）

### 1. `anchorClicked` → `link` 的真实接线：验通了
`--selftest` 走的是 `$app->dispatch($event)`（`examples/hello/src/main.php:563`），**完全绕过 Qt 侧信号**，
所以「桥接发的信号能不能到 PHP」这条一直没人验过。macOS 的 Accessibility 是关的
（`osascript -e 'tell application "System Events" to UI elements enabled'` → `false`）、机器上也没有
`cliclick` ⇒ 造不了系统级鼠标事件。改用**应用内合成**：临时在 `qt_webview.cc` 加
`qtWebViewProbeFirstLink()`，遍历 `QTextDocument` 找第一个带 href 的 anchor 片段 →
`QTextEdit::cursorRect()` 算视口坐标 → 先自证 `anchorAt(该点)` 非空 → 再
`QApplication::sendEvent(tb->viewport(), press/release)`。这走的就是 Qt 自己的
`mousePressEvent/mouseReleaseEvent → anchorClicked`（与 `QTest::mouseClick` 同一机制）。
实测（offscreen，`--linkprobe`）：
```
[probe] href=https://example.com/probe rect=(12,110 1x16) click=(12,117) anchorAt=https://example.com/probe
PROBE-LINK-EVENT href=https://example.com/probe
```
**鉴别力反证**：把 `QObject::connect` 里的 `box->enqueue("link", …)` 注释掉重编 →
`anchorAt` 照样命中（证明点击位置没问题），但 `PROBE-LINK-EVENT` 不再出现 ⇒ 事件确实来自那条 connect。

### 2. `zoom` 在 QTextBrowser 上确实是「静默忽略」，且无副作用
同一份 html，`zoom=2.0` 与不设 zoom 两次 `--shot` 的 PNG **sha256 逐字节相同**
（`633da1546bc0873fc2ffb37d64a58a6bcfd9240313ce88e8ec6cdeb8e4046cfd`），rc 均 0 ⇒
不但不生效，也不会崩、不会改变布局。

### 3. 远程 `url` 在 QTextBrowser 后端**根本不支持**（这条是文档缺陷）
`WVURL=https://example.com/` → Qt 自己打 `QTextBrowser: No document for https://example.com/`，
截图区空白，**rc 仍 0**。逐项排除：

| 排除项 | 做法 | 结果 |
|---|---|---|
| 不是本机没网 | `curl -o /dev/null -w %{http_code} https://example.com/` | `200`，0.63 s |
| 不是没链 QtNetwork | `project.macos.yml` 临时加 `-Wl,-framework,QtNetwork`，`otool -L` 确认已链 | 同一句警告，PNG 与之前**逐字节相同** |
| 不是 TLS 后端问题 | 换 `http://example.com/` | 同一句警告 |
| 不是异步没等到 | `QT_TEXTBROWSER_SYNCHRONOUS=1`（`--shot` 本就泵 120 帧） | 同一句警告 |
| 对照：本地路径正常 | `file:///tmp/p-html.png` | **没有**「No document」，走到渲染（把 PNG 当文本渲染出字体告警） |

#### 3.1 对象级复验（被追问「是不是拿纯字符串测的」之后重做）
AOT 二进制那条**本来就是 QUrl**（`tb->setSource(qtResolveUrl(toQString(value)))`，`qt_webview.cc:407`；
`qtResolveUrl` 对 `https://…` 走 `colon > 1 ⇒ QUrl(trimmed)`，`qt_common.h:146`），
而且那句 Qt 警告打印的正是 `url.toString()` ⇒ URL 到 `setSource` 时是完整的。
但「对象解析对不对 / 有没有异步路径」当时确实没验，所以用一个**不经过 PHP/AOT 的独立 Qt 程序**重测
（`/tmp/qtb_probe/qtbtest.cc`，brew Qt 6.11.2 framework 直链，`QT_QPA_PLATFORM=offscreen`）：

```
[raw ] indexOf(':')=5  ⇒ 走 QUrl(trimmed) 分支
[url ] QUrl(str)              valid=1 scheme=https  host=example.com  isLocalFile=0 toString=https://example.com/
[url ] QUrl::fromUserInput    valid=1 scheme=https  host=example.com  isLocalFile=0 toString=https://example.com/
[url ] fromLocalFile(误用)  valid=1 scheme=file   host=             isLocalFile=1 toString=file:https://example.com/
[sync] setSource 返回后 text=0 html=622
[t=  55ms … t=6013ms] text=0 html=622  url=https://example.com/     ← 真 QEventLoop 跑满 6 秒，每 50ms 轮询
[qnam] err=0(Unknown error) http=200 bytes=577                      ← 同一进程里 QNAM 拿得到
[load] loadResource(remote img) valid=0 size=0x0                    ← 连远程资源也不取
```

⇒ **不是字符串喂错**：`QUrl` 解析正常（scheme/host 都对、`isLocalFile()==false`），警告是 `setSource`
**同步**打的，之后 6 秒真事件循环里文档始终 0 字符（`html=622` 是空模板骨架），而同一进程的
`QNetworkAccessManager` 能拿到 200/577 字节 ⇒ 差异在「`QTextBrowser` 不发请求」，不在网络、不在等待。
再加一条静态硬证据：**`QtGui`/`QtWidgets` 根本链不到 QtNetwork** ——
`otool -L /opt/homebrew/opt/qtbase/lib/QtWidgets.framework/QtWidgets` 的 NEEDED 里只有 QtGui、QtCore、
libz 和系统框架，**没有 QtNetwork** ⇒ `QTextBrowserPrivate::setSource` 不可能发网络请求。
（顺带：`file:https://example.com/` 那种「误用 `fromLocalFile`」的形态是 `isLocalFile=1`，
与我们实测到的警告形态明显不同 ⇒ 反证桥接层没走错分支。）

#### 3.2 scheme 矩阵：`data:` 同样不支持（原来标的「未实测」已量掉）
`/tmp/qtb_probe/scheme.cc`，每种 scheme 一个新 `QTextBrowser`：

| scheme | 输入 | 结果 |
|---|---|---|
| `data` | `data:text/html,<h1>HI-DATA</h1><b>x</b>` | ❌ `No document for data:text/html,…`，text=0 |
| `data`+base64 | `data:text/html;base64,PGgxPkhJLURBVEE8L2gxPg==` | ❌ 同上，text=0 |
| `https` / `http` | `https://example.com/` | ❌ text=0 |
| `file`（`fromLocalFile` 与字面 `file:///` 两种写法） | `/tmp/qtb_probe/probe.html` | ✅ text=10，首行 `HI-FILE` |
| `qrc:` | `qrc:/probe.html` | 未注册资源，**不可作结论**（只说明本机没那个 qrc） |

⇒ 文档那句要改的范围比原先记的更大：**QTextBrowser 后端只认本地文件**（`file:`/裸路径），
`http(s)://` 与 `data:` 都会静默空白；而 WebView2 后端 `Navigate()` 三种都吃
（`data:` 是 `qt_webview.cc:407` vs `:171` 的 `Navigate(qtResolveUrl(url).toString()…)` 差异）。
桥接层若要对齐，得自己用 `QNetworkAccessManager` 取字节再 `setHtml`（`data:` 可直接解 base64），
那会给 mac/Linux 引入 QtNetwork 依赖 —— **属产品决策，本轮没动**。


口径上也自洽：Qt 官方 `QTextBrowser` 文档通篇只讲**文件名 / 搜索目录 / `qrc:`**，没有任何网络说法 ⇒
`QTextBrowserPrivate::sourceInfo()` 在 Qt 6 里只做本地与 qrc。
**文档缺陷定稿**：`docs/src/zh/widgets/webview.md:44`（及英文版）那句「带 scheme 的（`http://`、`https://`、
`file://`、`data:`）原样使用」在非 Windows 平台只在 `file` 上成立，`http(s)` 与 `data:` 都是空白 + 一句 Qt 警告（见 3.2）。

### 4. WebView2 分支：本机跑不了，改成静态审查（附行号）
探测结论：`/Applications` 无 Parallels/VMware/UTM/Fusion，`~/.ssh/config` 只有 `github.com`，
无 wine，Apple Container 只能跑 Linux；`brew install mingw-w64` 是 GB 级而**启动卷只剩 3.5 Gi** ⇒
连「只做 Windows 交叉语法检查」这条路都不成立。**结论：`QT_WEBVIEW2` 分支在这台机器上无法编译、
更无法运行，任何「验过」的说法都不成立。** 能给的只有代码审查：

| # | 位置 | 缺陷 |
|---|---|---|
| W1 | `qt_webview.cc:271-277` | `loaded` 事件 `sender->get_Source(&uri)` 取完**不用也不 `CoTaskMemFree`**（每次导航泄漏一个宽字符串），而 value 传的是字面量 `QString::fromWCharArray(L"")` ⇒ PHP 侧 `$event['value']` **永远是空串**，与紧邻注释「value = 最终 URL」不符。修法：`takeCoTaskMemString(uri)` |
| W2 | `qt_webview.cc:315-319` | `navigating` 事件同样取 `sender->get_Source()` = **导航前**的源；目标地址在 `args->get_Uri()`。用它做「后退按钮可用性」会拿到上一个 URL |
| W3 | `WebView2Widget` 全文 | **没有析构函数**，从不调 `ICoreWebView2Controller::Close()`（接口在 `WebView2.h:39283`）。按 WebView2 的约定不 Close 就不会释放浏览器子进程 ⇒ 反复创建 webview / 多窗口应用会攒进程。**需在 Windows 上实测确认**（审查推断，非实测） |
| W4 | `qt_webview.cc:217/225/254` | 三级 COM 回调全部 `[this]` 裸捕获，**没有生命周期保护**：用户在「环境 → 控制器 → webview」异步初始化期间关窗，回调就会访问已释放的 `controller_`/`placeholder_`。QTextBrowser 后端用 `box->window()` 作 connect context 由 Qt 自动断连，WebView2 这条没有等价物（W3/W4 是同一类根因：控件析构路径没被设计） |
| W5 | `qt_webview.cc:188-198` | `reload()` / `goBack()` / `goForward()` 三个方法**在 PHP 侧没有任何入口**（既不是属性，也不在 `qt_window_patch` 的 `call` 方法表 `qt_bridge.cc:558-585`）⇒ 死代码，要么接进 `call` 要么删 |

另有两处非缺陷但值得知道：`webView2RuntimeAvailable()` 只查默认 Evergreen 安装，用 Fixed Version
（`browserExeFolder`）时会误判不可用；`--shot` 里那句 `// TEMP: 给 WebView2 异步初始化留时间`
（`examples/hello/src/main.php:256`）是**仓库里唯一残留的 TEMP 标记**，现在无条件对全平台泵 120 帧，
应按 `webViewBackend() === 'webview2'` 收口。

### 5. 克隆即坏：`WebView2Loader.dll.lib` 被 `.gitignore` 的 `*.lib` 吞了（真机证据）
`.gitignore:9` 是构建产物规则 `*.lib`，它把 `third_party/webview2/x64/WebView2Loader.dll.lib`
一起忽略了 —— 而 `examples/hello/project.yml:39` 与 `bin/qtphp:470`（`qtphp new` 模板）都写死链接它，
`third_party/webview2/README.md:13` 和 F26 都**声称该文件已 vendor**。
`git ls-files third_party/webview2/` 里只有 `WebView2.h`/`WebView2EnvironmentOptions.h`/`nuspec`/`README`/
`WebView2Loader.dll`（194,912 B），**没有 `.lib`**；本机磁盘同样没有（`find third_party -type f` 就上面那 5 项）
⇒ **新克隆在 Windows 上链接必失败**（`LNK1181: 无法打开输入文件 "WebView2Loader.dll.lib"`）。
Session 24 的实现记录说 vendor 时保留过 import lib，所以它**大概率还在那台 Windows 机器的磁盘上、
只是被忽略规则挡住没入库** —— 这条是推断，本机无法证实；能证实的只有「仓库里没有、mac 磁盘上没有」。
本轮修掉规则并做了验证（**文件本身只能从 Windows 那台机器补交**，本机没有）：
```
加 !third_party/**/*.lib 之前： git check-ignore -v → .gitignore:9:*.lib
之后： git check-ignore → rc=1（不忽略）；git add -n → add '…/WebView2Loader.dll.lib'
未被削弱的规则： build/phpx.lib 与 examples/hello/build/foo.lib 仍命中 /build/ 而忽略
```


---

## F28. 两条测量学缺陷：`--shot` PNG 不稳定 & tpc 字面量池只增不减（Session 25 追问后自查）

起因是用户一句「`source` 是 QUrl，你拿纯字符串测的吗」。重做到对象级（F27 3.1）的过程中，
顺手用「产物里还有没有探针字符串」和「PNG sha256」复核自己之前的结论，结果这两把尺子都量出了问题。

### 1. `--shot` 的 PNG **跨次运行不稳定** ⇒ sha256 相等只能单向使用
同一个二进制（`examples/hello/build/hello`，全清重建）连跑 6 次 `--shot`：

```
5 × fc9319b597…
1 × 0876b10bf0…      ← 少数派与多数派的差异包围盒 = (30,62)–(643,84)
```

差异区域用 PIL `ImageChops.difference().getbbox()` 定位，两处外部状态：

| 区域（760×720 图内） | 内容 | 根因 |
|---|---|---|
| 行 62–83、列 30–643 | 名字输入框 | **QLineEdit 光标闪烁**：截到亮/灭两种状态 |
| 行 362–380、列 384–716 | 「打开日志窗口」按钮 | **hover/焦点描边**：随宿主鼠标位置与默认焦点变化 |

⇒ 之前所有「两次 `--shot` sha256 相同」的证据（24.1 的回归、24.3 ② 的 zoom）必须按方向读：
- **相等 ⇒ 无视觉变化**：仍然成立（外部噪声只会让哈希*不同*，不会把真实变化抹平成相同）。
- **不同 ⇒ 有回归**：**不成立**，~1/6 概率纯属光标相位。今天实测就撞上一次：
  第一次干净重建出 `3c39bdc8…`、第二次出 `fc9319b5…`，两者行为完全一致。
- 另注：PNG 里**没有** `tIME`/文本 chunk（只有 IHDR/pHYs/IDAT×5/IEND）⇒ 差异是真实像素，不是元数据。

可复现化的改法（**未做**，见下一步）：`--shot` 前让输入框失焦或关掉光标闪烁（`QApplication::setCursorFlashTime(0)`），
并把默认焦点固定，这样 sha256 才能当 CI 基线。

### 2. tpc 的字面量池只增不减 ⇒ `strings 产物` 不能证明「源码是哪一版」
还原探针后那次「重编复验」，产物里仍查得到 `--linkprobe` / `WVURL` / `webview_url_probe` 等字符串，
而 `build/extension-hello.cc` 的 mtime 停在**探针期那次构建**的时间。用一次性标记做了可复现实验：

```
① 干净重建（删掉整个 build/）      → 产物内探针字符串 0 处，25/25 + 20/20 + shot rc=0
② 改 main.php 为 'Hello, INCR-CACHE-MARKER!' → 增量 build rc=0，生成文件被重写（mtime 前进），产物含 marker ⇒ 增量本身没坏
③ 还原 main.php（git 干净）再增量 build     → 生成文件 mtime **不动**，产物**同时**含 marker 与 'Hello, World'
④ 但 ③ 的产物实跑 --shot：标题行区域与 ① 的干净产物 **逐字节相同**（bbox=None）⇒ 渲染的是还原后的代码
```

⇒ 机制：**残留只在字面量池**（`extension-hello.cc` 里 `[427]–[437]` 那批 `php::Str{ZEND_STRL(...)}`，
无任何代码引用，`grep` 全文件只有池内 6 处命中），编译出的**代码**是新版。
所以「产物含旧字符串」不等于「产物含旧逻辑」，但也不能反过来用它自证干净。
正确的自证手段是**删 `build/` 全清重建**（本次已做，见 ①）＋行为比对（见 ④）。
上游可改进点（**未验**，属 `typephp-compiler` 侧）：源码回退到曾构建过的状态时，生成文件不重写、池不收缩。

### 3. `--shot` 噪声的真身是**焦点瞬态**，不是光标闪烁；旧「冻结」写法不收敛（Session 25 续，更正 F28-1）

结论对 §1 的两处更正：① 当时把差异带记成「QLineEdit 光标闪烁」+「hover/焦点描边」，
真正的两层噪声是**应用自己的 1 秒心跳文案** + **输入框有没有焦点**；
② 用 `setCursorFlashTime(0)` + 重建焦点去治它，实测**完全不收敛**（下面 ②③ 两轮是同一对哈希）。

**(a) forcing function**：`--shot` 分支加 TEMP 探针 `SHOTDELAY=<ms>`，每 10ms 泵一帧，把 grab 推到
墙钟的不同相位。上一轮「摘掉冻结后 16/16 同哈希」的反证失败，原因是**不额外延时时 grab 太快**，
噪声根本没机会出现 —— 不是冻结有效。

**(b) 五轮全清重建实测**（同一台 mac、cocoa、同一份 `main.php`，SHOTDELAY ∈ {0,200,400,500,600,800,1000,1200,1600}）：

| 轮 | grab 前处理 | 心跳定时器 | 结果 |
|---|---|---|---|
| ① | 旧版冻结 | 仍注册 | 9 延时 → **5 种**哈希 |
| ② | 无 | 移到 `--shot` 分支之后 | 9 延时 → **3 种**哈希 |
| ③ | 旧版冻结 | 同上 | 9 延时 → **2 种**，且是 ② 那 3 种里的同 2 个值 |
| ④ | 旧版冻结 + `repaint()` + 状态打印 | 同上 | 7 延时 + 12 重复 = **19 样本 1 种** |
| ⑤ | ④ 去掉 `repaint()`（控制组） | 同上 | 7 延时 + 8 重复 = **15 样本 1 种** |

- ① 的差异带 = `(25,366)–(159,714)`，读图确认是「心跳 2 跳 → 3 跳」文案（主窗口 label + 底部状态条），
  与 caret/hover 无关 ⇒ 第一层噪声是应用的墙钟定时器。改法：`setTimer('clock', 1000)` 移到 `--shot`
  分支之后（截图基线不该随秒跳字；自检分支走 `dispatch()`，不依赖这里的注册）。
- ②③ 的差异只落在行 62–83、列 30–643 那一整行 QLineEdit：边框 `#b6b6b6` ↔ `#7e9dc2`
  （Fusion 焦点高亮色）+ 光标有无。**② 与 ③ 出现同一对哈希值** ⇒ 旧版冻结对该翻转无效，
  因为它 `if (focusWidget()) { clearFocus(); setFocus(); }` —— 有焦点时把焦点**留着**，没焦点时什么都不做。
- ④⑤ 期间蓝框态不再出现（34 样本全灰、`windowFocus=-`、`px=#b6b6b6`）。④ 相对 ③ 在第一次 grab
  **之前**只多了两次 `focusWidget()` 读取，不可能改变像素 ⇒ ④ 的稳定是**环境漂移**，不是 `repaint()`
  的功劳。⑤ 作为控制组证伪了「repaint 是解法」这条我差点写进记录的归因。

**(c) 机制在 harness 里判定**（`/tmp/qtb_probe/focus3.cc`；两个起点靠 `WA_ShowWithoutActivating`
区分，运行期 `setFocus()` 在 cocoa 上不可靠 —— 用它的 `focus2.cc` 三种模式都收敛成 1 种，是假阴性）：

```
mode=none     activate=1 focus=QLineEdit sha=ebb423075e76
mode=none     activate=0 focus=-         sha=aac40cb2d8aa     ← 瞬态漏进像素：2 种
mode=refocus  activate=1 focus=QLineEdit sha=2b8bda37c5b7
mode=refocus  activate=0 focus=-         sha=aac40cb2d8aa     ← 旧写法：仍 2 种
mode=clear    activate=1 focus=-         sha=aac40cb2d8aa
mode=clear    activate=0 focus=-         sha=aac40cb2d8aa     ← 清焦点：收敛成 1 种
```

⇒ 修法：grab 前把本窗口的焦点**清掉**，抓完再还回去（截图不该改变正在运行的应用）。
`setCursorFlashTime(0)` 随之多余（无焦点就不画光标），已删。

**(d) 旧 harness `noise.cc` 当轮输出**（说明它当时为什么没测出来）：

```
[setup] activeWindow=1 focus=QLineEdit cursorFlash=1000
[原样] 闪烁相位 g0/g1 相同 | hover 前/后 相同 | 冻结后 vs 脏帧 相同 | 冻结后 vs 干净帧 相同
[冻结] 闪烁相位 g0/g1 相同 | hover 前/后 相同 | 冻结后 vs 脏帧 **不同** | 冻结后 vs 干净帧 **不同**
```

「闪烁相位 g0/g1 相同」「hover 前/后 相同」⇒ 只置 `WA_UnderMouse` 不改像素（要真实鼠标事件才生效），
所以 §1 里「hover 描边是噪声源」当时也没有实测支撑。`WA_UnderMouse` 那一行清理仍保留：它清的是
Fusion 画 mouseOver 读的属性，属同一类外部状态，成本 1 行，本机无法按需复现（如实记为未证）。

### 4. 第三类噪声：**QLineEdit 清除按钮的淡入淡出** ⇒ `--shot` 的泵帧数是配方的一部分（Session 25 续）

第 ④ 步原本假设「120 帧泵是给 WebView2 异步初始化留时间」，想按后端收口成
`webViewSupportsJs() ? 120 : 3`。**实测把这个假设否掉了**：

| 配置（都是全清重建） | 泵帧 | cocoa `--shot` ×8 |
|---|---|---|
| WKWebView 开 | 120（无条件） | **1 种** `2728fb1a6a06…` |
| WKWebView 开 | 120（写成 `supportsJs` 分支，实际仍走 120） | **1 种**，同上逐字节 |
| WKWebView 关（QTextBrowser） | 120 | **1 种** `a70cd7ae05b6…`（24.8 控制组） |
| WKWebView 关 | **3** | **4 种**：8 次里 `2fec2364…`×1 / `4393a1b2…`×2 / `a2dd7139…`×3 / `db380cff…`×2 |

差异定位（PIL `ImageChops.difference().getbbox()`，四张互两两比）：**全部**落在
`(622,66)-(636,80)` 一个 14×14 的格里，其余像素零差异。放大看图 = `name_input` 右侧那个
**清除按钮（灰底 ✕ 圆圈）**，四种帧的区别是它的**不透明度**（同一格主灰阶实测
`#bababa` / `#ededed` / `#eeeeee` / `#dcdcdc`）—— Qt 的 `QLineEdit` 清除按钮带淡入淡出动画，
3 帧时抓到的是动画中途，落在哪一帧取决于墙钟相位。

⇒ 两条结论：
1. **泵帧数不能按 webview 后端收口**。它管的是「瞬态动画停下来」，与后端无关；原生后端的异步
   初始化只是第二个理由。所以 `examples/hello/src/main.php` 的 `--shot` 保持无条件 120 帧，
   注释改写成实测出来的真原因（原来那句 `// TEMP: 给 WebView2 异步初始化留时间` 是错归因）。

   **【本条结论已被 §5 取代】**「保持无条件 120 帧」是当时唯一可执行的止血办法，不是终解。
   真解是让 `snapshot()` 自己把动画推到终点，帧数与墙钟相位脱钩 ⇒ 现在配方是 **3 帧**（见 §5）。
   保留原文是因为它记录了「按后端收口」这个错误假设被否掉的过程。
2. **文档与脚手架原先教的 `runFrames(3)` 是一条会坏基线的配方** —— 已把
   `docs/src/{,zh/}advanced/headless.md`、`bin/qtphp` 的 `qtphp new` 模板、
   `.ohmyagent/skills/typephp-qt-app/references/{build-and-deploy,bridge-pattern}.md` 全部改成
   「泵到动画停（120 帧）」，并在 headless 页新增「把 PNG 变成 CI 基线」一节：三条前置条件
   （无墙钟内容 / 泵到动画停 / 抓前清焦点）+ 收敛判据（×8 必须 1 种）+ 当前三条基线值 +
   「哈希只能单向证明没变」。（配方现更新为 3 帧 + `snapshot()` 冻结，见 §5；文档已同步。）

### 5. 真解：`snapshot()` 把瞬态动画推到终点 ⇒ 泵帧数从 120 降回 3（Session 25 续，更正 §4-1）

§4 末尾记的「未做」现在做完了。过程与实测数据：

**(a) Qt6 API 事实（编译期被纠正，不是查文档得来的）**
- `Qt::UIEffect` 枚举只有 `UI_AnimateMenu / UI_FadeMenu / UI_AnimateCombo / UI_AnimateTooltip /
  UI_FadeTooltip / UI_AnimateToolBox` —— **没有**通用的「关一切 UI 动画」开关，§4 里猜的
  `QT_UI_EFFECT_ANIMATE_UI_CHANGE` 在 Qt6 不存在（那是 Qt4/5 的东西）。
- `QAbstractAnimation::State` 是 `{Stopped, Paused, Running}`，**没有** `NotRunning`（第一次照 Qt4 记忆写就编译失败）。
- `jumpToEnd()` 只在 `QPropertyAnimation` 上；`QAbstractAnimation` 的通用等价写法是
  `setCurrentTime(totalDuration())`，让动画自己把终值发出去。
- `QLineEdit` 清除按钮那条动画是 parented 到该 line edit 的 `QTimeLine` ⇒
  `window_->findChildren<QAbstractAnimation *>()` 能抓到，不需要专有开关。

**(b) 改法**：`snapshot()` 在 grab 前遍历 `findChildren<QAbstractAnimation *>()`，对 state ≠ `Stopped`
的动画 `setCurrentTime(totalDuration())` + `stop()`（抓完不还原 —— 动画已经到终态，还原没有语义）。
顺序：清焦点 → 清 `WA_UnderMouse` → 冻结动画 → `grab()` → 还焦点。
注意这条淡入淡出**是上一句 `clearFocus()` 自己触发的**（焦点丢了才淡出清除按钮），
所以「清焦点」和「不稳定」不是两件事，而是同一根链：清了焦点才有动画，有动画就多帧才稳。

**(c) 阶梯测量（每步只动一个变量）**

| 配置（都是全清重建） | 冻结动画 | 泵帧 | cocoa `--shot` ×8 | offscreen ×8 |
|---|---|---|---|---|
| WK 开 | ✗ | 120 | 1 种 `2728fb1a6a06…`（= §4 旧基线） | — |
| WK 开 | ✓ | 120 | **1 种 `2728fb1a6a06…`**（与首行**同值**，但首行是 24.8/24.9 那个时间窗测的 ⇒ 「120 帧时冻结是 no-op」是跨窗对比推出的结论，不是同窗并排测的） | — |
| WK 开 | ✓ | **3** | **1 种 `2728fb1a6a06…`**（8/8） | **1 种 `6c4832a6b738…`**（8/8） |
| WK 开 | **✗**（控制组 A） | 3 | **4 种**：`a70ad48f…`×1 / `1b586809…`×3 / `e8f85628…`×3 / `4b9d1455…`×1 | — |
| WK 关（QTextBrowser） | ✓ | 3 | **1 种 `a70cd7ae05b6…`**（8/8，与 24.8 控制组同值） | — |

⇒ 三条判据全绿：**冻结承重**（控制组 A 在同一时间窗、同一配置下跑出 4 种哈希，不是环境漂移）；
**基线未漂移**（新配方 cocoa/offscreen 的值与旧 120 帧配方逐字节相同，文档三条基线值**无需改数**）；
**后端独立性成立**（关开关在 3 帧 + 冻结下仍收敛，说明 §4 里「3 帧不收敛」确实只由动画引起，与后端无关）。

尺子口径说明（避免把跨窗比对当同窗实测）：表里「同值 / 未漂移」那几处是**与 24.8/24.9 那两个时间窗记录的哈希串
比对**，不是同一批跑出来的。按 F28 §1 认定的方向读法 —— 相同 ⇒ 像素没变 —— 这个比对是有效的（外部噪声只会让
哈希*不同*）；而控制组 A/B 是本轮**同一时间窗内**跑的，因此「冻结承重」这条不依赖跨窗假设。

**(d) 代价与副作用**：`--shot` 墙钟本轮实测 **445 ms**（120 帧配方下早前记的是「3 秒出 760×720 PNG」，
见本文件 F26 与 progress 的 24.x 验收表；两者不是同一时间窗背靠背测的，只作量级参考）。
两平台 `--selftest` 25/25、`--difftest` 20/20 全 rc=0；
`php bin/qtphp test` **124 tests / 209 断言**、`lint` 契约一致；脚手架 `qtphp new demo` → `build .` rc=0
（25,317,288 B）→ selftest passed → `--shot` ×8 distinct=1。
唯一语义变化：截图里的动画会停在**终点值**而非初始值 —— 对基线无影响（本来 120 帧也是终点），
但如果将来有人想验「动画中间态」，`--shot` 这条路拿不到，得自己构造帧。

---

## F29. mac 上「走 Qt WebView 类」的真实代价：那个类在 mac 上就是 WKWebView，而 brew 打包强拖 QtWebEngine（Session 25）

用户方向：mac 侧别再停在 QTextBrowser 的 HTML 子集，改用 Qt 的 WebView。先把三条路线的**事实**量清楚，
再决定动不动安装（启动卷只剩 **2.5 Gi**）。

### 1. Qt WebView 模块在 macOS 上用的就是系统 WebView（官方文档原文口径）
`doc.qt.io/qt-6/qtwebview-index.html`：Android/iOS/Windows/Linux/macOS 都支持，其中
**「On macOS, the system web view is used in the same manner as iOS」**；Linux 才依赖 Qt WebEngine，
Windows 可选 Qt WebEngine 或 WebView2。⇒ 在 mac 上装 Qt WebView 拿到的东西 = WKWebView 的一层 QML 壳
（`QWebView` 是 `QQuickItem`，只有 QML 类型，没有 QtWidgets 类 ⇒ 还要 qtdeclarative/QtQuick）。

### 2. 但 Homebrew 的 `qtwebview` 依赖表里写着 qtwebengine（实测）
```
qtwebview    6.11.2  deps= qtbase, qtdeclarative, qtwebengine, qtpositioning, qtwebchannel
qtdeclarative 6.11.2 deps= qtbase, qtsvg
qtwebengine  6.11.2  deps= libpng, qtbase, qtdeclarative, qtpositioning, qtwebchannel, qttools
```
本机只装了 `qtbase`（`ls -d /opt/homebrew/opt/qt*` 只有 `qtbase`），而仓库既有决策就是**故意不装**
`qtwebengine`（`task_plan.md:415`、F 里记的「~300MB → 解压 1.5–2GB」）。2.5 Gi 的余量装不下，
且**装它换来的仍然只是 WKWebView 的壳** ⇒ 这条路线在 mac 上没有净收益。
（bottle JSON 里没有 size 字段，`brew info --json=v2` 只能拿到 url/sha256，所以体积沿用仓库既有实测口径。）

### 3. 直接用系统 WKWebView 的能力实测：JS / 远程 https / data: 三条全过（零安装）
`/tmp/wkv_probe/wkprobe.mm`（纯 AppKit + WebKit，不依赖 Qt；`clang++ -fobjc-arc -framework Cocoa -framework WebKit`）。
判据不看像素，用 `evaluateJavaScript:"document.title"` 读回 —— **只有真引擎会执行脚本**：

```
webkit_version=21624
[finish/0] OK   title='JS-RAN-3'        ← <script>document.title='JS-RAN-'+(1+2)</script> 真的跑了
[finish/1] OK   title='Example Domain'  ← https://example.com 远程加载成功
[finish/1] OK   title='DATA-OK'         ← data:text/html,... 也吃
pending=0
run_rc=0
```

（三行 tag 都是 `finish/1` 是探针把计数写成了 `NSMutableSet` 装同一个 `@"x"` 去重了，
不影响结论 —— 三条结果本身按 title 可区分。）

⇒ F27 里 QTextBrowser 后端的那三个缺口（无 JS、远程 `url` 不支持、`data:` 不支持）**在 mac 上不是能力上限**，
系统里就有一个完整引擎，且不需要装任何东西。

### 4. 工程可行性：tpc 原生支持 `.mm`，但本仓的构建接线只认 `.cc`
- `typephp-compiler/src/Translator.php:2313-2323`：`'m' => 'objective-c'`、`'mm' => 'objective-c++'`；
  `FileScanner::isNativeSourceFile('/path/to/file.mm')` 为真（`phpunit/src/FileScannerTest.php:44`）；
  `src/Build/NativeCommandOptionsTrait.php:134` 对 objective-c++ 单独给编译选项；
  仓库里还有现成范例 `examples/apple-native/`（`.mm` 桥 + `-fobjc-arc`，README:201 明说「`.mm` 桥刻意薄」）。
- 本仓缺口：`bin/qtphp:1995` 是 `glob(rootDir . '/cpp-src/*.cc')`，脚手架文件清单写死在 `bin/qtphp:448-450`
  也只列 `.cc` ⇒ 加 `.mm` 后端要同时改这两处（以及 mac 入口的 `link-libs` 加 `-framework WebKit`）。
- 未验的部分（下一步）：把 WKWebView 作为**原生子 NSView** 挂进 `QWidget::winId()` 拿到的 NSView 并同步
  frame —— 这是 Qt WebEngine 在 mac 上的同款做法，但本仓还没跑通过；另外原生视图会浮在 Qt 绘制之上，
  与相邻 Qt 控件的层叠关系需在真窗口里确认。

### 5. 嵌入 spike 跑通：合成成立、缩放跟随成立，但 `grab()` 里是个空洞（Session 25 续）
探针 `/tmp/wkv_probe/spike.mm` + `spike2.mm`（工作区外，纯 AppKit + brew Qt6，不碰本仓）。
窗口结构 `QVBoxLayout[QLabel "QT-LABEL-ABOVE" / host QWidget(洋红 #ff00ff) / QLabel "QT-LABEL-BELOW"]`，
挂载方式就三行：

```objc
NSView *parent = (__bridge NSView *)(void *)host->winId();
auto *web = [[WKWebView alloc] initWithFrame:parent.bounds];
web.autoresizingMask = NSViewWidthSizable | NSViewHeightSizable;
[parent addSubview:web];
```

ARC 下 `WId → NSView *` **必须**走 `(__bridge NSView *)(void *)wid`（直接 `reinterpret_cast` 编译失败），
`printf` 的 `%p` 参数用 `(__bridge void *)parent`。

两条判据 + 两条附带风险，全部实测（800×600 窗口，宿主 bounds 760×510）：

| 判据 | 手段 | 结果（verbatim / 采样值） |
|------|------|--------------------------|
| 引擎在 Qt 宿主里活着 | `evaluateJavaScript:"document.title"` | `[spike] js_title=WK-JS-42` |
| **真的合成进 Qt 窗口**（不是只挂在层级里） | `screencapture -x -R<global,rect>` 后读图 | `spike-window.png` 中心 `#88bcdd`（网页蓝 `#7ec8e3` 经色彩配置转换后的值），洋红**一点没露** ⇒ frame/坐标换算正确，非 flipped 父视图的原点问题在「贴满宿主」时被自然规避 |
| 缩放跟随 | `window.resize(420,300)` 后打印 frame + 再抓一次 | `host.bounds=380x210 web.frame=0,0,380,210`，`spike2-resized.png` 中心仍 `#88bcdd` ⇒ `autoresizingMask` 足够，不需要 Qt 侧同步几何 |
| **`--shot` 取帧路径** | 同一时刻 `window.grab()` / `host->grab()` 存 PNG | `grab-window.png` 中心 `#ff00ff`、`grab-host.png` 两点均 `#ff00ff` ⇒ **原生子 view 不进 `QWidget::grab()`**，抓出来是宿主底色空洞 |

第四行与 F27 里已记的「`--shot` 截不到 WebView2（已知限制，非缺陷）」是**同一根因的第二次确认**
（两者都是独立原生表面，Qt 的绘制引擎看不见它们），差别只在于：mac 当前默认后端是 QTextBrowser，
**它是 Qt 自己画的、能截到**。所以把 mac 默认后端换成 WKWebView 会有一次**基线回退**：
`--shot` 的 mac 帧里 webview 区域从「有内容」变成「空洞」，刚锁定的 `a70cd7ae05b6…` 必然改变。
可接受的前提是**改用非像素手段验 webview**——像本 spike 这样用 `evaluateJavaScript` 读回页面里的值，
这比像素比对更直接（像素只能证明"画了"，读回属性能证明"页面真的加载并执行了"）。
备选（未做）：`WKWebView takeSnapshotWithConfiguration:` 能把页面渲成 `NSImage`，理论上可合成回
snapshot，但 `QtWindowBox::snapshot()` 是同步接口、要接异步回调，成本高，先记着不动。

### 6. 第三后端已实现并真机验收：事件是对的，像素那条路必须换尺子（Session 25 续）

`cpp-src/qt_webview_wk.mm`（约 235 行）+ mac 入口/脚手架接线完成后，**验收用的不是截图，是
`qt_window_poll_event` 分发出来的事件** —— 原生后端画在 Qt 之外，像素无法证明任何事（§5 第四行），
而 `title` 只有 JS 真跑了才会出现，比像素强。全程临时探针（`[wkev]` 打印）验完即删。

| 契约项 | 事件/回读 | 实测（verbatim） |
|---|---|---|
| `html` + JS | `title` | `[wkev] navigating value=about:blank` → `[wkev] loaded value=about:blank` → `[wkev] title value=WK-JS-42`（`loadHTMLString` 的页面 URL 就是 `about:blank`） |
| 远程 `url` | `navigating`/`title`/`loaded` | `navigating value=https://example.com/` → `title value=Example Domain` → `loaded value=https://example.com/` |
| `zoom` | `pageZoom` 回读 | `flushPending -> pageZoom=2.50`、`didFinish -> pageZoom=2.50`（对照组 1.00）⇒ 首次设置和跨导航都生效 |
| 链接点击 | `link` 且**不跳转** | `decidePolicy type=0` → `[wkev] link value=https://link-probe.invalid/x`，之后无 `navigating` |

**ObjC++ 的四条硬约束**（第一次编译一次吐 12 条错换来的，写下来免得下次再撞）：
① `@interface`/`@implementation` **不能**进匿名 namespace（"Objective-C declarations may only appear
in global scope"）⇒ ObjC 类放全局，C++ 宿主类前置声明解环；② ObjC ivar **不能**在 C++ 上下文用 `->`
取 ⇒ handler 只持 `WKWebViewWidget *`，回调宿主的 `report*` 成员函数，不在 handler 里堆状态；
③ ivar 取名要避开 POSIX（曾叫 `link` 直接撞 `::link`，报的是 `-Wpointer-bool-conversion` + "no matching
function"，完全看不出根因）；④ Qt6 的 `QString::toNSString()` **无参**，`QUrl` **没有** `toNSString`
⇒ URL 走 `[NSURL URLWithString:toNSString(qurl.toString())]`。ARC 下 ObjC 指针**可以**做 C++ 类成员
（`WKWebView *web_ = nil;`），`WId → NSView*` 仍必须 `(__bridge NSView *)(void *)winId()`。

**链接**：`.mm` 里直接使 NSString/NSURL/NSView/KVO ⇒ 必须显式 `-framework Foundation -framework AppKit
-framework WebKit`。缺了报 `Undefined symbols: _OBJC_CLASS_$_NSString … ___CFConstantStringClassReference`。
**QtGui 本身就依赖 AppKit，但 ld 不会替我们的目标文件去它的依赖里找符号** —— 别指望传递。

**tpc 侧**：`.mm` 走 `'mm'=>'objective-c++'`（`Translator.php:2313-2323`）、`FileScanner::OBJCXX_EXT=['mm']`、
`NativeCommandOptionsTrait.php:129-143` 让 objective-c++ 复用 `cpp_std` + `cxxflags` ⇒ 实测命令行是
`clang++ -c -x objective-c++ … -std=c++20 … -DQT_WEBVIEW_WK -fobjc-arc`，**不需要额外的 ObjC 专用开关字段**。
但 `ProjectYamlLoader.php:83-97` 的 `mergeConfig` 对 **list 是整体替换**，所以 mac 入口必须把整份 `sources`
重写一遍才能加进那一个 `.mm`（`include` 深合并救不了顶层 list）。

**一条新引入的真缺陷（已修）**：`QT_QPA_PLATFORM=offscreen --shot` **SIGSEGV，rc=139，PNG 不生成**。
根因是 offscreen 平台的 `winId()` 给的不是 NSView，拿它发 ObjC 消息直接崩；修法不是加 `isKindOfClass:`
兜底，而是在 `attach()` 首行按平台闸门 `QGuiApplication::platformName() != "cocoa" ⇒ return` ——
无头平台上控件保持空白，这**与「原生子 view 不进 grab()」的产物一致**，帧仍是确定的，语义没有说谎。

**基线与归因（控制组）**：开/关 `-DQT_WEBVIEW_WK` 各全清重建一遍。
关闭时 cocoa 帧与 24.5 锁定的旧基线 `a70cd7ae05b6f3099ee799204547ba040d35be4f93cc3bc8af4494e81a7d9bbc`
**逐字节相同**（证明改动没有被无关因素污染，也证明 §5 预言的「换默认后端必改基线」只在开启时发生）；
开启时 cocoa ×8 → `2728fb1a6a0652625483166097bda44a23f051f48e629b7acf9dac793ce1e4d7`、
offscreen ×8 → `6c4832a6b738145ef902a131f98ff832e769d2eb5aa1485b2295502ec9b0111b`（旧 offscreen 值是
`4b777e2e…`）。开/关像素差 **129,830 px = 23.73%**，差异包围盒 **x[33..726] y[454..664]** 完整落在 WebView
分组内（分组标题文字 + 空白内部），**y=454 以上零差异** ⇒ 其余控件未被触碰。
selftest 25/25（cocoa 与 offscreen 各一遍）、difftest 20/20、`php bin/qtphp test` 123 tests / 203 assertions、
`php bin/qtphp lint` 契约一致。脚手架 `qtphp new demo` → 生成的 mac yml 含 `.mm`/`-DQT_WEBVIEW_WK`/
`-framework,WebKit` → `build .` rc=0（25,316,424 B）→ `--selftest` passed。

**顺带修正的假话**：`webViewSupportsJs()` 在 C++ 侧是「两个原生后端都支持 JS」，但 `FakeBridge` 之前写死
`=== 'webview2'` —— 测试替身会把 `wkwebview` 报成不支持 JS，正好骗过应用侧的能力判断（与它自己注释的意图相反）。
已改成 `in_array(… , ['webview2','wkwebview'], true)` 并补断言。同类口径问题还牵连
`php-src/qt.stub.php`、`docs/src/{,zh/}reference/api.md`、`docs/src/{,zh/}guide/qt-setup.md` 的「两种后端」表述，
本次一并改三。

## F30. WebView2 分支 W1–W5 真机验证（Session 26，Windows）

Session 25 在 macOS 上只能静态审查 W1–W5。本轮在 **Windows 真机**上逐条取证，结论与推断
**一半相符、一半不符** —— 不符的两条正好说明「读代码推断」的边界在哪。

### 取证方法
写了一个临时探针应用 `examples/wvprobe`（验完即删，未入库），四种模式：
- `--w12` 打 `navigating`/`loaded`/`title` 事件的 value
- `--w3c`/`--w3d` 并发建 4 个带 webview 的窗口，用
  `Get-CimInstance Win32_Process` 按 **`--user-data-dir` 路径**过滤 `msedgewebview2.exe` 计数
- `--w4` 异步初始化未完成就 `destroy()`，反复 20 轮
- `--hold` **阳性对照**：确认过滤/采样方式本身有效（关键，否则「数到 0」可能只是没测到）

### 逐条结论

| # | 静态审查的推断 | 真机实测 | 判定 |
|---|---|---|---|
| W1 | `loaded` 的 value 恒为空串 + 每次导航泄漏一个 CoTaskMem 串 | `evt=loaded value=[]` 两次都空，而 `title` 正确报出 `Page A`/`Page B`、`success=true` | ✅ **成立** |
| W2 | `navigating` 取的是导航**前**的源 | 第一跳报 `about:blank`、第二跳报 `a.html`（目标其实是 `b.html`）—— 永远慢一拍 | ✅ **成立** |
| W3 | 不调 `Controller::Close()` ⇒ 反复创建会攒浏览器子进程 | 4 个并发：峰值 **9** 个进程；销毁 3 个后 **6** 个（= 单个 webview 的代价）；全销毁后 **0** | ❌ **不成立**（见下） |
| W4 | 三级 COM 回调裸捕 `this`，无生命周期保护 | 20 轮「建完立刻销毁」：40 次回调到达、20 次析构、**0 次 AFTER-DESTROY**，无崩溃 | ⚠️ **真实风险但窗口极窄** |
| W5 | `reload`/`goBack`/`goForward` 在 PHP 侧无入口 = 死代码 | 确认无任何入口 | ✅ **成立** |

### W3 为什么不成立（值得记住）
`WebView2Widget` 确实没有析构、从不调 `ICoreWebView2Controller::Close()`，但实测**不泄漏**：
`QtWindowBox::cleanup()`（`qt_bridge.cc:215`）会 `delete window_`，宿主窗口一销毁，
WebView2 的控制器跟着宿主 HWND 一起被回收。**串行建/销时进程数稳定在 6，峰值从不增长。**

教训：**「不调 Close 就不释放」是文档级的约定，不等于本工程里真会泄漏** ——
宿主生命周期把这条兜住了。审查只能指出「依赖了未承诺的行为」，不能断言「会泄漏」。

### W4 为什么只是「窄窗口风险」
回调能赢过析构，是因为**环境是进程级单例**：第一个 webview 建好后 `host.ready=true`，
之后每个控件走的都是同步路径（`onEnv(S_OK, host->env)` → `CreateCoreWebView2Controller`
→ 回调），而 `render()` 是在帧内同步建树的，帧内 Qt 的消息泵会把回调送达。
所以「建完立刻销毁」这个窗口被压得很小 —— 20 轮全过。

但**代码层面的缺陷是真的**：`cleanup()` 确实 `delete window_`，回调确实裸捕 `this`。
窗口窄 ≠ 不存在，所以照修。

### 修复与复验

| # | 修法 | 复验证据 |
|---|---|---|
| W1 | `takeCoTaskMemString(uri)` 真正取 URL 并释放 | `evt=loaded value=[file:///D:/tmp/wv/a.html]`、`…/b.html` |
| W2 | 改用 `args->get_Uri()`（本次目标） | `navigating` 报 `a.html` → `b.html`（与目标一致） |
| W3 | 析构里显式 `controller_->Close()` | 峰值 9 → 全销毁 0，未回归 |
| W4 | 加 `std::shared_ptr<std::atomic<bool>> alive_`，回调先查再碰成员 | 20 轮竞态无崩溃、无残留进程 |
| W5 | 接进 `qt_window_patch` 的 `call` 表 + `qtWebViewCall` 分发；PHP 侧加三个薄封装 | 真机：`goBack`→a.html、`goForward`→b.html、`reload`→重新导航 |

**顺带**：WKWebView 后端本来就有 `reload`/`goBack`/`goForward`（`WKNavigation`），
所以 `qtWebViewCall` 也给它接了实现（`respondsToSelector` 挡 macOS 11 以下的旧系统），
否则这个新 API 在 macOS 上会静默失效。三个后端的支持矩阵已写进文档。

### 另一条真机确认
W1 的泄漏面比审查说的更大：`add_NavigationCompleted` 里 `get_Source` 取完既不释放也不用。
修完复核了文件里**所有** `LPWSTR` 取用点，现在每一个都经 `takeCoTaskMemString` 释放。

---

## F31. mac 打包产物的真实体积、webview 是否在 bundle 里可用，以及两处打包缺陷（Session 26）

问题：`qtphp package` 出来的独立 `.app` 到底多大？打包后 webview 还能不能用？

### 1. 体积：`99.8 MB` 是虚高的，真实 **83.6 MiB**
`php bin/qtphp package examples/hello` 打印 `打包完成: …Hello.app (99.8 MB)`，但同一棵树：

| 量法 | 值 |
|---|---|
| `dirSize()`（工具自己打印的） | 99.8 MiB |
| 跳过符号链接后的真实逻辑字节 | **83.6 MiB**（87,612,051 B，54 个文件） |
| `du -sh`（磁盘块） | 84 M |
| `ditto -c -k` 压成 zip（分发体积） | **29.3 MiB** |

⇒ 缺陷 **P1（已修，28.1）**：`bin/qtphp` 的 `dirSize()` 用 `RecursiveIteratorIterator` + `$file->isFile()`，
而 `SplFileInfo::isFile()/getSize()` **跟随符号链接** ⇒ `.framework` 里 12 个 alias 条目按目标文件重复计一次，
虚高 ~19%。修法：`if ($file->isLink() || !$file->isFile()) continue;` ⇒ 现在打印 `83.6 MB`。
（测量过程本身也翻了一次车：第一次做对照实验时把「跳过链接」那侧写成了累加 8 字节，
于是两种口径都得到 83.6 ⇒ 差点结论成「不是符号链接的问题」。发现两个数**完全相同**就该怀疑尺子，
改成「真实字节」与「工具原逻辑」两口径并排才算出 99.8 vs 83.6。）

分解（真实字节）：`libicudata.78.dylib` **31.66 MiB（占 38%）** > `MacOS/hello` 21.55 MiB >
QtGui 5.61 + QtWidgets 5.23 + QtCore 4.80 = 15.6 MiB > icu i18n/uc 4.2 MiB >
brew 侧传递依赖（glib/iconv/harfbuzz/zstd/onig/gmp/mpfr…）约 5 MiB > PlugIns 1.6 MiB（cocoa 0.91 + offscreen）。
另：`macdeployqt` 会**顺手 strip** 产物二进制 —— `build/hello` 25,564,904 B → bundle 内 22,594,160 B
（`nsyms` 121,795 → 6,890、`__LINKEDIT` 3.59 → 0.62 MB，`__text` 不变）⇒ 交付物不含开发态符号表。
PHP/PHPX 是静态链进那 21.55 MiB 的（`--enable-embed=static`），bundle 里没有 `libphp.dylib`。

### 2. webview 在打包产物里可用（三条独立证据）
1. 从 `Contents/MacOS/hello --shot` 出图 sha256 = `2728fb1a6a06…`，与开发态 cocoa 基线**逐字节相同**；
   裁出 WebView 分组标题读图 = 「WebView（backend=wkwebview，js=支持）」。
2. `otool -L` 产物：Qt 三个 framework 全部改写成 `@executable_path/../Frameworks/…`，WebKit 是
   `/System/Library/Frameworks/WebKit.framework/…/WebKit`（系统框架，**故意不进 bundle**）
   ⇒ 目标机不需要装 brew Qt；也没有任何一条 NEEDED 落在 `/opt/homebrew` 或 `~/.typephp`
   （但 `LC_RPATH` 里仍留着这两个开发机路径，属无害残留，见下 P2 备注）。
3. `open Hello.app` 后新起 `com.apple.WebKit.WebContent.xpc` 进程（pid 13267，启动时刻紧跟 hello 的 13263）
   ⇒ WKWebView 在 bundle 上下文（ad-hoc 签名 + bundle 内 Qt）里**真的拉起了网页内核**，不只是链接通过。
   注意：XPC 的父进程是 launchd（ppid=1），所以「按 ppid 找子进程」这条判据不成立，必须用**启动前后 PID 集合做差**。

bundle 内 `--selftest` 在 cocoa 与 `QT_QPA_PLATFORM=offscreen` 下均 25/25、rc=0。

### 3. 缺陷 P2：打包后 assets 路径断（托盘图标丢）
`packageAppBundle` 把 assets 复制到 `Contents/Resources/assets`，而 `qt_common.h:114 qtResolvePath()`
的解析顺序是 **exe 目录 → cwd**（`QCoreApplication::applicationDirPath()` 再 `QDir::currentPath()`），
**从不看 bundle 的 Resources**。于是打包后 `main.php:54` 的 `'icon' => 'assets/icon.png'` 解析不到，
启动即 `tray icon could not be loaded: assets/icon.png`。
判别实验（证明是 exe 目录而不是 cwd）：开发态二进制从 `cwd=/tmp` 跑 `--shot` **无警告**、哈希与
从 `examples/hello` 跑时相同（`2728fb1a…`）⇒ 它命中了 `build/assets/`（build 会把 assets 拷到 exe 旁边）。
窗口像素不受影响（托盘不在 `grab()` 范围内）⇒ 所以这条**不会**被 `--shot` 基线抓到，只能靠日志/实跑。
**已按 ② 修掉（28.1）**：`qtResolvePath()` 的顺序变成
**exe 目录 → bundle 的 `Contents/Resources` → cwd**，且第二层只在「exe 目录的父目录名为 `Contents`」时启用
—— 否则 Linux/Windows 上项目根恰好有个 `Resources/` 就会被抢先命中。
证据：修后从 `cwd=/tmp` 与 `cwd=/`（等价 `open` 起的进程）跑 bundle 二进制均无警告、`--shot` 仍是 `2728fb1a…`；
**负控制**把 `Contents/Resources/assets` 改名后警告立刻回来 ⇒ 生效的正是新加那层。
文档三处（中英 `packaging.md`、`dialogs.md`、README）同步。

（P2 备注：`LC_RPATH` 残留 `/opt/homebrew/opt/libiconv/lib` 与 `~/.typephp/php-builder/…/install/lib`
不影响自包含性 —— 没有任何 NEEDED 走 `@rpath`；但它是「产物里带着开发机路径」的信息泄漏，
`install_name_tool -delete_rpath` 可清。**未验**。）

---

## F32. 打包验收缺一条「产物内 stderr 无告警」的自动化判据（Session 26）

问题：F31 那条托盘缺陷是怎么漏下来的？—— 不是没信号，是**信号只出现在人眼会跳过的地方**。

### 判据缺口
| 现有判据 | 覆盖什么 | 漏掉什么 |
|---|---|---|
| `--shot` 出图 sha256 | 窗口像素 | 托盘、任何其他不在 `QWidget::grab()` 范围内的资源 |
| `otool -L` / 依赖闭包 | 链接自包含性 | 运行时才解析的资源路径 |
| `--selftest` rc | 事件分发 | **失败也返回 0**（模板里只 `echo "selftest failed"`） |
| codesign / plutil | 签名与 plist | 一切运行时行为 |

⇒ `assets/icon.png` 解析不到时，package 仍打印「打包完成」、selftest 仍 `passed`、哈希仍与基线逐字节相同。

### 实测：健康产物的 stderr 里本来就有 warning
先量真实输出再定规则（否则白名单是猜的）。bundle 内 `QT_QPA_PLATFORM=offscreen --selftest`
配 `QT_MESSAGE_PATTERN='%{type}|%{category}|%{message}'`：

```
warning|qt.qpa.fonts|Populating font family aliases took 70 ms. …"Sans Serif"…
warning|default|This plugin does not support propagateSizeHints()
```

两条都是 Qt 平台插件自身的无害告知（字体别名表填充耗时跟开发机速度有关；后者是 offscreen 的能力声明），
**按 type 一律拦会误报**。所以规则是：`warning|critical|crash|fatal` 前缀 → 拦，
但白名单掉 `|qt.qpa.fonts|` 与 `This plugin does not support `。

### 落地的判据与鉴别力
`verifyAppBundle()` 新增：bundle 内真跑一遍 offscreen `--selftest`，要求
① 输出含 `selftest passed`（rc 只兜底，因为 selftest 失败仍返 0）② 过滤后无告警行。

**负控制**（藏掉 `examples/hello/assets/icon.png` 再 package）：
```
[INFO] 在无 GUI 会话下真跑产物（offscreen --selftest），并检查其告警...
[ERROR] 产物启动时告警，说明有资源或能力没就绪：
  - warning|default|tray icon could not be loaded: assets/icon.png
rc=1
```
还原后 `rc=0`、打印 83.6 MB。两条对照合起来才说明：判据既有鉴别力（能抓到真实缺失），
又没把无害噪声算成失败（正例本身带 2 条 warning 仍通过）。

踩过的坑：第一次测负控制用 `php … | tail -8` 再读 `${pipestatus[2]}` —— 那是 `tail` 的退出码，
于是「rc=0」差点对 F31 的修复效果下错结论。量退出码时**不要对被测命令加管道**。

### 仍未覆盖
- Linux `verifyLinuxPackage()` 只**打印**无头验收命令、不执行；Windows 侧同理。
- `--selftest` / `--difftest` 失败时进程退出码仍是 0（本判据只在打包路径上用文本兜住）。
- 用户应用自己往 stderr 写的正常日志，目前没有区分「日志」与「告警」的机制（靠 `type` 前缀区分 Qt 日志，非 Qt 输出不参与判定）。

---

## F33. `tpc --nano` 用在 qt 应用上：体积能降到 4.17 MB，但产物起不来（Session 26）

问题（用户提出）：typephp 有 nano 编译模式，走 nano 编出来的 qt 应用多大？

### 1. 先说结论
| 量 | 值 |
|---|---|
| nano 产物（未 strip） | **4,370,536 B（4.17 MiB）** |
| nano 产物（`strip -u -r`） | 3,639,504 B（3.47 MiB） |
| 普通模式同一项目 `build/hello` | 25,569,608 B（24.39 MiB） |
| ⇒ 二进制降幅 | **−21.2 MB（约 83%）** |
| `otool -L` | 只有 Qt 三个 framework + Foundation/AppKit/WebKit + libiconv + libc++/libSystem ⇒ **无 libphp、无 phpx.dll**，源码内联这件事是真的 |

**但产物运行 rc=1，一句 `Unable to start PHP Nano extensions` 就退出** ⇒ 现阶段 nano 不能用于交付。
体积数字是「能编出来」的体积，不是「能发货」的体积。

> **本节两条结论后来都被推翻/更新（见 F35 §5）**：① 「起不来」已被上游 codegen 补丁修好并在本机真机验证
> （deps 在 nano 下降为 `ZEND_MOD_OPTIONAL`，`--selftest`/`--difftest`/`--shot` 全过、出图与 embed 模式逐字节同）；
> ② 下面那两个体积数字**可复现**（`-O2` 重编得到逐字节等值的 4,370,536 / 3,639,504），但本节**漏记了 `-O2`**
> ⇒ 默认 `-O0` 复现会得到 6,988,648 B；引用时必须带档。

### 2. 走通 nano 编译需要两个前置（都是配置层，不是代码层）
1. **`--nano` 要求 C++17**：`examples/hello/project.yml:24` 是 `cxx-std: c++20`，直接报
   `Fatal error: --nano requires the C++17 language standard`。加 `--cxx-std c++17` 即过（Qt 6.11 本身要 C++17，够用）。
2. **nano 入口 yml 必须去掉 `php-builder:` 段**：留着时 tpc 在 nano 下仍走
   `preparePhpBuilderEnvironment()` 去现编/下载私有 embed 运行时，本机报
   `Unable to prepare self-contained PHP SAPI runtime: … curl: (22) … error: 404`（rc=255）。
   nano 不链 libphp，这一段本就不该参与 —— 要长期支持 nano，得给 mac/linux 各配一份不含它的入口 yml。

编译本身：250 个 TU（php-nano 被裁剪后的 C/C++ + phpx 的 29 个 + 我们 4 个 `.cc/.mm`）全过，
**含 mac 的 WKWebView `.mm` 后端**。我们的 PHP 侧（`QtApp.php`/`WidgetTree.php`/`main.php`/`qt.stub.php`）
也**全部通过 nano 前端能力策略** —— prepare→convert→arginfo→代码生成都没被拒，
说明没有 `eval`/`include`/`require`/匿名类，也没撞禁用函数清单。

### 3. 起不来的根因（读代码定位，非猜测）
```
generated: examples/hello/build/extension-hello_nano.cc:861   ZEND_MOD_REQUIRED("Core")
           （5 行最小 nano 程序同样有这一行：build/extension-nano_min_bin.cc:205）
array    : examples/hello/build/composer_extensions.cpp       standard/date/filter/hash/json/pcre/Reflection/random/SPL + 项目模块
机制     : php-nano/src/extension.cpp:27-36  find_available() 只在传入数组里按 ->name  strcmp
           :66-70  MODULE_DEP_REQUIRED 找不到 ⇒ DependencyState::Invalid
           :133-138 Invalid ⇒ php_nano_startup_extensions() 返回 FAILURE
唯一叫 "Core" 的入口 : php-nano/Zend/zend_builtin_functions.c:52  `static zend_module_entry zend_builtin_module`
                      ⇒ static，结构上不可能被 composer 数组持有
出口     : phpx/src/typephp/typephp_main_nano.cc:40  打印 "Unable to start PHP Nano extensions" 并 return 1
```
⇒ **生成代码声明了一个运行时永远满足不了的依赖**。这解释了 tinygui 侧同一现象，
并且我这边证明它**与 Qt 代码无关**（最小程序同样中招），也**不是 Linux 专属**（macOS arm64 复现）。

**我的补丁实验不成立，如实记**：先删掉生成 `.cc` 里那一行再重编 —— tpc 会**按 target 名重新生成该文件**
（用同名 `-o nano_min_bin` 重编后 `grep -c ZEND_MOD_REQUIRED` 回到 1、产物字节数与原始一致），
所以没做出「只删一行就能跑起来」的正证。真要正证得改 tpc 的 codegen（nano 模式下不写 core 依赖），
按 tinygui 的读法是 4 行级改动 —— 本轮未做。

> **F35 补正**：本节机制后来按官方文档口径重核过，并逐条否证了「phpx 版本旧」「vendor 供给方式不对」
> 「还有第二个不匹配依赖」三种解释；结论是**只有 "Core" 这一处**不可满足，而 "Core" 恰好是 `strlen`
> 所在的扩展 ⇒ 应用侧无法规避，修复只可能在上游。另外「唯一叫 Core 的入口是 static」这一条
> 现在有了更强的独立证据（`zend_builtin_functions.c:52` 的 `static` + php-nano 28 个 component 无一对外的 "Core"）。

### 4. 如果 nano 修好了，打包体积会是什么样（**推算，未实测**）
`.app` 里 app 二进制 21.55 MiB（strip 后）→ 约 3.5 MiB，其余不变 ⇒
真实体积 83.6 MiB → **约 66 MiB**，zip 分发体积同比例下降。
大头仍是 `libicudata.78.dylib` 31.66 MiB 与 Qt 三件套 15.6 MiB（F31）——
**nano 省的是 PHP 那一块，省不动 Qt**。真要再降一个量级，方向是 ICU 与 Qt 模块裁剪，不是 PHP。

### 5. 现场清理
`vendor/swoole/php-nano`（v1.1.0，abi 80600 与 `vendor/swoole/phpx` 的 abi 80600 相符）是手工放进
gitignored 的 `vendor/` 的，**没动 `composer.json`/`composer.lock`**；临时入口 yml、探针程序与
nano 产物均已删除；`examples/hello/build` 用改前备份还原，还原后开发态二进制
`--selftest passed`、`--shot` 哈希仍是 cocoa 基线 `2728fb1a6a065262…`（逐字节相同）。

## F34. 无头验收开关的退出码：失败也返回 0，以及为造负控制挖出的两个 AOT 语义坑（Session 27）

问题（F32 遗留的第一条）：`--selftest` / `--difftest` / `--shot` 失败时进程仍然 rc=0，
于是 CI 只能 grep 输出文本；`verifyAppBundle()` 里那句「rc 只是兜底」就是这个缺口的直接后果。

### 1. `exit(N)` 在 AOT 下能不能落到进程退出码

能，而且**外证已在手**：tpc 自己就是 AOT 产物，`src/CompilerBase.php:4720-4729` 把
`exit`/`die` 降级成 `php::aotExit(N)`；`src/Diagnostics/CliDiagnosticReporter.cc:68` 就是
`php::aotExit(255LL)` —— 本会话早前实测到的「`--nano requires the C++17 language standard` ⇒ rc=255」
正是这条通路跑通的证据。本项目再补直接实测（下表）。

### 2. 实测矩阵（macOS arm64，示例 `examples/hello` + 脚手架新项目）

| 场景 | 改前 rc | 改后 rc | 输出末行 |
|---|---|---|---|
| `--selftest` 全过（offscreen，25 条） | 0 | **0** | `selftest passed` |
| `--selftest` 有 1 条 FAIL（offscreen） | 0 | **1** | `selftest failed: 1` |
| `--difftest` 全过（20 条） | 0 | **0** | `difftest passed` |
| `--difftest` 有 1 条 FAIL | 0 | **1** | `difftest failed: 1` |
| `--shot` 正常出图 | 0 | **0** | 出图，哈希见下 |
| `--shot /no/such/dir/x.png` | 0（且**无任何提示**） | **1** | `snapshot failed: /no/such/dir/x.png` |
| `qtphp run . --shot <坏路径>`（脚手架 demo） | — | **1** | CLI 原样透传，未吞码 |

出图基线未漂移（改的是退出码，不该动像素，实测确认）：cocoa
`2728fb1a6a0652625483166097bda44a23f051f48e629b7acf9dac793ce1e4d7`、offscreen
`6c4832a6b738…` 与 F28/F29 记的值逐字节相同；打包验收正向 rc=0（83.6 MB）、
负向（藏 `assets/icon.png`）rc=1 且仍精确报出 `warning|default|tray icon could not be loaded`。
单测 **127 tests / 215 assertions**（+1 例）、`lint` 契约一致。

### 3. 造负控制时挖出的两个 AOT 语义坑（都是实测，不是读码）

1. **`1 % 0` 在 AOT 产物里不抛 `DivisionByZeroError`。** 我先用 `$state['progress'] - $state['progress']`
   造一个运行期零除数（避开常量折叠），编译通过、运行照旧 `selftest passed`、rc=0 ——
   即解释器的整数除零异常没被降级出来。**负控制必须用显式 `throw new \Exception(...)`**，
   它被 `QtApp::safely()` 的 `\Throwable` 捕获后写进 `lastError`，才真正让用例 FAIL。
   含义：任何「指望 PHP 运行期自动抛」的自检断言在 AOT 下不可信，得自己 throw。
2. **`echo $cond ? "a: ", $x, "\n" : "b\n";` 被 tpc 拒收** —— `Fatal error: Syntax error, unexpected ','`，
   脚手架模板第一版就这样 `build` rc=1。改成「先赋值给变量，再 if/else 两支各自 echo」即通过。
   同一条 `echo` 里不带三元的逗号实参表（模板里原本就有）是能的，所以坑是**三元 + 逗号列表**的组合。

### 4. 顺带修掉的一个诊断缺陷：`lastError` 粘在旧异常上

`QtApp::safely()` 只在异常时写 `lastError`，从不先清。注入一处 handler 抛异常后，`--selftest`
打出 **21 条 FAIL、全部指向同一行** `main.php:17`（其实只有 `step_btn` 那一条真炸）。
示例循环里补了 `clearError()` 也救不了：**真实事件泵**（非 selftest）同样被污染 —— 第一次失败后
`lastError()` 会一直冒充「最近一次异常」。修法是在 `safely()` 每次分发前 `$this->lastError = ''`，
使语义回到「这一次分发的异常」；新增单测
`testLastErrorIsScopedToTheDispatchThatFailed` 锁住。修后同一注入只报 1 条 FAIL、rc=1。

### 5. 锚点守门脚本对新锚点有鉴别力（实测）

新增的 `#exit-codes` / `#退出码` 两处跨页锚点，用**坏锚点注入**验过：把
`/zh/advanced/headless.md#退出码` 改成 `…#退出码-不存在` ⇒ `check-anchors.py src` rc=**1**，
并精确报出那一行；还原后 rc=**0**、目标文件数 14→16（说明这两条链接确实进了检查范围）。
即 CJK slug 不是被脚本跳过的。
（过程性错误：第一次注入用 `perl -pi -e 's{...}{...}/'` 写错定界符，脚本直接 syntax error，
那次「负向 rc=0」是无效读数 ⇒ 改用 python 落注入并先 `assert count==1` 才算。见 Errors 表。）

## F35. 按官方《TypePHP 四种运行时与链接方案》校准 nano 判断：卡点唯一、在上游 codegen（Session 27）

问题（用户给文档）：F33 的 nano 结论要按这份文档重新核一遍，尤其是文档与我上一轮读码口径
不一致的地方（Windows）。全程只读，未拉包、未重编（`df -h /` 仅 2.1 GiB 可用）。

### 1. 先按文档的三轴给本仓库定位（结论：没有用错轴）

| 轴 | 本仓库取值 | 依据 |
|---|---|---|
| `mode` | `bin` | `examples/hello/project.yml:2` |
| `sapi` | 未写 ⇒ 默认 `embed`（实测 `CompilerBase.php:566` 是 `protected array $sapiTargets = ['embed'];`，
  与 `Translator.php:509` 消费的是同一字段） | 全仓 `project*.yml` 无 `sapi:` 键 |
| 运行时来源 | mac = `php-builder`（私有 embed 运行时）；win/linux = 默认宿主 libphp | `project.macos.yml` 末段 |

`project.macos.yml` 的注释早就写了文档那条前提：「宿主的 Homebrew PHP 只有 cli SAPI（无 libphp）」
⇒ 必须 `php-builder`。文档另说 `php-builder` 只适用 `mode: bin`、Windows/Android/iOS 不用它，我们没有违反。
另记两个我们**实测过而文档没写**的 nano 前置（F33 §2）：`--cxx-std c++17`（示例是 c++20，nano 直接 fatal 拒绝）、
入口 yml 必须去掉 `php-builder:` 段。

### 2. nano 卡点收敛成「唯一一处不可满足依赖」，五条备择解释逐条否证

| 解释 | 判定 | 证据 |
|---|---|---|
| phpx 版本过旧 | ✗ | `composer.lock` 的 installed 清单里是 `swoole/phpx@v2.9.3` + `swoole/typephp@v0.9.4`（两者都是正常 lock 包）；`2.7.0`/`v0.9.1` 是**姊妹仓库自己 vendor** 里的版本，拿来比是本仓库之外的事实 |
| 供给方式不对（该按文档 `composer require --dev swoole/typephp "swoole/php-nano:^1.0.2"`，我们是手工塞 vendor） | ✗（但文档这条口径有原因） | 原因是 `swoole/php-nano: ^1.1` 在 typephp 的 **`require-dev`**（`composer.lock:1758-1762`），而 composer 不装依赖的 dev 依赖 ⇒ 正常 `composer install` 永远拿不到它，本仓库的 `vendor/swoole/php-nano` 是手工塞的（我们自己的 `composer.json` 零提及）。但**卡点机制与之无关**：php-nano `composer.json` 的 28 个 component 逐项 dump，对外名字里根本没有 "Core"（`standard.core`→`basic_functions_module`/"standard"、`reflection`→"Reflection"、`spl`→"SPL"…），而唯一叫 "Core" 的 `Zend/zend_builtin_functions.c:52` 是 **`static`** `zend_builtin_module` ⇒ 符号不导出，怎么声明都进不了启动数组 |
| Qt / 我们的 PHP 代码问题 | ✗ | F33：5 行最小程序同一失败 |
| 还有第二个不匹配（`standard`→`random`+`uri`、`uri`→`lexbor`） | ✗ | `ext/standard/basic_functions.c:16` 整段被 `#ifdef PHP_NANO` 分成两套模块入口，nano 编译走 **345 行**那套（`NativeSourceProjectBuilder.php:108` 给 php-nano 的 TU 加 `-DPHP_NANO=1`，
`NativeCommandOptionsTrait.php:36-43` 给我们自己的代码加 `PHP_NANO=1`/`PHPX_NANO=1`），用的是 `STANDARD_MODULE_HEADER`；`Zend/zend_modules.h:41` 把它展开成 `…, NULL, NULL`，按结构体字段序正落在 `ini_entry` 与 **`deps`** ⇒ deps 为 NULL。带 `standard_deps` 的是 489 行的 `#else` 分支，nano 下不编译。php-nano 里 `ZEND_MOD_REQUIRED` 的实际使用只落在 4 个模块（uri→lexbor、openssl→standard、standard 的 `#else` 分支→random+uri、spl→json；计数 7 处里另有 2 处是 `Zend/zend_modules.h:105/109` 的宏定义本身），会进我们数组的只有 SPL→json，而 json 在数组内 ⇒ Ready |
| "Core" 是应用乱用冷门函数造成的 | **反向** | 实测宿主 `PHP 8.5.7` 反射映射：`strlen`/`func_num_args`/`function_exists`/`class_exists` ⇒ **Core**；`var_dump`/`explode`/`is_array`/`printf`/`ini_set` ⇒ standard；`json_decode` ⇒ json。要在 nano 下跑通等于禁止用户写 `strlen` ⇒ 应用侧既不可行也没有规避价值。且 `strlen` 在 php-nano 里**仍然**由 Core 提供（`Zend/zend_builtin_functions.c:390`，arginfo 同目录）⇒ 这条依赖在 nano 语义下本就应由已启动的 core 满足，只是 `dependency_state()` 不去已启动集合里找 |

⇒ 修复点全在上游，两个候选（都不在本仓库）：
(a) `Translator::appendExtensionDependency()`（v0.9.4 `src/Translator.php:2075-2088`）在 nano 模式下不把 `Core` 写进 deps；
(b) 更根本的是 php-nano：`extension.cpp:95` 已先跑 `php_nano_startup_core()`（"Core" 由它启动），
而 `dependency_state()` 只在传入数组里找 ⇒ 已启动的 core 应算可满足。
同一文件里这个不对称是现成的：`php_nano_find_extension()`（`extension.cpp:194-200`）搜的就是
`started_extensions()`，只有依赖检查不用它。
姊妹仓库当前源码与 v0.9.4 在这两处**逐行一致** ⇒ 等发版不会自动修好。
`composer require` 那条口径的真正价值只是让 php-nano 进 `composer.json`/lock 被正常解析，
不是本卡点的解药。

### 3. 与文档冲突的那条：Windows `--nano` 是源码组合还是「仅 policy、仍链 PHP DLL」

文档：Windows 用 `--nano` 只应用语法/能力限制，运行时仍是完整 PHP/PHPX DLL。
v0.9.4 代码是三处反向证据：`Build/NanoBuildBackend.php:11-19` 的 `forHost()`/`composesRuntimeSources()`
**都无视 `$platformName` 参数**、恒返回 `COMPOSER_SOURCES`/`true`（类注释第 5 行：「Selects the source-composed
runtime backend used by a --nano application」，没有任何平台分支）；
`Build/NativeBuildConfigurationTrait.php:238-248` 的 `getLibraries()` 在 `isNanoMode()` 下**提前 return**，
Windows 只给 `advapi32/bcrypt/pathcch/shell32/user32/ws2_32.lib`、非 Windows 给 `[]`，
而 `windowsPhpCoreLib`/`windowsPhpEmbedLib`（`php8ts.lib`/`php8embed.lib`）只在 238 行那个提前 return **之后**的
非 nano 分支里才追加（273-301）⇒ nano 下 PHP 的导入库根本不进链接命令；
`Build/NativeDependencyAuditor.php:69-96` 的 `assertWindowsImports()` 用正则 `php(?:x|\d.*)?\.dll`（:76）匹配
`dumpbin /imports <产物>` 的输出，命中就抛错；它的调用点在 `Translator.php:3025-3028`
（守卫 `if ($this->isWindows())` 在 3025 ⇒ **在 Windows 上构建时**跑这道检查，跨编不算）。php-nano 自己的
`composer.json` 也把 `windows` 列进 `typephp-native.targets`。

> **本节初稿有一处证据是凭印象编的，已当场否证并替换**：原写「`NanoSourceComposer.php:331-340` 为 Windows
> 专门产 `php_nano.lib`，并把 phpx 的 nano glue（`typephp_main_nano.cc` 等）编进目标」—— 实测该文件**只有 252 行**，
> 全 `src/` grep `php_nano\.lib` **零命中**，`typephp_main_nano.cc` 的真实引用点是 `Preprocessor.php:446`。
> 换成上面 `NativeBuildConfigurationTrait.php:238-248` 这条真证据后，§3 的结论方向没变（三处仍反向于文档），
> 但**证据链里那一环此前是空的**。
⇒ 这两件事不可能同时为真：若 Windows `--nano` 产物照文档说的那样「仍然链接完整 PHP/PHPX DLL」，
上面这道检查会当场把构建判失败。所以文档那条要么描述的是旧版本，要么指的不是 nano 这条路；

⇒ 我上一轮「全平台源码组合」在**代码层面**成立，但和文档一样**都没有 Windows 实测**：本机 macOS arm64，
php-nano 的 C 源在 clang-cl/MSVC 下能否编过、`nanoRuntimeIncludePaths` 在 Windows 填不填得上，一律未验证。
两条都按「未验证」记，不采信任一方为事实；引用时不能拿文档当依据，也不能拿我的读码当依据。

### 4. 文档里对 qt 无用的一条

`--full-static`：面向 Linux musl ELF、需要 `$PHPX_HOME/full-static/sdk`（含 musl `crt1.o/crti.o/crtn.o`），
而 qt 应用要链 Qt 自己的动态库 ⇒ 与 qt 交付目标不搭，记为「不适用」，不立项。
nano 的方向仍然是 F33 §4 那句：**省的是 PHP，省不动 Qt**（ICU 31.66 MiB + Qt 15.6 MiB 才是大头）。

### 5. §2(a) 的补丁已落地并真机跑通：nano 产物现在能起、出图与 embed 模式逐字节同（Session 27）

用户令「直接改」⇒ 落 §2 候选 (a) 的最小形：`doGenExtension()` 里把宿主反射来的依赖在 nano 下写成
`ZEND_MOD_OPTIONAL` 而非 `ZEND_MOD_REQUIRED`（选降级而非「特判掉 Core」的理由见 §2 后段：
`dependency_state()` 对 OPTIONAL 缺失是 `continue`、对 REQUIRED 缺失是 `Invalid`，而数组内已存在但未启动的模块
两者都返回 `Waiting` ⇒ 降级**完整保留启动顺序语义**，只让数组外的名字不再致命；真缺扩展会在链接期以未定义符号暴露）。
改动两处、同一处同一文本：`typephp-qt/vendor/swoole/typephp/src/Translator.php:1949`（验证用，`vendor/` 是 gitignored）
与 `typephp-compiler/src/Translator.php:1949`（正解落点，该仓库 git 工作区现仅此一处 modified）。

验证现场（macOS arm64，`examples/hello`，临时入口 `project.nano-probe.yml` = `project.macos.yml` 去掉
`php-builder:` 段 + `cxx-std: c++17` + 独立 `build-dir: build-nano`；两条 nano 前置在此**再次复现成立**。
下表两臂都**没给 `-O`**，即默认 `-O0`）：

| 臂 | codegen 生成的 deps | 构建 | 运行 |
|---|---|---|---|
| 打补丁（OPTIONAL） | `extension-hello_nano.cc:856-861` 六条全 `ZEND_MOD_OPTIONAL`（含 `"Core"`） | rc=0，250 TU（240 php-nano + 6 generated + 4 external，含 `qt_webview_wk.mm`），`Auditing Nano runtime dependencies` 通过 | **`--selftest` 25/25 ok、rc=0**；`--difftest` passed、rc=0；`--shot` 见下 |
| 还原补丁（REQUIRED，负控制） | 同一文件重新生成后六条全 `ZEND_MOD_REQUIRED` | rc=0（同一 build-nano 树，249 个对象走增量缓存，只重编项目 TU + 重链） | **rc=1，全进程只有一行 `Unable to start PHP Nano extensions`**，selftest 一行输出都没有 |
| 再打回补丁 | 六条 `ZEND_MOD_OPTIONAL` | rc=0 | `selftest passed`、rc=0 |

⇒ 正反两臂在同一时间窗、同一构建树里只换这一个变量，且第二轮还原确认了 F33 的失败**不是**环境漂移。

出图与 embed 模式**逐字节相同**（尺子稳定性顺带验了：cocoa 连跑两次同哈希）：

| 后端 | nano 产物哈希 | 既有基线 | 结论 |
|---|---|---|---|
| offscreen | `6c4832a6b738145ef902a131f98ff832e769d2eb5aa1485b2295502ec9b0111b` | `6c4832a6b738…`（F28/F29/F34） | 同 |
| cocoa ×2 | 两次均 `2728fb1a6a0652625483166097bda44a23f051f48e629b7acf9dac793ce1e4d7` | `2728fb1a6a06…` | 同 |

`otool -L` 仍是只链 Qt 三件套 + Foundation/AppKit/WebKit + brew libiconv + libc++/libSystem ⇒ 无 libphp、无 phpx dylib，
「源码内联」这件事在能跑起来的前提下才真正有意义。

**那 2.55 MB 的差异已归因，并且是 F33 自己漏记的一个参数**：会话记录里 F33 那次成功的命令是
```
export CPATH=/opt/homebrew/include LIBRARY_PATH=/opt/homebrew/lib
php vendor/bin/tpc.php examples/hello/project.nanoprobe.yml --nano --cxx-std c++17 -O2 -o /tmp/hello-nano --no-progress
```
（`-O2` 与 `--cxx-std c++17` 都在 CLI 上、没进 yml，F33 只记了后者）。本轮我直连 tpc 时**没给 `-O`** ⇒ 默认 `-O0`。
用同一棵树只换 `-O` 重编即可闭环：

| `-O` | 产物 | `strip -u -r` 后 | `__text` | `__LINKEDIT` |
|---|---|---|---|---|
| `-O0`（默认） | 6,988,648 B | 6,237,888 B | 4,508,196 | 1,146,880 |
| `-O2` | **4,370,536 B** | **3,639,504 B** | 2,131,164 | 917,504 |
| F33 记的（未写 `-O`） | 4,370,536 B | 3,639,504 B | 2,129,548 | 917,504 |

⇒ `-O2` 那一档的**文件总尺寸与 F33 逐字节等值**（`__text` 差 1,616 B，是重编后行号/字符串池的正常抖动），
所以 F33 的 4.17 MiB 不是错数，只是**没把 `-O2` 写进入口 yml 或记录里**，导致同配置复现时先差出 2.55 MB。
排除过程留下的旁证：两档都是 250 TU、链接命令完全相同（都有 `-Wl,-dead_strip`）、`nm` 符号数 8,274 vs 8,727
（差异集中在 Qt 模板实例与 `_register_class_*` ⇒ 内联决策不同，不是组件集合不同）。
`-O2` 档运行同样全过：`--selftest` rc=0、`--difftest` rc=0、`--shot` offscreen `6c4832a6b738…` + cocoa `2728fb1a6a06…`
（与基线逐字节同）、strip 后 3,639,504 B。
⇒ **入口 yml 里应把优化档写死**，否则「nano 多大」有两种答案。走仓库自己的 CLI 时这一档其实固定：
`bin/qtphp:1004` 给 tpc 的命令行里硬编码 `-O2`（1013-1022 还把 brew 前缀塞进 `CPATH`/`LIBRARY_PATH`；
`--nano` 分支是 1005-1011，Phase 32 新加的）
⇒ `qtphp build --nano` 口径就是 4.17 MiB，6.67 MiB 只在直连 `tpc` 且不给 `-O` 时出现。

nano 在 `bin/qtphp` 的 build 侧入口已于 Phase 32 接通（F36）；仍未验证的是 `.app` 打包链路（`macdeployqt` 对 nano 产物）、
Windows/Linux 侧，以及 §3 那个 Windows 口径冲突。

## F36. nano 接进 `bin/qtphp`：真正的卡点不在 qtphp，而在 tpc 的 `php-builder:` 判定（Session 27）

### 1. 为什么「接进 CLI」必须先改上游一处判定

`examples/hello/project.macos.yml` 结尾本来就带 `php-builder: {extensions: [], zts: false}`。F33 当时的绕法是
临时复制一份「去掉该段」的探针 yml —— 那等于让每个项目都为 nano 多养一份入口配置，不可交付。
读码确认配置层没有 off switch（下列行号本轮实测；基准是 `typephp-compiler` 仓库根，vendor 副本同结构。§1 初稿里的
`12 处`、`Translator.php:527/:4323`、裸 `SourcePipelineTrait.php` 三处均已被 §5 更正）：

- `isPhpBuilderBuild()`（`src/CompilerBase.php:978`）是**唯一**判定，`src/` 下 14 处使用 + 3 处上游断言；
- `configurePhpBuilder()` 只把 yml 段写进字段（调用点 `src/Translator.php:529` 走 CLI、`:4325` 走 yml，且仅当 CLI 未给时；定义 `:4690`）；
- 真正干活的是 `src/Build/SourcePipelineTrait.php:612-613` 与 `:1000-1004` 里的 `preparePhpBuilderEnvironment()`（定义同文件 `:711`），
  两者都在运行期按 `isPhpBuilderBuild()` 决定要不要去现编/下载私有 embed 运行时。

⇒ 落点只能是 `isPhpBuilderBuild()` 本身：

```php
return $this->phpBuilderEnabled && !$this->isNanoMode();
```

**只关 `isNanoMode()`、不关 `isNanoPolicyMode()`**（后者是「只做语法/能力限制、仍链宿主 libphp」那条路，
`php-builder:` 在那儿语义不变）。两处仓库同步：`typephp-qt/vendor/.../CompilerBase.php`（验证用、gitignored）
与 `typephp-compiler/src/CompilerBase.php`（正解落点，未提交）。

副作用：F33 记的「入口 yml 必须去掉 `php-builder:`」这条前置作废，现有 `project.yml` + `project.macos.yml`
原样可用于 `--nano`。

### 2. qtphp 侧只有三处改动，其中一处依据是「上游审计会替我兜住」

- `cmdBuild(string $path, array $flags = [])`：只认 `--nano`，未知项 `error()` + rc=1（在 `cmdBuild` 内判，
  不在分发处静默吞掉）；实际下发 `tpc <entry> -O2 --nano --cxx-std c++17` —— `--cxx-std` 走 CLI 覆盖 yml 的 `c++20`。
- Windows 分支把 `deployRuntimeDlls()` 改成 `if (!$nano)`。依据不是「我觉得 nano 不带 DLL」这种直觉，
  而是 tpc 自己的 `NativeDependencyAuditor::assertWindowsImports()` 用正则 `php(?:x|\d.*)?\.dll`
  匹配 `dumpbin /imports` 输出、命中即抛 ⇒ nano 产物若真链上 `php*.dll` 根本编不出来。
  **注意这是 Windows 路径，本轮在 mac 上无法实测，属于「读上游代码 + 上游自带审计」的证据。**
- `usage` 加 `--nano` 一行并写明只有 macOS 验过。`run`/`package` 不改：都按 `build/<name>` 定位产物，
  mac 侧 PHP/PHPX 本就静态链入（`packageAppBundle` 的既有注释即此意）。

### 3. 真机三向（同一 `examples/hello/build/`，不新开 build-dir）

| 臂 | 结果 |
|---|---|
| T1 embed 基线复跑（确认默认路径没被新门伤到） | rc=0、25,569,608 B、链接命令仍含 `-lphp`/`-lphpx` 与 `~/.typephp/php-builder/...` |
| T2 `build --nano` | rc=0；日志里 `php-builder` **出现 0 次**、无 curl/404；250 TU 全过、打印 `Auditing Nano runtime dependencies`；产物 **4,403,640 B**（`strip -u -r` 3,672,352 B） |
| T3 切回 embed（跨模式增量缓存） | 日志 `for 12 files` / `Successfully compiled 12 files`（**不是 no-op**）、`php-builder` 回到 2 次、`-lphp` 回来、产物回到 **25,569,608 B** 与基线等值 |

T2 的 `otool -L`：QtWidgets/QtGui/QtCore（6.11.2）+ Foundation/AppKit/WebKit + brew `libiconv.2.dylib`
+ `libc++.1`/`libSystem.B` + CoreFoundation/libobjc ⇒ 无 libphp、无 phpx dylib。
运行侧与 embed 基线**完全一致**：`--selftest` 25 例 rc=0、`--difftest` 20 例 rc=0、
`--shot` cocoa `2728fb1a6a0652625483166097bda44a23f051f48e629b7acf9dac793ce1e4d7`、
offscreen `6c4832a6b738145ef902a131f98ff832e769d2eb5aa1485b2295502ec9b0111b`。
T3 之后 `--shot` cocoa 仍 `2728fb1a6a06…` ⇒ 复用旧对象没改变行为。

**如实记下两处口径没对齐的地方**（都不是结论级问题，但别当成已解释）：

1. T3 日志报 12 个文件重编，而 mtime 落在该窗口的 `.o` 只有 8 个（`external/2108d59f3aa44f11/qt_{bridge,webview,webview_wk,widgets}`
   + `generated/extension-hello` + `generated/src/{main,QtApp,WidgetTree}`）。我没逐文件核对哪个口径为准，不把 8 当作 12 的解释。
2. 走 qtphp 的 nano 产物 4,403,640 B，与 F35 §5 直连 tpc `-O2` 的 4,370,536 B 差 **33,104 B（+0.76%）**，
   strip 后差 32,848 B。两边都 250 TU、链接命令行集合相同 ⇒ 抖动在代码生成层，候选是入口 yml 不同
   （`project.macos.yml` 保留 `php-builder:` 段 vs 探针 yml 删掉）与产物名 `hello`/`hello_nano` 改变生成符号名长度。**未归因**。

### 4. 文档与回归

`docs/src/reference/cli.md` 与 zh 版各新增 `## nano` 段（两文件实测同在第 96 行；锚点 `#nano` 正是 `bin/qtphp` 注释里指的那个），
并在「首次构建会慢」tip 里交叉引用 —— 因为 nano 恰好**不走**现编私有 embed 运行时那一步。
README 两处：「构建 / 运行 / 打包」代码块加 `qtphp build . --nano` 一行 + 一段实测数字说明，
命令表把该行改成 `qtphp build <path> [--nano]`。
README 的链接按本仓库既有惯例指向**站点产物**（`https://yangweijie.github.io/typephp-qt/zh/reference/cli.html#nano`）
而不是 `docs/src/...md` —— README 里除文档目录入口外没有第二条仓库相对 md 链接，写成后者会多造一种风格。
体积表只写走 `qtphp` 能复现出的数字（4,403,640 / strip 3,672,352），并写明两档同为 `-O2`。

回归：`php -l bin/qtphp` 无错；`php bin/qtphp test` ⇒ **127 tests / 215 assertions OK**；`php bin/qtphp lint` ⇒ 契约一致；
`python3 docs/check-anchors.py docs/src` ⇒ rc=0（16 个目标文件）。
**鉴别力边界**：`check-anchors.py` 的正则只吃 `](/xxx.md#frag)` 形式的跨页链接，本轮新增的页内 `](#nano)`
不经它校验 ⇒ 不能说这条锚点是「被脚本验过的」，只能说它按 markdown-it-anchor 规则应为 `nano`。

顺带修掉三处失效引用：Phase 32 把 `bin/qtphp` 的行号顶掉了 10 行（`-O2` 994→1004），
F35/30.3/Session 27 里三条 `bin/qtphp:994` 已全部改到当前行号并标注是改动后的位置。

### 5. 上游自检：`isPhpBuilderBuild()` 门对上游测试面零影响（A/B 实测，非推理）

先纠正本文件 §1 的三处引用（**初稿那三个 `file:line` 是压缩前的记忆，实测全部有偏差**）：`isPhpBuilderBuild()`
**不是 12 处**。本轮 count/content 实测，均以 `typephp-compiler` 仓库根为基准：

- 定义 1 处：`src/CompilerBase.php:978`；`src/` 内使用 **14 处**
  （`src/Build/NativeBuildConfigurationTrait.php` 180/182/215/349、`src/Build/NativeCommandOptionsTrait.php` 168、
  `src/Build/SourcePipelineTrait.php` 518/521/612/645/687/1000、`src/Translator.php` 2037/2885/3080）；
  另有 3 处 phpunit 断言（`phpunit/src/CompilerBaseApiTest.php:993/1119/1289`）。
- `preparePhpBuilderEnvironment()`：触发点 `src/Build/SourcePipelineTrait.php:612-613` 与 `:1000-1004`
  （**路径含 `Build/` 子目录**，初稿写成裸文件名），定义在同文件 `:711`；
`configurePhpBuilder()` 的两个调用点实测为 `Translator.php:529`（CLI）与 `:4325`（yml），定义 `:4690`。

那 3 处断言都不在 nano 模式下（`grep` 确认：文件里 `setPropertyValue('nanoMode', true)` 出现在 759/1330/1339/1381/1746，
与 993/1119/1289 不同用例），所以 `&& !$this->isNanoMode()` 理论上不该动它们 —— 但这是「能实测就别推理」的场合。

在 `typephp-compiler`（该仓库自带 `vendor/bin`，测试跑得起；`typephp-qt/vendor/.../phpunit` 起不来，
因其 bootstrap 要嵌套 `vendor/autoload.php`，实测报 `Failed opening required`）跑
`php vendor/bin/phpunit --filter '(PhpBuilder|Nano)'`：

| 臂 | 结果 |
|---|---|
| 打补丁（`src/CompilerBase.php` = HEAD + 我那一行） | rc=2，`Tests: 73, Assertions: 206, Errors: 3, Failures: 1, Deprecations: 7` |
| `git show HEAD:src/CompilerBase.php` 覆盖后重跑 | rc=2，**逐位相同**：`Tests: 73, Assertions: 206, Errors: 3, Failures: 1, Deprecations: 7`，4 个坏用例名字也一致 |

⇒ 这一改动在上游 php-builder / nano 测试面上**不产生任何回归**；测完 `src/CompilerBase.php` 已按 `/tmp` 快照
`cmp` 校验还原为逐字节一致，`git status` 回到只有 `src/CompilerBase.php` 与 `src/Translator.php` 两处 modified。

**那 4 个坏点都是环境/仓库自身的坑，与 nano 接入无关（如实记，别当已修）**：

1. 3 个 ERROR 全是 `RuntimeException: Native dependency \`swoole/php-nano\` is not installed`
   （抛点 `src/Build/ComposerNativePackage.php:82`，经 `NanoSourceComposer.php:36`）
   ⇒ `typephp-compiler` 自己的 vendor 里没装 php-nano；那个包只在 `typephp-qt/vendor` 里手工塞过。
2. 1 个 FAILURE 在 `CompilerBaseApiTest.php:1117` 的 `assertSame([$source], $files)`，
   差异是 `/var/folders/…` vs `/private/var/folders/…` ⇒ mac 上 `/var` 是 `/private/var` 的符号链接，
   测试脚手架拿的是未解析路径。**注意连带后果**：该用例在 1117 就断了，永远走不到 1119 那句
   `assertTrue(isPhpBuilderBuild())` ⇒ 这个用例**不能**当作「非 nano 下 php-builder 仍生效」的正向证据；
   正向证据来自 T1（embed 重编 rc=0、产物 25,569,608 B、链接命令含 `-lphp`/`-lphpx`、日志里 `php-builder` 出现 2 次）。
3. 整跑 `--filter CompilerBaseApiTest` 会**中途死掉**（`Tests: ` 摘要行都不打印）：死在
   `testParseProjectYamlLoadsDocumentedCompilerOptions`，`--debug` 显示它在触发
   `ReflectionMethod::setAccessible()` 的 PHP 8.5 deprecation 后打印
   `` `profile` in YAML is only supported on Linux (requires gperftools) `` 即终止。
   A/B：换成 HEAD 版 `CompilerBase.php` 同样 rc=1、同样两行输出 ⇒ 既有问题，不是我引入的。
   ⇒ 结论：**上游这套自检在本机不能全绿**，提交流程里别拿它当门禁；`typephp-qt` 自己的 127 例才是。

## F37. `-O2`/`cxx-std` 固化进入口 yml（Session 28）

用户令把这两项写进入口 yml 固化。改动面：

1. `examples/hello/project.yml`（公共段，`project.macos.yml` / `project.linux.yml` 都 `include` 它，实测 505/580 行都是 `- project.yml`）：
   `cxx-std: c++20 → c++17`，新增 `optimize: 2`，附三行理由注释。
2. `bin/qtphp` 的 `qtphp new` 模板同步（`build-dir: build` 之后那三行）——生成物冒烟已验（见下表末行）。
3. `bin/qtphp:1007-1011` nano 分支注释改写：预留的 CLI `--cxx-std c++17` 从「覆盖 yml 的 c++20」改为「兜底老 yml
   仍写 c++20 的项目」；**CLI 覆盖本身保留**（老脚手架项目不至于死）。
4. 文档同步：中英 `docs/src{/zh}/reference/cli.md`「不用额外的 yml」条目改为「入口 yml 固化 `cxx-std: c++17`（nano 拒绝
   c++20）+ `qtphp build --nano` 的命令行兜底」；`-O2` 口径改为「入口 yml 固化 `optimize: 2`」；README 同点。

**为什么落到 c++17**：`Translator.php:661` 对 nano 是**严格相等**校验（`$this->cxxStd !== 'c++17'` ⇒ fatal），
而 tpc 自己的 cxx-std 默认就是 `c++17`（`CompilerBase.php:532`、`Metadata/Constants.php:253` default），
且上一轮 250 TU 的 nano 全量编译已经把全部桥接源码（含 `.mm`）在 c++17 下编过 ⇒ 降档面有实测背书。
`.ohmyagent/skills/typephp-qt-app` 的模板本来就是 `c++17`（windows/macos/linux 三个），本次是向它对齐。

真机验收（Apple Silicon，全部实测）：

| 验证 | 命令 | 结果 |
|---|---|---|
| embed 重编 | `php bin/qtphp build examples/hello` | 指纹变更触发 pch 重建（新目录 `413c569c…`，旧 `a0148f90…` 10:35 版留着）+ **12 TU 重编**、rc=0；产物 **25,569,608 B**（与 c++20 基线同值） |
| embed 行为 | selftest / difftest / `--shot` ×2 | 全 rc=0；cocoa `2728fb1a…`、offscreen `6c4832a6…` 与基线逐字节相同 |
| nano 重编 | `php bin/qtphp build examples/hello --nano` | 250 TU、`Auditing Nano runtime dependencies`、rc=0；产物 **4,403,640 B**（与基线同值） |
| nano 行为 | 同上四件套 | 全 rc=0，两条哈希依旧逐字节相同 |
| **裸 `tpc --nano`** | `php vendor/bin/tpc.php examples/hello/project.macos.yml --nano -o /tmp/hello-nano-ymlcheck`（**不给 `-O2`、不给 `--cxx-std`**） | 通过 `Translator.php:661` 严格校验并 250 TU 编+链成功、`--selftest` passed、出图同基线 ⇒ yml 的 `cxx-std` 生效的直证 |
| optimize 直证 | 同命令加 CLI `-O0` 覆盖 | **6,989,240 B** vs yml-O2 的 4,370,928 B（+60%）⇒ 若 yml `optimize: 2` 没生效，前者不可能停在 4.37 MB 档；同时确认 CLI 仍优先（`-O0` 能覆盖 yml） |
| 脚手架 | `qtphp new ymlcheck_probe`（/tmp） | rc=0；生成 project.yml 含 `cxx-std: c++17` + `optimize: 2` 与注释；mac/linux 入口照旧 include 公共段 |

模板细节：`qtphp new` 的 yml 是 `<<<YML` heredoc，注释里不能出现 `$`（会被插值）——本次新增注释无 `$`。

**顺带查明的两件事**（不属本任务、未修）：

1. **32,712 B 体积差的新边界**（旧记 33,104 B 是另一对产物）：qtphp 路线 `build/hello`（4,403,640 B）vs 裸 tpc
   `/tmp/hello-nano-ymlcheck`（4,370,928 B），两边同为 O2 / c++17 / 同一入口 yml / 250 TU 同源。差聚在
   `__TEXT`（+32,768 B 整，`size -m`），符号数/符号名总长几乎相同（裸 13,501/492,577 vs qtphp 13,506/492,248）。
   **已排除**：optimize（O0 档 6.99 MB 差量级完全不同）、cxx-std（同值且严格校验已过）、tpc 入口（qtphp 的
   `findTpc()` 实测走 `vendor/bin/tpc.php`，与探针同一入口；`vendor/bin/tpc` 原生二进制不存在）、上一轮怀疑的
   `php-builder:` 段差异（本轮两边都用 `project.macos.yml`，段都在）。**已归因（F38）**：32,712 B = 33,312 B
   （同名「先 embed 再 nano」脏态差）− 600 B（目标名长度差）；真因是 embed 轮的字面量字符串表风味被增量缓存
   沿用，而不是代码生成差异。canonical 干净首建口径 = **4,370,328 B**。
2. **上游 tpc 缓存缺陷：同 build-dir 连续换 output 名 ⇒ 链接期 undefined symbols**。复现：同一
   `examples/hello/build/` 上依次用 `-o /tmp/hello-nano-ymlcheck`（名 A）、`-O0 -o /tmp/hello-nano-ymlcheck-o0`（名 B，
   同一前缀）、`-o /tmp/hello`（名 C=`hello`）跑 `tpc project.macos.yml --nano --no-progress`；第三次链接失败，
   undefined 全指旧命名空间 `typephp_project_hello_nano_ymlcheck_o0::get_persistent_*`（被引用于 main.o/QtApp.o 等
   共享路径对象），而「Successfully compiled 250 files」照打 ⇒ 生成 TU 的对象新鲜度键不含目标名/未捕获命名空间
   变更。机理未挖，归 `typephp-compiler` 侧待办。**对正常流程的影响**：固定目标名的重复构建不受影响
   （T1–T3 与本轮所有正常构建均 rc=0）；本缺陷由连续三种目标名的对抗性探针暴露。**连带处置**：进入下一任务
   （打包验收）前把 `examples/hello/build/cache/{objects,incremental,link}` 清掉，从这个已知污染态里重建一次干净
   nano 产物，不拿污染缓存上的产物做验收。

## F38. `.app` 打包链 × nano 产物验收 + 33,312 B 体积差归因结案（Session 28 收尾）

### 1. 打包验收（全实测，Apple Silicon）

先按 F37 §2 的连带处置清 `build/cache/{objects,incremental,link}`，重建干净 nano 产物（4,370,328 B），
再 `qtphp package examples/hello` rc=0：

| 项 | 值 |
|---|---|
| bundle | `dist/Hello.app`，**67,248,220 B（64.1 MiB）/ 46 文件**（`du -sh` 65,824 KB） |
| 对照 embed 版 | 87,612,051 B / 54 文件 / 83.6 MiB ⇒ **−20,363,831 B（−23.2%）** |
| bundle 内二进制 | 3,787,792 B（macdeployqt 会 strip），sha256 `ada43ae4a4aa58a521ca46018fa5b99079796464036dc31dc4e75016fd29f290` |
| 依赖 | `otool -L` 全 `@executable_path` 改写，无 libphp |
| 签名 | `codesign --verify --deep --strict` 通过 |
| 无头验收 | `verifyAppBundle()` 通过（bundle 内 offscreen `--selftest` + stderr 白名单判据） |
| 出图 | cocoa `2728fb1a…` / offscreen `6c4832a6…` 与开发态基线**逐字节相同**；读图分组标题 `backend=wkwebview，js=支持` |

### 2. 33,312 B 体积差：四步单变量实验

| 步 | 操作 | 产物 | 生成的扩展源 | 结论 |
|---|---|---|---|---|
| A | 清 cache 后干净首建 | **4,370,328 B** | 34,475 B（无表） | canonical 口径 |
| B | 同目录先 embed 再切 `--nano` | **4,403,640 B** | 60,304 B（含 `_literal_strings` 表 ×2） | 脏态精确复现 |
| C | nano-after-nano 重编 | 4,403,640 B | 带表 | **粘滞**（不会自愈） |
| D | 清 `build/cache/{objects,incremental,link}` 后重建 | **4,370,328 B** | 无表 | 复位到 canonical |

差量 forensics（B vs A，`size -m`）：`__TEXT` 段 +32,768（页对齐）、`__text` +32,220、`__bss` +6,984
（表 6,976 + 对齐）、`__LINKEDIT` +544、`__unwind_info` +16、`__gcc_except_tab` +24、`__cstring` +6、
`__init_offsets` +4；新增符号 `typephp_project_hello::_literal_strings`、
`__GLOBAL__sub_I_extension_hello.cc`、`__cxx_global_var_init/dtor`。

机制：`Translator.php:498-506` 在 nano（`composesRuntimeSources`）下强制 `noLiteralStrings = true`，
干净首建遵从（无表）；但同一 `build/` 先跑过 embed 时，该轮的字面量字符串表风味被增量缓存沿用，
nano 轮重新生成的扩展源码带表。归属上游 tpc 增量缓存缺陷家族（与 F37 §2「换目标名」同类）。

**行为零差异**：带表产物 selftest 25/25、difftest 20/20、两条出图哈希与基线逐字节相同 ⇒ 纯体积/风味问题。

### 3. 数字口径结案

- F37 §1 的 32,712 B：= 33,312（同名脏态差）− 600（目标名长度差）。**就此结案**。
- canonical 口径：`--nano` **清 cache 首建 4,370,328 B**、`strip -u -r` 后 **3,639,472 B**。
- 历史近邻数字 4,370,536（30.1/30.3 的 `-O2` 档）、4,370,928（32.6 裸 tpc 探针，目标名更长）来自
  更早的构建轮次，未与 4,370,328 逐字节对账（见下方教训），引用一律以 4,370,328 为准。
- 4,403,640 是「同目录先 embed 再切 nano」的脏态值 —— README 与中英 cli.md 原先引用的是它，
  已全部改按干净首建口径（并加模式切换注记）。

### 4. 方法教训

产物二进制内嵌编译时间戳（实测脏态 `16:42:47` / 干净 `17:28:29`）⇒ **跨轮次二进制不可逐字节对比**；
引用体积/行为前的稳定尺子 = 尺寸 + 符号表 + 出图哈希。

## F39. `.app` 并不自包含：QtCore → brew ICU 的绝对引用（Session 28，追问「ICU 是干嘛的」时挖出；全实测）

**现象**：bundle 内有 36 MB 的 ICU 拷贝（三个文件的 ID 都已改写为 `@executable_path/...`，实测），
但 QtCore 的 3 条 ICU 引用仍是 `/opt/homebrew/opt/icu4c@78/lib/...` 绝对路径 ⇒ 运行时实际加载的是
brew 的 ICU，bundle 内拷贝全程没被加载（死重）。**未装 brew icu4c@78 的机器起不来。**

**证据链（全实测）**：

1. `otool -L` bundle QtCore：glib/pcre2/zstd/double-conversion/b2/gthread 等 brew 依赖都已是
   `@executable_path/../Frameworks/...`，**唯独 ICU 三条保持 `/opt/homebrew/...` 绝对**；
2. `DYLD_PRINT_LIBRARIES=1` 跑 bundle：加载自 `/opt/homebrew/Cellar/icu4c@78/78.3/lib/libicu*.dylib`，
   不是 `Hello.app/Contents/Frameworks/libicu*.dylib`；
3. **A/B 负控制**（临时改名 `/opt/homebrew/opt/icu4c@78`，跑完即还原并复核 symlink 与 selftest）：
   - A 原产物：**rc=134**，`dyld: Library not loaded: /opt/homebrew/opt/icu4c@78/lib/libicui18n.78.dylib`，
     `Referenced from: .../Hello.app/Contents/Frameworks/QtCore.framework/Versions/A/QtCore`；
   - B 修正副本（只改 3 条 ICU 引用为 `@executable_path/../Frameworks/` + 重签）：**rc=0、selftest passed**
     ⇒ 修复方向验证通过（bundle 内 ICU 拷贝本身够用）。副本留在 `/tmp/hello-icu-fixed.app`，
     日志 `/tmp/icu-A.log`、`/tmp/icu-B.log`。

**根因链**（三个盲区叠加；macdeployqt 为何单漏 ICU 未挖）：

- macdeployqt 改写了 QtCore 的大部分 brew 依赖引用，漏了 ICU 三条；
- `vendorBundleDeps()`（bin/qtphp:1839）BFS 队列**只从主二进制出发**、只处理**绝对引用**
  ⇒ Qt framework 从不入队，框架内部引用无人碰；
- `verifyAppBundle()`（bin/qtphp:1891）只扫主二进制 ⇒ 此前所有「自检通过 / env -i 通过」都测不出
  （env -i 清环境变量，清不掉文件系统里的 /opt/homebrew）。

**影响**：Mac 产物实际依赖构建机的 brew icu4c@78。体积口径不变（ICU 36 MB 本来就要在），缺陷是
「死重」没变成「真依赖」。

### F39.1 修复（Session 28 收尾，用户令「修复自包含问题」；全实测）

改动（`bin/qtphp`）：

1. 新增 `bundleMachOFiles()`（读文件头魔数判 Mach-O、realpath 去重 framework 符号链接）与
   `machOLoadRefs()`（`otool -L` 去掉自身 install name —— framework 的第一条是 **ID 不是依赖**，
   误当依赖会拿 basename `QtCore` 去乱拷）；
2. `vendorBundleDeps($appBinary, ...)` → `vendorBundleDeps($contents, ...)`：队列从 Contents 下
   **全部** Mach-O 出发（主二进制、framework、插件、依赖 dylib），绝对非系统引用一律改写
   （目标已在 Frameworks 的直接 `-change`；不在的补拷 + 改 ID 再 `-change`）；两处调用点合并为一处；
3. `verifyAppBundle()` 从「只扫主二进制」扩到「扫全部 Mach-O」，报错带「文件 → 引用」。

验收（全实测，Apple Silicon）：

| 验证 | 结果 |
|---|---|
| 修复后 `qtphp package` | rc=0；QtCore 三条 ICU 引用 → `@executable_path/../Frameworks/...`；全 bundle 只剩 4 条 Qt framework **自 ID**（绝对路径，非加载引用，macdeployqt 产物惯例，不影响自包含） |
| 负控制（临时停掉改写） | rc=1，自检精确列出 `QtCore → /opt/homebrew/.../libicu{i18n,uc,data}` 三条 —— 旧自检根本看不见（只扫主二进制）；**顺带查明**：主二进制与其余依赖 macdeployqt 本会处理好，全 bundle 扫描挖出的唯一遗漏就是 framework 内部的 ICU |
| **藏 brew `icu4c@78` 跑修复产物** | **rc=0、selftest passed**，`DYLD_PRINT_LIBRARIES` 显示 ICU 从 `Hello.app/Contents/Frameworks/libicu*.dylib` 加载（修前同条件 rc=134）⇒ 36 MB 拷贝由死重变真依赖 |
| 回归 | `--difftest` passed；cocoa 出图 `2728fb1a…`、offscreen `6c4832a6…` 与基线逐字节相同；`codesign --verify --deep --strict` 通过；phpunit **127/215** 不变；`qtphp lint` 契约一致 |
| 收尾 | brew symlink 已还原复核；临时对照副本 `/tmp/hello-icu-fixed.app` 已清理 |

文档同步：中英 `reference/packaging.md`「自检机制」加「依赖自包含」判据（扫全 bundle Mach-O），
并纠正被本条证伪的「`env -i` 让构建机任何东西都漏不进来」说法。

**顺带回答案「不支持 webview 会不会小」**：不会。mac 上 webview 走系统 WebKit.framework/AppKit
（不进 bundle），关掉 WKWebView 后端只省 `qt_webview_wk.mm.o`（60,056 B）；ICU 是 QtCore 的固有
依赖，与 webview 无关。
