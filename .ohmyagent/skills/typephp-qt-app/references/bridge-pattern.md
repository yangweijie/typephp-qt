# The C++ ⇄ PHP bridge, in depth

This is the heart of any TypePHP + Qt app. Read it once before writing your first bridge; afterwards `assets/templates/bridge.skeleton.cc` plus the `examples/qt-taskboard/cpp-src/taskboard.cc` example is usually enough.

**If you are using the `typephp-qt` package (SKILL.md route 0), you do not need to write any of this** — the bridge already exists. Read sections 9 and 8 instead, and skim the rest only when you need to extend the bridge or debug it. Sections 1–7 are the hand-written route.

## Why the bridge looks the way it does

PHP cannot call Qt directly, and Qt cannot call PHP directly. So we pick a deliberately dumb boundary: **arrays and scalars cross; everything else is an opaque handle.** That keeps both sides simple and keeps the C++ free of business logic.

The mechanism is PHPX. A C++ function named `php_foo()` becomes the PHP function `foo()`. `php::Variant` is the universal PHP value; `php::Box` is a C++ object wrapped as a PHP `mixed` value (an opaque handle that PHP can hold but not inspect).

## 1. Conversion helpers

Always UTF-8. This is the single most common source of "the UI shows garbage instead of Chinese".

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

`php::Array` is both the input and output currency. Read fields with `row.get("id")`, write with `data.set("id", toPhpString(id))`.

## 2. The Box — one per window

`php::Box` is the base for "a C++ object PHP holds". Subclass it and put every Qt pointer inside. PHP holds the `mixed`; C++ casts it back.

```cpp
class TaskWindowBox final : public php::Box {
  public:
    explicit TaskWindowBox(const QString &title) { /* build the whole UI */ }
    bool isOpen() const;
    void processEvents();
    php::Array pollEvent();
    void setView(const php::Array &rows, const php::Array &metrics, const QString &selected);
    void showError(const QString &message);
    void cleanup();
  private:
    QMainWindow *window_ = nullptr;
    QTableWidget *table_ = nullptr;
    // … every widget you need to touch later
    QHash<QString, TaskView> tasks_;      // id -> row data, for the detail pane
    std::deque<php::Array> events_;       // input events waiting for PHP
};

TaskWindowBox *windowBox(php::Variant box) { return box.toBox<TaskWindowBox>(); }
```

Key design points:

- **`cleanup()` deletes the `QMainWindow`, not the `QApplication`.** The app object lives for the process.
- **Keep a `QHash` of the current rows** keyed by id, so the detail pane and the "advance/edit/delete" buttons can look up full data from just the selected id.
- **Guard against the window being destroyed underneath you**: connect `QObject::destroyed` to null out `window_`.

## 3. QApplication — create it lazily, exactly once

Qt needs a `QApplication` before any widget exists, and you cannot have two. Create it on first use:

```cpp
static int qt_argc = 1;
static char qt_program_name[] = "typephp-app";
static char *qt_argv[] = {qt_program_name, nullptr};
static QApplication *qt_application = nullptr;

php::Variant php_qt_board_create(php::String title) {
    if (!qt_application) {
        qt_application = new QApplication(qt_argc, qt_argv);
        qt_application->setStyle(QStyleFactory::create("Fusion"));   // consistent look across platforms
        // … app name, icon, font, and a global setStyleSheet(...) here
    }
    return {new TaskWindowBox(toQString(title))};
}
```

The global stylesheet is applied once, here — it is the cheapest way to get a non-default look without a designer tool.

## 4. Rendering PHP data onto Qt widgets

`setView` is the "paint the screen" function. It receives PHP-built arrays and does pure presentation:

```cpp
void setView(const php::Array &rows, const php::Array &metrics, const QString &selected) {
    totalValue_->setText(QString::number(metrics.get("total").toInt()));
    // …

    QSignalBlocker block(table_);          // <-- essential: stop selection-changed from
    table_->setRowCount(static_cast<int>(rows.count()));   //     firing while we rebuild
    for (size_t i = 0; i < rows.count(); ++i) {
        const TaskView task = fromPhpTask(rows.get(i).toArray());
        tasks_.insert(task.id, task);
        auto *title = new QTableWidgetItem(task.title);
        title->setData(Qt::UserRole, task.id);       // stash the id on the item
        table_->setItem(static_cast<int>(i), 0, title);
        // …
    }
    // reselect the row PHP asked for
}
```

Two habits worth copying:

- **`QSignalBlocker`** around any programmatic rebuild, or your own `itemSelectionChanged` handler will fire and push bogus `select` events back at PHP in a loop.
- **Stash the id on the item** via `Qt::UserRole`, so `selectedId()` can be derived from the current row without a parallel array.

## 5. The event queue and the event loop

This is the part people get wrong. Qt wants to own the loop via `app.exec()`; TypePHP needs PHP to own it. So we invert: **Qt pumps in small slices, and user input is queued for PHP to pull.**

C++ side:

```cpp
void processEvents() {
    if (!qt_application) return;
    QEventLoop loop;
    QTimer::singleShot(16, &loop, &QEventLoop::quit);   // ~1 frame, then return to PHP
    loop.exec(QEventLoop::AllEvents);
}

php::Array pollEvent() {
    if (events_.empty()) return {};          // empty array == "nothing to do"
    php::Array event = events_.front();
    events_.pop_front();
    return event;
}
```

Every signal handler in the UI just enqueues, never acts:

```cpp
void enqueue(const QString &type, const QString &id = {}, const QString &value = {}, const php::Array &payload = {}) {
    php::Array event;
    event.set("type", toPhpString(type));
    if (!id.isEmpty())    event.set("id", toPhpString(id));
    if (!value.isEmpty()) event.set("value", toPhpString(value));
    if (payload.count() > 0) event.set("payload", payload);
    events_.push_back(event);
}

QObject::connect(add, &QPushButton::clicked, window_, [this]() { openEditor({}); });
QObject::connect(search_, &QLineEdit::textChanged, window_, [this](const QString &t) { enqueue("search", {}, t); });
QObject::connect(deleteButton_, &QPushButton::clicked, window_, [this]() {
    const QString id = selectedId();
    if (!id.isEmpty() && QMessageBox::question(window_, tr("删除任务"), tr("确定删除吗？")) == QMessageBox::Yes)
        enqueue("delete", id);
});
```

PHP side owns the loop:

```php
public function run(): int
{
    $this->refresh();
    while (qt_board_is_open($this->window)) {
        qt_board_process_events($this->window);
        while (true) {
            $event = qt_board_poll_event($this->window);
            if ($event === []) break;
            try {
                $this->handle($event);          // dispatch on $event['type']
            } catch (Throwable $error) {
                qt_board_show_error($this->window, $error->getMessage());   // never let PHP die silently
            }
        }
    }
    qt_board_destroy($this->window);
    return 0;
}
```

**Why this shape is worth the trouble:** business rules stay in PHP where they are testable and where the AOT compiler can optimize them, and the error path is one `try/catch` that surfaces to a Qt message box instead of a crash.

### Event payload convention

Keep one flat convention across the whole bridge — it makes `handle()` a trivial `switch`:

| key | meaning |
|---|---|
| `type` | `filter` / `search` / `select` / `save` / `advance` / `delete` / … |
| `id` | the row/entity id, when the event targets one |
| `value` | a single scalar (search text, filter name), when there is one |
| `payload` | an array for multi-field data (e.g. a form submission) |

## 6. Entry points — one line each

```cpp
php::Variant php_qt_board_create(php::String title) { /* the only non-trivial one */ }
php::Bool    php_qt_board_is_open(php::Variant box) { return windowBox(box)->isOpen(); }
void         php_qt_board_process_events(php::Variant box) { windowBox(box)->processEvents(); }
php::Array   php_qt_board_poll_event(php::Variant box) { return windowBox(box)->pollEvent(); }
void         php_qt_board_set_view(php::Variant box, php::Array rows, php::Array metrics, php::String sel) {
    windowBox(box)->setView(rows, metrics, toQString(sel));
}
void         php_qt_board_show_error(php::Variant box, php::String msg) { windowBox(box)->showError(toQString(msg)); }
void         php_qt_board_destroy(php::Variant box) { windowBox(box)->cleanup(); }
```

## 7. Modal dialogs and forms

A form dialog is a self-contained `QDialog` subclass whose `payload()` returns a `php::Array`. Use `exec()` for the nested loop, and only enqueue on accept:

```cpp
void openEditor(const QString &id) {
    TaskDialog dialog(window_, tasks_.value(id));
    if (dialog.exec() == QDialog::Accepted)
        enqueue("save", id, {}, dialog.payload(id));
}
```

## 8. Headless verification — build a screenshot mode

The single most useful trick for CI and for agents: let the app render once, save a PNG, and exit. Prefer an **argv flag** over an env var (passing env vars through shell/`.bat` layers is fragile — see `build-and-deploy.md`).

```cpp
bool snapshot(const QString &path) {
    qt_application->processEvents();
    return window_ && window_->grab().save(path, "PNG");
}
```

```php
$shot = shot_path($argv);      // reads `--shot <path>`
if ($shot !== '') {
    $app->runFrames(3);
    $app->snapshot($shot);     // clears focus + pushes running animations to their end value
    $app->destroy();
    return;
}
```

Pair it with a **behavioural** switch (`--selftest`) that dispatches every registered event once — that is the only check that catches the AOT handler-arity trap. Both are described in `build-and-deploy.md`; the trap itself in `aot-pitfalls.md`.

**Modal dialogs must not block a headless run.** Give the app a headless flag so `message()` returns its default and file dialogs return empty instead of spinning a nested event loop forever (`aot-pitfalls.md` #5).

## 9. Declarative rendering — the alternative to `setView`

The `set_view` shape above is *imperative*: PHP hands over finished data, C++ paints it. It works, but every new screen means new C++ and a new stub function.

The `typephp-qt` package uses a **declarative** variant instead: PHP hands over a *widget tree* (nested arrays of `{type, id, props, children}`) every frame, and C++ **diffs** it against the live widget set:

- node `id` present in both → update only the changed props (widget state survives: cursor, selection, scroll);
- node `id` new → create the widget;
- node `id` gone → detach and delete.

The key requirement is a **stable id**. Nodes without an explicit `id` get a structural-path id (`_p0.1.2`) derived from their position, so the same node maps to the same widget across frames. An id that changes every frame (a counter, say) makes the differ rebuild the whole subtree each time — and any pointer you kept to the old widget dangles.

Two consequences worth internalizing:

- **You never mutate widgets from PHP.** Handlers change state; the next frame re-describes the tree; the diff applies it. The widget layer becomes a pure function of state.
- **An imperative escape hatch is still needed** for hot paths (a log stream appending rows at speed). The package exposes `patch()` for that — but because it bypasses the tree, the next `render()` re-syncs from the tree, so anything meant to persist has to be written back into state.

If you are hand-writing the bridge and only have a couple of screens, `setView` is simpler. Reach for the declarative shape when screens multiply, or when you find yourself adding C++ for every UI tweak.

## Checklist before you compile

- [ ] Every stub function has a matching `php_`-prefixed C++ symbol.
- [ ] Stub bodies are empty.
- [ ] `QApplication` created lazily, once.
- [ ] Every signal handler **enqueues**, none mutate state directly.
- [ ] `QSignalBlocker` around programmatic view rebuilds.
- [ ] All strings go through `fromUtf8` / `toUtf8`.
- [ ] PHP `main()` loop drains events and wraps handling in `try/catch`.
- [ ] No `require` anywhere; cross-file visibility via `project.yml` `sources:` (`aot-pitfalls.md` #2).
- [ ] Handler dispatch matches each closure's declared arity (`aot-pitfalls.md` #1).
- [ ] A `--selftest` switch exists and passes on the **real binary**, not just under PHPUnit.
