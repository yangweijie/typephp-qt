# Progress Log — typephp-qt

## 当前状态（Session 3 收尾，Phase 1–11 全部完成）

| 项 | 状态 |
|---|---|
| 全部 9 个 Phase（Windows 路线） | ✅ done |
| Phase 10（macOS 原生编译路线） | ✅ done：`qtphp build examples/hello` 在 mac 上产出真实 Mach-O arm64 可执行文件，`--selftest` 10/10、`--shot` 出图 |
| Phase 11（macOS 运行/打包/脚手架） | ✅ done：11.1–11.6 全绿，见下三行 |
| `qtphp test` | ✅ 103 tests / 162 assertions（Windows 与 macOS 双方言实测一致） |
| `qtphp lint` | ✅ 契约一致（24 个函数） |
| 示例 `build` / `--shot` / `--selftest` / `package` | ✅ Windows 全部实测通过 |
| 示例在 macOS 无头运行（FakeBridge） | ✅ `--selftest` 10/10、`--shot` 渲染出完整控件树 |
| 示例在 macOS **真实 AOT 二进制 + Qt 6.11.2** | ✅ `QT_QPA_PLATFORM=offscreen`：`--selftest` 10/10 干净退出；`--shot` 760×560 PNG，菜单/分组/进度条/状态栏全对 |
| `qtphp new` 模板 | ✅ 生成即可编译 + 自检通过；11.6 起同时给出 `project.macos.yml` + `Info.macos.plist`，全新项目在 mac 上 build→run→package 全链实测通过 |
| `qtphp run` / `package`（macOS） | ✅ 11.1/11.2/11.5 完成：run 找无扩展名产物 + `otool -L` 自检 + 参数透传（`--selftest`/`--shot` 直接可用）；package 产出 `dist/Hello.app`，`env -i` 裸环境 `--selftest` 10/10 |
| `qtphp doctor`（macOS） | ✅ 6 项全 OK（含 11.3 新增的「PHP 运行时库」与按平台分派的 C++ 工具链检查） |
| 代码规模 | 3879 行（C++ 1855 / PHP 框架 963 / CLI 924 / 契约 137） |

**Phase 11 收尾复验（2026-10-02，无代码改动）**
`php bin/qtphp test` → OK (103 tests / 162 assertions)；`lint` → 契约一致（24 个函数）；
`doctor` → 6 项全 OK，其中「PHP 运行时库」指向 `~/.typephp/php-builder/php-8.5.11-5852a1ce211cc711/install/lib`。
task_plan.md 的 macOS 环境段同步：私有 embed 运行时从「缺」改为「已建成并缓存」，标题改为「Phase 10–11 交付环境」。

**下一步（可选，未开始）**
- 更多控件行为测试（表格/树的 diff 边界）
- `qt_window_patch` 的 `call` 操作实现（当前是预留）
- 示例增加多窗口 / 托盘 / 定时器演示

---

## Session 3 — 2026-10-02（macOS）

### 任务
> 尝试本地运行 `examples/hello`；随后：`findTpc()` 改跨平台、补 Qt 环境、`project.yml` 改平台条件。

### 平台事实（先纠正一条误记）
- `~/../typephp-tinygui/tools/build-macos.sh` 在 mac 上跑通的是 **clang++ 原生壳 + 系统 PHP shebang 后端**，
  脚本注释自己写明「tpc AOT is a Windows-only toolchain」；Linux 侧同样只有 shebang 后端 + `--nano` 实验。
  ⇒ 本项目此前从未在 mac 上做过 tpc AOT 编译，`~/.typephp`（私有 PHP 运行时缓存）不存在。
- 但 tpc **v0.9.4 本身在 macOS 可用**：`php vendor/bin/tpc.php --version` 正常，
  `Initialized platform/backend: macOS + Clang (clang++)`，源码里 `Build/CompilerToolchain.php:119`、
  `NativeSourceProjectBuilder.php:111` 都有 Darwin 分支。

### 本轮改动
1. **`bin/qtphp` `findTpc()` 跨平台**（+16/−3）
   - 补 `vendor/bin/tpc.php`、`vendor/swoole/typephp/bin/tpc.php` 候选：composer 只发 `bin/tpc.php`，
     `bin/tpc` 是各平台预编译二进制，安装时被跳过（`Skipped installation of bin bin/tpc`）。
   - PATH 探测按平台在 `where tpc` / `command -v tpc` 间切换；`file_exists`→`is_file`。
   - 新增 `tpcCommand()`：扩展名为 `.php` 的入口必须用 `PHP_BINARY` 起，不能当二进制执行。
   - `cmdBuild()` 改用 `tpcCommand()` + `escapeshellarg()`；Windows 的 `tpc.exe` 候选与 `vcvars64.bat` 分支不变。
2. **Qt6 环境补齐**：`brew install qtbase`（6.11.2，只装 base，避开 `brew install qt` 的 qtwebengine 全家桶）。
   `findQt()`/`project.yml` 尚未接 macOS 路径 —— 这是下一步。
3. **Phase 11.1 run 跨平台**：`cmdRun()` 按平台找产物（mac 无 `.exe`）；新增 `machODeps()`/`isSystemDepPath()`
   走 `otool -L` 自检；`deployRuntimeDlls()` 收进 `PHP_OS_FAMILY === 'Windows'` 分支。
4. **Phase 11.2 package 跨平台**：`cmdPackage()` mac 早退到 `packageAppBundle()`，
   对齐上游 `qt-taskboard`（`Info.macos.plist` → `macdeployqt -always-overwrite -no-codesign`
   → `vendorBundleDeps()` 兜底搬 brew 依赖 → ad-hoc 深度签名 → `verifyAppBundle()` 校验
   「无 bundle 外绝对依赖 + `plutil -lint` + `codesign --verify --deep --strict`」。
5. **Phase 11.3 「tpc 在哪」与「运行时库在哪」拆开**：删掉 `findTpcDir()`（composer 装下它返回
   `vendor/bin`，那里一个运行时库都没有），改为 `findRuntimeLibDir()` —— 按标记文件
   （`phpx.dll`/`php8ts.dll`/`libphp.a`/`libphp.dylib`）筛候选：native tpc 同目录 → `TPC_RUNTIME_DIR`
   → `~/.typephp/php-builder/php-‹版本›-‹指纹›/install/lib`（多份取 mtime 最新）。
   `deployRuntimeDlls()` 定位不到时点名原因并给补救命令（此前 null 会拼出 `/phpx.dll`）；
   删除 `cmdNew()` 里从未被模板插值的死 `$tpcDir`；`cmdDoctor()` 增加「PHP 运行时库」检查，
   MSVC 检查收进 Windows 分支、非 Windows 改查 `clang++`。
6. **Phase 11.4 勘误**：README 项目树里「仓库根 project.yml = 桥接单独编译检查」是幻影条目，
   按磁盘实况改写为两份真实入口 yml，并说明桥接的验证路径就是 `qtphp build examples/hello` +
   `qtphp lint`；Session 1 文件清单同步加注勘误。CLI 表也按平台重写。
7. **Phase 11.5 `cmdRun()` 参数透传**：签名加 `array $args = []`，`main()` 用 `array_slice($argv, 3)`
   递进来；命令拼接从手搓双引号改成 `escapeshellarg($exe)` + 逐个 `escapeshellarg($arg)`。
   README 里 `--shot`/`--selftest` 的示例改成平台无关的 `qtphp run <path> …`，
   上手第 4 步不再只列 `.bat`，测试数 89 → 103。
   **顺带修真缺陷**：`run .` / `package .` 因 `basename('.')` 一直是坏的（且 `file_exists('./build/.')`
   为真导致校验失效），新增 `projectDir()`（`rtrim` + `realpath`）供两处使用（见 F11.11）。

### 验证
| 检查 | 结果 |
|---|---|
| `php bin/qtphp doctor` | ✅ `[OK] tpc: .../vendor/bin/tpc.php`、`[OK] PHPUnit`（此前 tpc 是 ERROR） |
| `php bin/qtphp test` | ✅ OK (103 tests / 162 assertions)，改 CLI 后复跑仍全绿 |
| `php bin/qtphp lint` | ✅ 契约一致 |
| 无头跑示例（PHP + FakeBridge） | ✅ `--selftest` 10 个事件全 ok；`--shot` 渲染 2 帧并记录 snapshot 调用 |
| Qt6 编译+链接（`/tmp/qtlink.cpp` QLabel 程序） | ✅ rc=0，`otool -L` 确认链到 `QtWidgets/QtGui/QtCore.framework` |
| `php bin/qtphp build examples/hello` | ✅ `Build successful` → `examples/hello/build/hello`（Mach-O arm64，24 MB）。途中依次踩过：yml 硬编码 D:/ → 缺 embed 运行时 → phpx 缺 gmp/mpfr 头 → framework 转发头缺 `-F` → 缺 `-liconv` |
| 产物依赖 | ✅ `otool -L`：QtWidgets/QtGui/QtCore 6.11.2 + libiconv/gmp/gmpxx/mpfr/onig，rpath 已写 |
| 真实二进制 `--selftest` | ✅ 10/10 全 ok 并干净退出（首轮卡在 `copy_btn`，根因见 `notify()` 修复） |
| 真实二进制 `--shot` | ✅ `/tmp/hello-shot.png` 760×560，肉眼核对：菜单栏、greeting、输入框+打招呼、设置组（复选+下拉）、进度组（进度条+推进/重置+点击次数）、三个底部按钮+链接、状态栏「就绪」全部正确 |
| 不带手工 env 的可复现性 | ✅ 删掉 `~/.typephp/.../phpx-build` 后 `php bin/qtphp build` 裸跑：`Building static PHPX runtime` → `Build successful`，缺头文件报错 0 次（证明 CLI 自动注入 `CPATH`/`LIBRARY_PATH` 生效） |
| Phase 11.1：`qtphp run examples/hello`（mac） | ✅ 找到无 `.exe` 的产物并真正起进程（Qt 字体日志证明已初始化），不再误报「缺 DLL」；`deployRuntimeDlls()` 收进 Windows 分支 |
| `checkSharedLibraryDeps()` 正/负双向 | ✅ 真产物 → 无缺失；`install_name_tool` 改坏依赖的副本 → 精确报出那一条（系统库不误报） |
| 按上游约定改名 `project.darwin.yml` → `project.macos.yml` 后重编 | ✅ 仍 `Build successful`（`-Wl,-framework` 形式实测可用） |
| Phase 11.2：`qtphp package examples/hello`（mac） | ✅ 产出 `dist/Hello.app` 99.4 MB，macdeployqt + ad-hoc 签名零告警 |
| bundle 自包含性 | ✅ `otool -L` 主二进制无 bundle 外绝对路径；**`env -i` 裸环境跑 `Contents/MacOS/hello --selftest` → 10/10 通过**（默认 cocoa 插件，`codesign -dv` 显示 adhoc） |
| Phase 11.3：`findRuntimeLibDir()` 四条路径 | ✅ 临时副本（`sed '$d' bin/qtphp > bin/.tmp-lib-check.php`）逐个验：空 HOME+无发行包 → `NULL`；`TPC_RUNTIME_DIR` 指向放了假 `phpx.dll` 的目录 → 命中该目录；真实 HOME → `/Users/jay/.typephp/php-builder/php-8.5.11-5852a1ce211cc711/install/lib`；`deployRuntimeDlls()` 无库时输出 2 条可操作警告，无 PHP warning/TypeError |
| `php bin/qtphp doctor`（11.3 后） | ✅ 新增 `[OK] PHP 运行时库: ~/.typephp/.../install/lib`；MSVC 检查按平台分派，mac 改报 `[OK] C++: /usr/bin/clang++`，6 项全 OK |
| `php bin/qtphp test` / `lint`（11.3 后复跑） | ✅ OK (103 tests / 162 assertions)、契约一致 |
| Phase 11.4：仓库根 `project.yml` 是否存在 | ❌ 不存在，计划项前提为假：`find` 只有 examples/hello 两份 yml、`git log --all -- project.yml` 零记录、`.gitignore:13` 把它列为生成物。出处是 README 项目树 + Session 1 文件清单，两处已勘误（见 F11.9） |
| 剩余 `D:/` 硬编码复核 | ✅ `git grep -n D:/`：`bin/qtphp` 4 处是 `is_file`/`is_dir` 候选（mac 永不命中，无害）、`cmdNew()` 的 Qt 回落属 11.6、`examples/hello/project.yml` 是**故意的** Windows 入口 |
| Phase 11.5：`qtphp run examples/hello --selftest` | ✅ 参数透传生效，10/10 全 ok（这是 mac 上第一次用 CLI 一条命令跑完无头验收，不必手敲 `build/hello`） |
| 带空格的多参数透传 | ✅ `qtphp run examples/hello --shot "/tmp/qtphp 11 5.png"` → 文件按原名落盘，`file` 确认 760×560 PNG |
| 退出码传递 | ✅ 造一个假产物脚本（`echo "args: $*"; exit 7`）当 `build/fakeproj`：`qtphp run /tmp/fakeproj --selftest '--shot /tmp/a b.png'` → 应用侧收到的参数与传前一致，`rc=7` 逐位传出 |
| **`qtphp run .` / `package .`（修 `basename('.')` 前）** | ❌ 复现真缺陷：`run .` → `sh: ./build/.: is a directory` rc=126；`package .` → 「未找到 bundle 描述文件」。根因 `basename('.')` = `.`，且 `file_exists('./build/.')` 因它是目录而**为真**，前置校验失效（F11.11） |
| **`qtphp run .` / `package .` / `build .`（加 `projectDir()` 后）** | ✅ 在 `examples/hello` 目录内：`run . --selftest` 10/10；`package .` 重出 `dist/Hello.app` 99.4 MB，`env -i` 裸环境 `--selftest` 仍 10/10；`build .` 复用缓存 3.6 s 成功 |
| `php -l` / `test` / `lint`（11.5 后） | ✅ 语法通过、OK (103 tests / 162 assertions)、契约一致 |
| 产物是否会被误提交 | ✅ `git check-ignore -v` → `examples/hello/.gitignore:/build/` 与根 `.gitignore:examples/hello/dist/` 都命中，未跟踪项只剩两份 mac 配置 |

### 环境噪声
- anaconda 的 Qt 5.15.2 在 PATH 上遮蔽 brew 的 `qmake`/`qtpaths`（brew 自己会提示），
  且它没有 `QtWidgets` 顶层 include 目录。构建时一律用 `/opt/homebrew/opt/qtbase` 绝对路径，不吃 PATH。
- 磁盘：数据卷剩 ~11Gi。`~/.typephp` 实测占用：php-src 源码 + 构建 + `install/lib/libphp.a`（62 MB）+
  `install/bin/php`（23 MB）+ `phpx-build/lib/libphpx.a`，一次建成后第二次构建秒级复用（日志出现 `Reusing private PHP runtime`）。
- 本轮新暴露的**跨平台缺陷**（非 mac 专有）：`QtApp::notify()` 漏了 `headless` 守卫，
  任何没有系统托盘的环境（Linux CI、offscreen）都会在 `notify` 处永久阻塞。

### 已知教训延续
- 「本地测试全绿」≠「AOT 二进制能跑」这条本轮再次成立：mac 上能验证的只有 PHP 领域层（FakeBridge），
  C++ 桥与 AOT 严格性必须等 tpc 真编译。

---

## Session 2 — 2026-10-01（续）

### 用户报告的问题
> 点各个按钮和"关于"菜单都报 `stdClass::{closure}() expects exactly 0 arguments, 1 given`

### 根因
ZendPHP 对用户函数的多余实参**静默忽略**，AOT 编译后的闭包做**精确校验**。
`QtApp` 统一用 `$handler($event)` 调用，导致所有 0 参处理器（`function () {...}`）全部报错。
这是"本地测试全绿、编译后一点就崩"的典型 —— 因为 FakeBridge 跑在 ZendPHP 上。

### 修复
- `QtApp::on()/onAny()` 在**注册时**用反射探测必需参数个数，存 `[handler, arity]`
- 分发时按 arity 调用（0 → `$handler()`，≥1 → `$handler($event)`）
- 实测 AOT 支持反射，闭包/方法数组/函数名字符串三种 callable 都能正确探测

### 顺带修复
- **无头阻塞**：`--selftest` 触发 `msg_btn`/`menu.about` 时卡死在模态对话框。
  新增 `QtApp::headless()`，消息框返回 default、文件对话框返回空。
- **`QtApp::lastError()`**：无头环境看不到错误框，需要能程序化读取失败原因。
- **AOT 类型推断**：`$ref` 不能先绑 `ReflectionMethod` 再绑 `ReflectionFunction`，
  必须用两个变量（报 `Cannot re-assign typed object`）。

### 验证

```
示例 --selftest（真实 AOT 二进制，精简 PATH）
  ok   click greet_btn        ok   click copy_btn
  ok   submit name_input      ok   click msg_btn
  ok   toggle dark_toggle     ok   click doc_link
  ok   change theme_combo     ok   menu menu.about
  ok   click step_btn         selftest passed
  ok   click reset_btn

qtphp test → 103 tests, 162 assertions 全绿（新增 14 个 arity/headless 回归）
qtphp new newapp && build && --selftest → selftest passed
```

### 新增回归测试（防复发）
- `testZeroArgHandlerIsCalledWithoutEvent` —— 直接覆盖本次 bug
- `testZeroArgWildcardHandler`
- `testMethodArrayHandlerArity` / `testStringCallableHandler`
- `testHeadless*`（4 个）—— 无头模式行为
- `testLastError*`（3 个）

### 教训
FakeBridge 跑在 ZendPHP 上，**无法复现 AOT 的严格性**。凡是依赖"PHP 宽容行为"
（多余实参、弱类型转换、动态特性）的代码，都必须用真实 AOT 二进制验收，
`--selftest` 就是为此加的。

---

## Session 1 — 2026-10-01

### 开始状态
- `D:\git\php\typephp-qt` 为**空目录**。
- 工具链：Qt 6.9.3 MSVC / tpc v0.9.4 / MSVC 2022 BuildTools。

### 交付结果（全部完成并实测）

| 交付标准 | 状态 | 证据 |
|---|---|---|
| `composer.json` 可 `composer validate` | ✅ | `./composer.json is valid` |
| `qtphp doctor/new/build/run/package/test/lint` 可用 | ✅ | 逐个实跑通过 |
| 示例能 build → run 出窗口 → package 自检通过 | ✅ | 见下 |
| `qtphp test` 无 Qt 环境全绿 | ✅ | 当时 89 tests（Session 2 增至 103） |
| README 说清 5 分钟上手 | ✅ | `README.md` |

### 端到端验收（实测）

```
qtphp build examples/hello
  → Build successful: examples/hello/build/hello.exe
  → 已部署 11 个运行时文件到 build/

hello.exe（精简 PATH: C:\Windows\System32;C:\Windows）
  → GUI 模式：窗口启动，事件循环运行
  → --shot shot.png：退出码 0，生成 17KB PNG，渲染正确

qtphp package examples/hello
  → dist/ 70.2 MB，自检"关键文件齐全"
  → dist/hello.exe 在精简 PATH 下运行成功（退出码 0）
```

### 本轮修复的 4 个真实缺陷

1. **`Array*` 隐式转 bool**（9 处）—— `qtPropInt(&spec, ...)` 传指针变成"值为 bool 的数组"，
   运行时抛 `parameter 1 must be \`array\`, got \`bool\``。
2. **自动 id 每帧递增** —— 无 id 节点每帧重建控件，删旧控件留悬垂指针，第二次渲染崩溃
   （`0xC0000005`）。改为按结构路径稳定生成。
3. **`removeStale()` 直接 delete** —— 布局留悬垂 item。改为先摘除再 `deleteLater()`。
4. **CLI 未加载 MSVC 环境** —— 只查 `cl.exe` 误判（它在 PATH 上但 `INCLUDE` 未设）。
   改为查 `INCLUDE`，并自动 `call vcvars64.bat`。

### 架构决策修正

- **放弃预编译桥接库**（实测证伪）：tpc `-m lib` 只导出有函数体的 PHP 实现，
  桥接 stub 按契约必须空体，故生成的 stub 恒为空。改用**源码内联** ——
  桥接 `.cc` 直接列进应用 `sources`。
- **FakeBridge 改为全局函数**：与真实桥接形态一致（`qt_*` 是全局函数），
  领域层代码写 `qt_window_render(...)` 在测试和 AOT 下都成立。

### 文件清单

```
bin/qtphp                  CLI（7 个子命令）
cpp-src/qt_common.h        共享头：转换工具、Box、ChildSlot
cpp-src/qt_bridge.cc       窗口/渲染 diff/装饰/对话框/24 个包装符号
cpp-src/qt_widgets.cc      控件工厂/属性应用/取值
php-src/qt.stub.php        桥接契约（24 个函数）
src/QtApp.php              应用框架
src/WidgetTree.php         声明式控件树构建器
src/FakeBridge.php         纯 PHP 桥接替身
tests/*.php                3 个测试文件 + bootstrap
examples/hello/            示例应用（build/run/package/--shot/--selftest）
README.md                  使用文档
findings.md                调研结论与踩坑（F1–F10）
task_plan.md               计划与决策
（本清单曾列有仓库根 project.yml —— Session 3 核实该文件从未入库、磁盘也不存在；
  桥接 .cc 只能随应用源码内联编译，见 F7，因此没有独立的根编译配置）
```
