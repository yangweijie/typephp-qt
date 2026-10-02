# 架构原理

## 三层分工

```
        PHP（大脑，可单测）          C++ 桥（神经，极薄）           Qt（脸）
   ────────────────────────   ────────────────────────   ───────────────────
   TypePHP\Qt\QtApp         ─►  qt_window_render(tree) ─►  QMainWindow/QLayout
   TypePHP\Qt\WidgetTree        qt_window_poll_event()     QWidget 子树
   （声明式控件树 + 状态）       （id→widget 表 + diff）     （只显示/只上报）
```

- **PHP 是大脑。** 数据、校验、状态迁移、持久化、过滤、计数。因为它是 AOT 编译的，跑起来是机器码速度 —— 别客气，把真实逻辑放这里。
- **C++ 桥极薄。** 它创建 Qt 对象、把 PHP 给的数据画上去、把用户输入转成普通 PHP 数组。几乎不含业务逻辑。
- **Qt 只显示、只上报。** 一个 Qt 控件不该做任何决策，它只报告"用户点了 X"。

## 一次点击的完整旅程

理解这个框架最快的方式是跟着一次点击走完：

```
① 用户点按钮
      ↓
② Qt 发 clicked 信号 → 桥的 lambda 只做一件事：入队
      box->enqueue("click", "greet_btn");
      ↓
③ PHP 主循环（run()）每帧：
      qt_window_process_events()    ← 泵 ~16ms 的 Qt 事件
      while (poll_event) 取事件
      ↓
④ 分派给注册的处理器（on('greet_btn','click', …)）
      处理器只改 $state —— 不碰控件
      ↓
⑤ 下一帧：view() 闭包重新执行，返回新的控件树
      ↓
⑥ qt_window_render(tree) → C++ 按 id 做 diff
      新 id → 建控件；消失的 id → 删控件；还在的 → 只更新变化的属性
      ↓
⑦ 屏幕更新。输入光标、表格选中、滚动位置都还在原处
```

关键在第 ⑥ 步：**控件状态能保留，是因为 diff 认的是 `id` 而不是位置。** 详见 [diff 引擎](/zh/advanced/diff-engine.md)。

## 桥接契约

PHP 与 C++ 的边界由两个文件声明，编译器**两个都要**：

| 文件 | 作用 |
|---|---|
| `php-src/qt.stub.php` | PHP 可见的函数签名。**函数体必须为空。** 这是编译器唯一能学到桥接类型的地方。 |
| `cpp-src/*.cc` | 实现。PHP 的 `qt_foo()` 对应 C++ 符号 `php_qt_foo()`。 |

共 24 个函数，全部列在 [桥接契约参考](/zh/reference/api.md#桥接契约)。

`qtphp lint` 会校验两者一致（stub 里声明的每个函数都有实现，C++ 里每个实现都有声明）：

```bash
qtphp lint
# [OK] 契约一致！
```

## 事件循环为什么由 PHP 驱动

Qt 想让 `app.exec()` 拥有主循环，TypePHP 需要 PHP 拥有它。所以这里把关系反过来：**Qt 只按小片泵事件，用户输入排队等 PHP 来取。**

C++ 侧：

```cpp
void processEvents() {
    if (!qt_application) return;
    QEventLoop loop;
    QTimer::singleShot(16, &loop, &QEventLoop::quit);   // 约一帧，然后交还 PHP
    loop.exec(QEventLoop::AllEvents);
}
```

PHP 侧：

```php
while ($app->isOpen()) {
    $app->runFrames(1);      // 泵事件 + 分派 + 按状态重渲染
}
```

这样做的好处：业务规则留在 PHP（可测、可被 AOT 优化），错误路径集中在一个 `try/catch` —— 处理器抛异常时弹错误框而不是让进程崩掉。

## 错误兜底

处理器抛出的异常被 `QtApp` 捕获，记进 `lastError()`，并弹一个错误框，**但循环不中断**：

```php
$app->on('boom', 'click', function () {
    throw new RuntimeException('出错了');
});

// 之后：
$app->lastError();   // '出错了 @ /path/to/main.php:42'
$app->clearError();  // 清掉
```

`lastError()` 里的 `文件:行号` 是 AOT 下定位问题的主要手段 —— 因为编译产物的堆栈不如解释器可读。无头验收（`--selftest`）就是靠它报告失败原因的。
