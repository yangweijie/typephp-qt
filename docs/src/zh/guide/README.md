# 指南

TypePHP 把 PHP 编译成原生机器码，但它**画不了窗口**。Qt（C++）负责画窗口。所以这个框架的形状是固定的：一层很薄的 C++ 桥把 Qt 暴露给 PHP，所有决策留在 PHP 里。

`yangweijie/typephp-qt` 把这层桥、一个声明式 UI 层、一个测试替身和一个 CLI 打包成了一个 Composer 包。**你只写 PHP。**

## 三个概念

整个框架就这三个想法：

1. **状态是普通 PHP** —— 一个数组（或你自己的对象）装着界面要显示的一切。
2. **`view()` 按状态描述界面** —— 它每帧执行一次，返回一棵控件树。你从不直接改控件。
3. **处理器只改状态** —— 下一帧重新描述界面；C++ 侧按 `id` 做 diff，控件状态（光标、选中、滚动）原样保留。

```php
use TypePHP\Qt\QtApp;
use TypePHP\Qt\WidgetTree;

function main(int $argc, array $argv): void
{
    $state = ['name' => 'World', 'greeting' => 'Hello, World!'];

    $app = new QtApp();
    $app->create(['name' => 'MyApp']);
    $app->createWindow('My App', ['width' => 720, 'height' => 480, 'centered' => true]);

    $app->view(function () use (&$state): array {
        return WidgetTree::vbox([
            WidgetTree::label($state['greeting'], ['id' => 'greeting']),
            WidgetTree::hbox([
                WidgetTree::lineEdit($state['name'], ['id' => 'name_input']),
                WidgetTree::button('打招呼', ['id' => 'greet_btn']),
            ]),
        ]);
    });

    $app->on('greet_btn', 'click', function () use ($app, &$state) {
        $state['name'] = $app->text('name_input');
        $state['greeting'] = 'Hello, ' . $state['name'] . '!';
    });

    $app->run();
}
```

## 接下来

- [安装](/zh/guide/installation.md) —— 装包与工具链检查
- [快速上手](/zh/guide/quickstart.md) —— 五分钟跑起一个窗口
- [架构原理](/zh/guide/architecture.md) —— 声明式 diff 是怎么工作的
- [控件目录](/zh/widgets/) —— 有哪些控件可用

## 为什么不用手写 C++ 桥

包本身就是"手写桥"的产品化形态。手写路线仍然可行（[深入：桥接](/zh/advanced/bridge.md)），但只有在你要用包未暴露的 Qt 控件时才需要。包的路线有三点好处：

- **没有 C++ 要维护**，桥不会和你的 Qt/PHPX 版本脱节。
- **领域层能脱离 Qt 单测** —— 包自带 `FakeBridge`（`qt_*` 函数的纯 PHP 替身），`qtphp test` 不需要 Qt 也不需要编译器。
- **一个 CLI 覆盖三个平台** —— `qtphp` 按平台挑入口 yml、部署运行时 DLL、打包并自检。
