# Hand-written Bridge

The package already contains a complete bridge. This page explains how the bridge is organized internally, and when you would extend it yourself.

## When to hand-write

- To use a **Qt widget the package does not expose** (the package covers 30 common controls; Qt Widgets has 200+ classes).
- To write a **pure tray app** (no main window, lives in the background) — that needs changes to `setQuitOnLastWindowClosed` and the loop condition, see [Multiple Windows, Tray and Timers](/guide/menus-tray-timers.md#tray-apps-two-assumptions-invert).
- You are working inside the TypePHP compiler repo, where `examples/qt-taskboard` is the canonical hand-written reference.

Otherwise, use the package.

## The four parts of a bridge

Whatever the app, a bridge is always these four pieces:

### 1. Conversion helpers

`QString ↔ php::String`, **always through UTF-8**. This is the most common source of "the UI shows garbage instead of Chinese".

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

### 2. A Box — one per window

`php::Box` is the base for "a C++ object PHP holds". Subclass it and put every Qt pointer inside. PHP holds the `mixed`; C++ casts it back.

```cpp
class WindowBox final : public php::Box {
  public:
    explicit WindowBox(const QString &title) { /* build the whole UI */ }
    bool isOpen() const;
    void processEvents();
    php::Array pollEvent();
    void render(const php::Array &tree);
    void cleanup();
  private:
    QMainWindow *window_ = nullptr;
    QHash<QString, QWidget *> widgets_;      // id -> widget
    std::deque<php::Array> events_;          // events waiting for PHP
};

WindowBox *windowBox(php::Variant box) { return box.toBox<WindowBox>(); }
```

Points to note:

- **`cleanup()` deletes the `QMainWindow`, not the `QApplication`.** The app object lives for the process.
- **Keep a widget map keyed by id**, because the diff engine uses it to decide "a widget with this id already exists".
- **Guard against the window being destroyed underneath you**: connect `QObject::destroyed` to something that nulls `window_`.

### 3. QApplication — lazily, exactly once

```cpp
static int qt_argc = 1;
static char qt_program_name[] = "typephp-app";
static char *qt_argv[] = {qt_program_name, nullptr};
static QApplication *qt_application = nullptr;

php::Variant php_qt_app_create(php::Array options) {
    if (!qt_application) {
        qt_application = new QApplication(qt_argc, qt_argv);
        qt_application->setStyle(QStyleFactory::create("Fusion"));
        // … app name, icon, font, a global setStyleSheet(...)
    }
    return {};   // idempotent: calling repeatedly is safe
}
```

The global stylesheet is applied once, here — the cheapest way to get a non-default look without a designer tool.

### 4. `php_*` entry points — one line each

```cpp
void php_qt_window_render(php::Variant box, php::Array tree) { windowBox(box)->render(tree); }
void php_qt_window_close(php::Variant box)                   { windowBox(box)->close(); }
bool php_qt_window_is_open(php::Variant box)                 { return windowBox(box)->isOpen(); }
```

## The contract: stub and implementation must agree

| File | Role |
|---|---|
| `php-src/qt.stub.php` | PHP-visible signatures. **Bodies must be empty.** |
| `cpp-src/*.cc` | The implementations. PHP `qt_foo()` ⇄ C++ `php_qt_foo()` |

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

`qtphp lint` verifies the two agree:

```bash
qtphp lint
# [OK] 契约一致！  (or it lists the mismatched symbols)
```

::: warning Every declared function must be called
If a function declared in the bridge stub is **never called anywhere**, `qtphp build` aborts. That is a compiler requirement — when you add a new function, make sure PHP actually uses it, or do not add it.
:::

## Event queue and loop

```cpp
void processEvents() {
    if (!qt_application) return;
    QEventLoop loop;
    QTimer::singleShot(16, &loop, &QEventLoop::quit);   // about one frame
    loop.exec(QEventLoop::AllEvents);
}

php::Array pollEvent() {
    if (events_.empty()) return {};      // an empty array means "nothing to do"
    php::Array event = events_.front();
    events_.pop_front();
    return event;
}
```

**Every signal handler only enqueues, it never acts directly:**

```cpp
QObject::connect(button, &QPushButton::clicked, window_, [this, id]() {
    enqueue(QStringLiteral("click"), id);
});
```

## Why source inlining

The bridge `.cc` is listed **directly in the app's `sources`**, not linked as a prebuilt library. The reason: `tpc -m lib` only exports signatures from PHP implementations that **have a body**, while the bridge stub must have **empty bodies** by contract — so the generated stub is always empty and the prebuilt-library route does not work.

The side effect is a good one: source inlining can never drift from your Qt/PHPX version.

## Adding a new control, end to end

Say you want `QCalendarWidget`:

1. **`cpp-src/qt_widgets.cc`** — add a branch to the widget factory:

```cpp
if (type == QLatin1String("calendar")) {
    auto *cal = new QCalendarWidget();
    QObject::connect(cal, &QCalendarWidget::selectionChanged, ctx, [box, id, cal]() {
        box->enqueue(QStringLiteral("change"), id, cal->selectedDate().toString(Qt::ISODate));
    });
    return cal;
}
```

2. **Property application** — if it has properties you support (say `min`/`max` dates), handle them where properties are applied.

3. **`src/WidgetTree.php`** — add the factory method:

```php
public static function calendar(array $props = []): array
{
    return self::node('calendar', [], $props);
}
```

4. **`src/FakeBridge.php`** — teach the test double about it (otherwise the unit tests cannot run).

5. **`qtphp lint`** — verify the contract (if you added a new bridge function).

6. **`qtphp build examples/hello`** — verify with a real compile.

7. **Add a case to the example's `--selftest`** — covering its `change` event.

::: tip Do not skip step 4
`FakeBridge` is the pure-PHP stand-in for the `qt_*` functions. A new control that is not registered there makes `qtphp test` fail on an unrecognized type.
:::
