# 手写桥接

包已经包含了完整的桥接。这一页讲桥**内部**是怎么组织的，以及什么时候你需要自己扩展它。

## 什么时候需要手写

- 要用**包未暴露**的 Qt 控件（包覆盖了 30 个常用控件；Qt Widgets 有 200+ 个类）。
- 要写**纯托盘应用**（无主窗口、常驻后台）—— 需要改 `setQuitOnLastWindowClosed` 和主循环条件，见[多窗口/托盘/定时器](/zh/guide/menus-tray-timers.md#托盘应用两个假设要反过来)。
- 你在 TypePHP 编译器仓库里工作，`examples/qt-taskboard` 是权威的手写参考。

否则用包。

## 桥的四个部分

无论哪个应用，桥总是这四块：

### 1. 转换辅助函数

`QString ↔ php::String`，**永远走 UTF-8**。这是"界面显示乱码"最常见的来源。

```cpp
#include "phpx.h"

QString toQString(const php::Variant &value) {
    if (value.isNull() || value.isUndef()) return {};
    return QString::fromUtf8(value.toCString());
}

php::String toPhpString(const QString &value) {
    const QByteArray utf8 = value.toUtf8();
    return php::String(utf8.constData(), static_cast<size_t>(utf8.size()));
}
```

### 2. Box —— 一个窗口一个

`php::Box` 是"PHP 持有的 C++ 对象"的基类。子类化它，把所有 Qt 指针塞进去。PHP 持有 `mixed`，C++ 转回来。

```cpp
class WindowBox final : public php::Box {
  public:
    explicit WindowBox(const QString &title) { /* 建整个界面 */ }
    bool isOpen() const;
    void processEvents();
    php::Array pollEvent();
    void render(const php::Array &tree);
    void cleanup();
  private:
    QMainWindow *window_ = nullptr;
    QHash<QString, QWidget *> widgets_;      // id -> 控件
    std::deque<php::Array> events_;          // 等 PHP 取走的事件
};

WindowBox *windowBox(php::Variant box) { return box.toBox<WindowBox>(); }
```

要点：

- **`cleanup()` 删 `QMainWindow`，不删 `QApplication`。** 应用对象活到进程结束。
- **按 id 保存控件表**，diff 引擎靠它判断"这个 id 的控件已存在"。
- **防窗口被销毁**：把 `QObject::destroyed` 连到一个把 `window_` 置空的地方。

### 3. QApplication —— 懒创建，只创建一次

```cpp
static int qt_argc = 1;
static char qt_program_name[] = "typephp-app";
static char *qt_argv[] = {qt_program_name, nullptr};
static QApplication *qt_application = nullptr;

php::Variant php_qt_app_create(php::Array options) {
    if (!qt_application) {
        qt_application = new QApplication(qt_argc, qt_argv);
        qt_application->setStyle(QStyleFactory::create("Fusion"));
        // … 应用名、图标、字体、全局 setStyleSheet(...)
    }
    return {};   // 幂等：多次调用安全
}
```

全局样式表在这里设一次 —— 这是不借助设计器拿到非默认外观最省事的办法。

### 4. `php_*` 入口 —— 每个一行

```cpp
void php_qt_window_render(php::Variant box, php::Array tree) { windowBox(box)->render(tree); }
void php_qt_window_close(php::Variant box)                   { windowBox(box)->close(); }
bool php_qt_window_is_open(php::Variant box)                 { return windowBox(box)->isOpen(); }
```

## 契约：stub 与实现必须一致

| 文件 | 角色 |
|---|---|
| `php-src/qt.stub.php` | PHP 可见的签名。**函数体必须为空。** |
| `cpp-src/*.cc` | 实现。PHP `qt_foo()` ⇄ C++ `php_qt_foo()` |

```php
// php-src/qt.stub.php
<?php
function qt_window_render(mixed $window, array $tree): void {}
function qt_window_poll_event(mixed $window): array {}
```

```cpp
// cpp-src/qt_bridge.cc
void php_qt_window_render(php::Variant box, php::Array tree) { /* … */ }
php::Array php_qt_window_poll_event(php::Variant box)        { /* … */ }
```

`qtphp lint` 校验两者一致：

```bash
qtphp lint
# [OK] 契约一致！  （或列出不一致的符号）
```

::: warning 每个声明的函数都必须被调用
桥接 stub 里声明的函数，如果**没有任何地方调用**，`qtphp build` 会中止。这是编译器的要求 —— 加新函数时记得在 PHP 侧真的用上它，或者别加。
:::

## 事件队列与循环

```cpp
void processEvents() {
    if (!qt_application) return;
    QEventLoop loop;
    QTimer::singleShot(16, &loop, &QEventLoop::quit);   // 约一帧
    loop.exec(QEventLoop::AllEvents);
}

php::Array pollEvent() {
    if (events_.empty()) return {};      // 空数组 == "没有事件"
    php::Array event = events_.front();
    events_.pop_front();
    return event;
}
```

**每个信号处理器只入队，从不直接行动：**

```cpp
QObject::connect(button, &QPushButton::clicked, window_, [this, id]() {
    enqueue(QStringLiteral("click"), id);
});
```

## 为什么是源码内联

桥接 `.cc` 是**直接列进应用 `sources`** 的，不走预编译库。原因：`tpc -m lib` 只从**有函数体**的 PHP 实现导出签名，而桥接 stub 按契约**必须函数体为空** —— 所以生成的 stub 恒为空，预编译路线走不通。

副作用是好的：源码内联永远和你的 Qt/PHPX 版本同步。

## 加一个新控件的完整流程

假设要加 `QCalendarWidget`：

1. **`cpp-src/qt_widgets.cc`** —— 在控件工厂里加分支：

```cpp
if (type == QLatin1String("calendar")) {
    auto *cal = new QCalendarWidget();
    QObject::connect(cal, &QCalendarWidget::selectionChanged, ctx, [box, id, cal]() {
        box->enqueue(QStringLiteral("change"), id, cal->selectedDate().toString(Qt::ISODate));
    });
    return cal;
}
```

2. **属性应用** —— 如果它有你支持的属性（如 `min`/`max` 日期），在属性应用逻辑里处理。

3. **`src/WidgetTree.php`** —— 加工厂方法：

```php
public static function calendar(array $props = []): array
{
    return self::node('calendar', [], $props);
}
```

4. **`src/FakeBridge.php`** —— 让测试替身也认识它（否则单测跑不了）。

5. **`qtphp lint`** —— 校验契约（如果加了新桥接函数）。

6. **`qtphp build examples/hello`** —— 真编译验证。

7. **在示例的 `--selftest` 里加一条** —— 覆盖它的 `change` 事件。

::: tip 第 4 步别忘
`FakeBridge` 是纯 PHP 的 `qt_*` 替身。新控件如果不在它那里登记，`qtphp test` 会因为认不出类型而失败。
:::
