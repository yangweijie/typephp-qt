# 快速上手

## 1. 创建项目

```bash
qtphp new myapp
cd myapp
```

生成的骨架**开箱即可编译运行**，你只需要替换界面和数据层。

```
myapp/
├── src/main.php            # 入口：状态 + 视图 + 事件
├── project.yml             # Windows 编译入口
├── project.macos.yml       # macOS 入口（include 公共段 + 覆盖 Qt 段）
├── project.linux.yml       # Linux 入口（Debian 多架构路径）
├── Info.macos.plist        # 打包 .app 用
├── build.bat / run.bat / package.bat   # Windows 便捷脚本
└── assets/                 # 运行时要读的文件（图标等）
```

## 2. 编译 / 运行 / 打包

```bash
qtphp build .       # 编译（Windows 上会自动部署运行时 DLL 到 build/）
qtphp run .         # 运行；其后的参数原样透传给应用
qtphp package .     # 打包自包含产物
```

`run` 之后的参数会原样传给应用，所以可以这样验收：

```bash
qtphp run . --shot out.png
qtphp run . --selftest
```

## 3. 写你的界面

编辑 `src/main.php`。骨架里的结构就是推荐的写法 —— 状态、视图、处理器三段：

```php
<?php

declare(strict_types=1);

use TypePHP\Qt\QtApp;
use TypePHP\Qt\WidgetTree;

function main(int $argc, array $argv): void
{
    // ① 状态：事件改它，视图读它
    $state = ['name' => 'World', 'greeting' => 'Hello, World!', 'clicks' => 0];

    $app = new QtApp();
    $app->create(['name' => 'MyApp']);
    $app->createWindow('My App', ['width' => 720, 'height' => 480, 'centered' => true]);

    // ② 视图：每帧按 $state 重新描述界面
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

    // ③ 处理器：只改状态
    $app->on('greet_btn', 'click', function () use ($app, &$state) {
        $state['name'] = $app->text('name_input');
        $state['greeting'] = 'Hello, ' . ($state['name'] === '' ? 'World' : $state['name']) . '!';
        $state['clicks']++;
    });

    $app->run();
}
```

## 4. 验收

```bash
qtphp run . --shot out.png     # 渲染几帧存 PNG 后退出（视觉验收）
qtphp run . --selftest         # 逐个触发所有事件，验证每个处理器可调用
```

`--shot` 出的图直接看就行；`--selftest` 能在无头环境覆盖「闭包参数个数不匹配」这类**只在 AOT 下暴露**的问题 —— 见 [AOT 注意事项](/zh/advanced/aot-notes.md#闭包实参个数必须精确匹配)。

## 关键约定

- **给每个你要读写的控件一个 `id`。** 没写 id 的节点会拿到结构路径 id（`_p0.1.2`），稳定但不可读；`$app->text('name_input')` 需要真实的 id。
- **`main()` 必须是全局函数**，签名 `main(int $argc, array $argv): void`。命令行参数从这里拿。
- **不要用 `require`。** 跨文件可见性靠 `project.yml` 的 `sources:` 列表建立。
- **不要用 `global $argv`** —— 在 AOT 下会崩。

## 下一步

- [控件目录](/zh/widgets/) —— 有哪些控件、各自的属性
- [事件与处理器](/zh/guide/events.md) —— 事件类型与 payload
- [状态与视图](/zh/guide/state-and-view.md) —— 声明式模式怎么写才顺手
