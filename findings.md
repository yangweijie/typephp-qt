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
