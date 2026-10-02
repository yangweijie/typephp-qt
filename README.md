# TypePHP\Qt

基于 TypePHP (AOT) + Qt 6 的原生桌面 GUI 应用快速开发框架。

## 特性

- **声明式 UI** — 用 PHP 数组描述控件树，C++ 侧按 id 做差异更新，控件状态（输入光标、表格选中、滚动位置）在重渲染后保留
- **状态驱动** — 事件处理器只改状态，`view()` 注册的构建函数每帧按新状态重新描述界面
- **PHP 主循环** — Qt 事件泵由 PHP 驱动，业务逻辑全部留在 PHP 里，可单测
- **源码内联** — 桥接 C++ 直接参与应用编译，不依赖预编译二进制，永远不会和 Qt/PHPX 版本脱节
- **一键打包** — `qtphp package` 组装自包含产物并自检：Windows 出 `dist/` 目录（windeployqt + PHP/PHPX 运行时 + 平台插件），macOS 出 `dist/<Name>.app`（macdeployqt + ad-hoc 签名，PHP 侧全静态无需搬运行时）
- **无头测试** — 纯 PHP 桥接替身，不需要 Qt 或编译器；`--shot` 模式可出 PNG 做视觉验收

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
qtphp run .         # 运行；其后的参数原样透传给应用，如 `qtphp run . --selftest`
qtphp package .     # 打包自包含产物：Windows → dist/，macOS → dist/MyApp.app
```

`qtphp new` 生成的新项目同时带 `project.yml`（Windows 段）、`project.macos.yml`（mac 入口，
`include` 前者再替换 Qt 段）与 `Info.macos.plist`（打包 `.app` 用），Windows 便捷脚本
`build.bat` / `run.bat` / `package.bat` 也一并给出。macOS 上首次 `qtphp build` 会让 tpc 从
php-src 现编私有 embed 运行时并缓存在 `~/.typephp`，之后所有构建复用（需要 brew 的
`qtbase` 与 `libiconv`）。

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
    └── project.macos.yml  # macOS 入口：include 上面的公共段再整体替换 Qt 段
```

桥接 C++ 的编译验证就是 `qtphp build examples/hello` —— 两个 `.cc` 是示例 `sources` 的一部分，
Windows 与 macOS 各自走自己的入口 yml，没有单独的仓库根编译配置。
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

## 事件类型

| 事件 | 触发控件 | 说明 |
|------|----------|------|
| `click` | button, link | link 的 `value` 是 href |
| `change` | lineedit, textedit, spin, doublespin, slider, combo | `value` 为新值 |
| `submit` | lineedit | 回车触发 |
| `toggle` | checkbox, radio | `value` 为 `'0'`/`'1'` |
| `select` | list, table, tree | `value` 为行/项 id |
| `activate` | list, table, tree | 双击 |
| `tab` | tabs, stack | `payload.index` |
| `menu` | 菜单项 | `payload.checked` |
| `timer` | 定时器 | 见 `setTimer()` |
| `tray` | 系统托盘 | 左键点击 |

## 控件目录

**容器**：`vbox` `hbox` `grid` `form` `group` `frame` `scroll` `tabs` `tab` `stack` `page` `split` `spacer` `separator`

**控件**：`label` `button` `lineedit` `textedit` `spin` `doublespin` `slider` `progress` `checkbox` `radio` `combo` `list` `table` `tree` `image` `link`

## 常用属性

所有节点通用：`id` `visible` `enabled` `tooltip` `style` `size` `min_size` `max_size` `align` `grow`

容器额外：`title` `margin` `spacing` `row` `col` `row_span` `col_span`

输入类：`text` `placeholder` `readonly` `password` `clear_button` `max_length`

数值类：`value` `min` `max` `step` `decimals` `prefix` `suffix`

列表类：`items` `current` `columns` `rows` `row_ids` `nodes` `headers` `multi` `select_mode`

## CLI

| 命令 | 说明 |
|------|------|
| `qtphp doctor` | 检查工具链（PHP / tpc / PHP 运行时库 / Qt / C++ 编译器 / PHPUnit） |
| `qtphp new <name>` | 创建新项目（`project.yml` + `project.macos.yml` + `Info.macos.plist` + Windows 三个 `.bat`） |
| `qtphp build <path>` | 编译。入口 yml 按平台挑选（`project.macos.yml` → 回落 `project.yml`）；Windows 编译后自动部署运行时 DLL |
| `qtphp run <path> [应用参数…]` | 运行产物，其后的参数原样透传（`--selftest` / `--shot out.png`）；启动前做依赖自检（Windows 查 DLL，macOS 用 `otool -L` 查 bundle 外绝对路径） |
| `qtphp package <path>` | 打包自包含产物并自检：Windows → `dist/` 目录，macOS → `dist/<Name>.app` |
| `qtphp test` | 运行测试 |
| `qtphp lint` | 校验 stub ⇄ C++ 符号一致 |

## 测试

```bash
qtphp test
```

103 个测试全部走纯 PHP 桥接替身，**不需要 Qt 或编译器**。

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

配合两个内置开关做自动化验收：

```bash
qtphp run <path> --shot out.png   # 渲染几帧后存 PNG 退出（视觉验收）
qtphp run <path> --selftest       # 逐个触发所有事件，验证每个 handler 可调用
```

`--selftest` 能在无头环境覆盖"闭包参数个数不匹配"这类只在 AOT 下暴露的问题。

## License

MIT
