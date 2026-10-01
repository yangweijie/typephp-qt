# Progress Log — typephp-qt

## 当前状态（截至 Session 2 结束）

| 项 | 状态 |
|---|---|
| 全部 9 个 Phase | ✅ done |
| `qtphp test` | ✅ 103 tests / 162 assertions |
| `qtphp lint` | ✅ 契约一致（24 个函数） |
| 示例 `build` / `--shot` / `--selftest` / `package` | ✅ 全部实测通过 |
| `qtphp new` 模板 | ✅ 生成即可编译 + 自检通过 |
| 代码规模 | 3879 行（C++ 1855 / PHP 框架 963 / CLI 924 / 契约 137） |

**下一步（可选，未开始）**
- 更多控件行为测试（表格/树的 diff 边界）
- `qt_window_patch` 的 `call` 操作实现（当前是预留）
- 示例增加多窗口 / 托盘 / 定时器演示

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
project.yml                桥接 C++ 独立编译检查
```
