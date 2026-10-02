# 多窗口、托盘与定时器

## 一个 QtApp = 一个窗口

副窗口就是再 `new QtApp()` 并 `createWindow()`：

```php
$log = new QtApp();
$log->createWindow('运行日志', ['width' => 560, 'height' => 300]);
$log->render(WidgetTree::vbox([
    WidgetTree::table(['时间', '事件'], [], ['id' => 'log_tbl', 'stretch_last' => true]),
]));
```

`qt_app_create` 是**幂等**的 —— `QApplication` 全程只有一个，多次调用不会出问题。

## 但 `run()` 只泵自己那个窗口

这是最容易踩的地方：`$app->run()` 只处理 `$app` 自己的窗口事件。多窗口要**自己按帧轮流泵**：

```php
while ($app->isOpen()) {
    $app->runFrames(1);                       // 主窗口：泵事件 + 分派 + 重渲染

    $log = $state['log'];
    if ($log instanceof QtApp) {
        if ($log->isOpen()) {
            $log->runFrames(1);               // 副窗口
        } else {
            $log->destroy();                  // 被关掉就摘掉，别攒僵尸窗口
            $state['log'] = null;
        }
    }
}
```

要点：

- 用 `runFrames(1)` 而不是 `run()` —— 后者会阻塞到窗口关闭。
- **窗口关闭后要 `destroy()` 并从状态里摘掉**，否则每帧都在泵一个死窗口。
- 主窗口关闭（`isOpen()` 为假）时循环结束。

## 完整例子：主窗口 + 日志窗口

```php
$state = ['log' => null, 'logLines' => []];

// 主窗口里开日志窗口的按钮
$app->on('open_log_btn', 'click', function () use (&$state) {
    if ($state['log'] instanceof QtApp) {
        return;                                   // 已经开着
    }

    $log = new QtApp();
    $log->createWindow('运行日志', ['width' => 560, 'height' => 300]);
    $log->render(WidgetTree::vbox([
        WidgetTree::table(['时间', '事件'], [], ['id' => 'log_tbl', 'stretch_last' => true]),
        WidgetTree::hbox([
            WidgetTree::button('关闭日志', ['id' => 'close_log_btn']),
            WidgetTree::spacer(1),
        ]),
    ]));
    $log->on('close_log_btn', 'click', function () use (&$state) {
        if ($state['log'] instanceof QtApp) {
            $state['log']->close();               // 请求关闭，下一帧 isOpen() 变假
        }
    });

    $state['log'] = $log;
    $log->runFrames(1);                           // 先泵一帧，让控件建出来
});
```

::: tip 先泵一帧
`patch()` 找的是**已存在的**控件。新窗口如果不先泵一帧，第一条 `patch` 会因为控件还没建而丢掉。
:::

## 往日志窗口追加行

用 `patch()` 走命令式旁路（日志流是热路径，整树重建太浪费）：

```php
function log_append(QtApp $log, string $line): void
{
    $log->patch([
        ['op' => 'call', 'id' => 'log_tbl', 'method' => 'appendRows',
         'args' => [[[date('H:i:s'), $line]]]],
    ]);
}
```

**注意**：`patch` 追加的行只在下次 `render()` 之前有效 —— 一旦重渲染，表格会以控件树为准被重建。所以日志这类"只增不减"的数据，要么写回状态（在 `view()` 里生成完整行列表），要么就用 `patch` 且接受它在重渲染时被清掉。

示例应用的做法是把日志行存进状态，`view()` 里生成表格 —— 这样重渲染不丢：

```php
$app->view(function () use (&$state): array {
    return WidgetTree::table(
        ['时间', '事件'],
        $state['logLines'],                       // 数据来自状态
        ['id' => 'log_tbl', 'stretch_last' => true]
    );
});
```

## 定时器

```php
$app->setTimer('clock', 1000);      // 每 1000ms 一次
$app->setTimer('clock', 0);         // 停

$app->on('clock', 'timer', function () use (&$state) {
    $state['ticks']++;
});
```

定时器在所有 handler 注册**之后**注册（`run()` 之前），到 `run()` 里才开始跳。

## 托盘应用：两个假设要反过来

托盘应用和普通窗口应用有两个结构性差异：

**① 关掉窗口不该退出进程。**

```php
// 需要在桥接里设：QApplication::setQuitOnLastWindowClosed(false)
```

否则窗口一隐藏，进程就死了，托盘图标也跟着消失。

**② 循环条件是"还活着"而不是"窗口开着"。**

托盘应用大部分时间没有可见窗口，所以 `while ($app->isOpen())` 会在第一次隐藏后就退出。要用存活标志。

::: warning 包的现状
包当前把托盘定位为**窗口应用的附加能力**（`setTray` + `onAny('tray', …)`），主循环仍是 `while (isOpen())`。

要写**纯托盘应用**（无主窗口、常驻后台），需要手写桥接并改这两处 —— 见 [手写桥接](/zh/advanced/bridge.md) 与技能里的 `references/system-tray.md`。
:::

## 无头验收多窗口

多窗口应用同样能跑 `--selftest`：

```php
// 自检末尾记得把副窗口也销毁
if ($state['log'] instanceof QtApp) {
    $state['log']->destroy();
}
$app->destroy();
```
