# AGENTS.md

给在本仓库里工作的 AI 代理（以及新加入的人）的入场说明。**先读这一页，再动代码。**

## 这是什么

TypePHP\Qt —— 用 TypePHP（PHP AOT 编译器）写原生桌面 GUI 的框架，底层是 Qt 6 Widgets。

三层结构，边界必须守住：

```
PHP（src/QtApp.php, src/WidgetTree.php）
  ↓ 只传数组
C++ 薄桥（cpp-src/*.cc）
  ↓ 只创建 Qt 对象、做数组互转
Qt（Widgets）—— 只显示、只上报信号
```

- **业务逻辑、状态、校验、事件处理全在 PHP**。C++ 侧不许长业务。
- **Qt 的事件循环由 PHP 驱动**。禁止 `QApplication::exec()`，用的是
  `QEventLoop` + `QTimer::singleShot(16ms)` + `std::deque` 自驱动。
- **QApplication 是惰性单例**，必须早于任何 widget 创建。

## 目录

| 路径 | 内容 |
|---|---|
| `src/QtApp.php` | 应用/窗口生命周期、事件分发、对话框、剪贴板、webview 调用 |
| `src/WidgetTree.php` | 声明式控件树构造器（静态方法返回数组） |
| `src/FakeBridge.php` | **测试替身**，用纯 PHP 镜像全部 `qt_*` 全局函数 |
| `php-src/qt.stub.php` | PHP 可见的桥接契约（26 个函数，函数体必须为空） |
| `cpp-src/qt_common.h` | 共用工具、`QtWindowBox` 类声明、前向声明 |
| `cpp-src/qt_loop.cc` | 应用/窗口生命周期、事件循环（自驱动分发） |
| `cpp-src/qt_render.cc` | 渲染遍历与差异更新（render/forgetProps/patch） |
| `cpp-src/qt_decor.cc` | 装饰（菜单/托盘/状态栏）与对话框、几何、a11y |
| `cpp-src/qt_widgets.cc` | 控件工厂与属性应用 |
| `cpp-src/qt_webview.cc` | webview 统一入口 + WebView2 / QTextBrowser |
| `cpp-src/qt_webview_wk.mm` | WKWebView（仅 macOS，且仅在 `QT_WEBVIEW_WK` 下进 sources） |
| `bin/qtphp` | CLI 入口：只留用法头 + `require_once lib/*.php` + `exit(main($argv))` |
| `bin/lib/` | CLI 命令实现，按域一个文件（output / doctor / discovery / new / build / project / run / package / package-macos / fs / contract / cli）；**都是全局函数，没有命名空间** |
| `examples/hello` | 参考应用 |
| `docs/` | VuePress 文档站源码 |
| `compat/msvc/` | MSVC 下 nano 运行时的补丁桩 |

## 契约：双文件

任何桥接 API 都必须在**两处**同时存在，否则 `qtphp lint` 会失败：

1. `php-src/qt.stub.php` —— PHP 可见签名，**函数体必须为空 `{}`**
2. `cpp-src/*.cc` —— C++ 实现，符号必须带 `php_` 前缀

```php
// php-src/qt.stub.php
function qt_window_focus(mixed $window): void {}
```

```cpp
// cpp-src/qt_decor.cc
void php_qt_window_focus(const php::Box &window) { ... }
```

**新增一个 API 的完整步骤**（四件套，顺序不能乱）：

1. `php-src/qt.stub.php` 加空函数声明 + 文档注释
2. `src/FakeBridge.php` 加同签名实现（否则 PHP 侧单测会炸在「函数不存在」）
3. `cpp-src/*.cc` 加 `php_` 实现；若涉及 `QtWindowBox` 成员，先改 `qt_common.h`
4. `src/QtApp.php` 包一层语义化方法

新增 **PHP 侧类**（不涉及桥接）只需 1 步：写进 `src/`，PSR-4 自动加载。

## 构建与验证

```bash
# PHP 侧单测（不需要 Qt、不需要编译器）—— 基线 128 tests
php vendor/bin/phpunit --no-coverage

# 桥接契约一致性（stub ⇄ C++）
php bin/qtphp lint

# 示例应用
php bin/qtphp build examples/hello
php bin/qtphp run examples/hello
php bin/qtphp package examples/hello
```

**改动后必须两件事都跑**：`phpunit` + `qtphp lint`。
PHP 侧有回归、或者契约对不上，都不算完成。

C++ 侧目前没有自动化测试；验证方式是 `qtphp build` + 无头运行：

```bash
# Windows
$env:QT_QPA_PLATFORM = "offscreen"
build\hello.exe --selftest
build\hello.exe --difftest
build\hello.exe --shot out.png
```

## 硬约束（踩过的坑）

### AOT（TypePHP / nano）

- 入口必须是全局函数 `main(int $argc, array $argv): void`
- **全局作用域只能有声明**，不能有可执行语句
- 闭包参数需要显式类型标注
- **闭包实参个数必须精确匹配**：AOT 多传一个就是 `ArgumentCountError`，普通 PHP 会静默忽略
- 跨文件调用桥接函数要用 `\qt_xxx()` 全限定名
- 同一个变量不能先绑 `ReflectionMethod` 再绑 `ReflectionFunction`（AOT 类型推断不认），
  见 `QtApp::arityOf()` 里用两个变量分开写

### 编译

- Qt 的 MSVC 编译必须带 `/Zc:__cplusplus /permissive-`
- `examples/hello/project.yml` 用 **`c++17`**（nano 严格校验，`c++20` 会被拒）；
  根 `project.yml`（库模式）用 `c++20`
- 边界统一 UTF-8：`QString::fromUtf8` / `toUtf8()`
- Windows 上 WebView2 需要 `third_party/webview2/x64/WebView2Loader.dll.lib`，
  `.gitignore` 特意用 `!third_party/**/*.lib` 保住它（否则新克隆 LNK1181）

### 运行时

- 托盘应用要 `setQuitOnLastWindowClosed(false)`，且主循环条件用 `is_alive` 而不是 `is_open`
- 没有系统托盘时 `qt_window_notify()` 会退化成模态框阻塞 —— 无头模式下直接跳过
- Windows 需要 runtime ini 配 `PDO_SQLITE`（若用 SQLite）
- `--no-console` 才产出 GUI 子系统

### 渲染 / 差异更新

- **要更新的控件必须给 `id`**。没 id 的节点会拿到 `_p<path>` 隐式 id，
  这个路径只保证**帧内**稳定；前面兄弟数量一变，后面所有无 id 节点都会换 id，
  结果是每帧重建、旧控件从布局脱落 —— 症状是「页面越长越容易看到空白/闪烁」。
  动态容器（列表、表格行、条件分支）里的孩子**必须显式给 id**。
- 控件状态（输入光标、表格选中、滚动位置）靠 id 复用保住；换 id 等于换控件。
- `patch()` 是命令式旁路。用 `call` 改完控件后必须 `forgetProps()` 摘掉相关签名，
  否则下一帧「签名说没变、控件其实变了」。
- 未知控件类型 / 属性 / patch method 默认**静默忽略**（兼容性考虑）；
  调试时设 `QT_DEBUG_STRICT=1` 让它们输出警告。
- 大表格/大树的 `rows` / `nodes` 每帧都会算签名。数据量上千行时优先用
  `patch([['op'=>'call','id'=>'t','method'=>'appendRows', ...]])` 增量追加。

## 代码风格

- 跟现有文件走：PHP 用 4 空格、`declare(strict_types=1)`、方法名 camelCase；
  C++ 用 4 空格、Qt 风格（`qtXxx` 自由函数、`xxx_` 成员后缀）
- 注释写**为什么**，不写**是什么**。本仓库现有的长注释几乎都在解释踩过的坑，保持这个传统
- 不要引入新依赖。Qt 只用 `Core` / `Gui` / `Widgets` / `Network` 四个模块（`Network` 供
  `qt_http_request()` 使用，因此打包时**不能**再跳过 `tls` / `networkinformation` 插件）

## 提交

- 提交信息用中文，一句话说清「改了什么 + 为什么」
- 不要提交 `build/`、`dist/`、`vendor/`、`.phpunit.cache/`
- `composer.lock` 被 `.gitignore` 忽略（这是有意的：库项目不锁依赖）
