# Architecture

## Three layers

```
        PHP (brain, unit-testable)   C++ bridge (nerves, thin)      Qt (face)
   ─────────────────────────────   ────────────────────────   ───────────────────
   TypePHP\Qt\QtApp             ─►  qt_window_render(tree) ─►  QMainWindow/QLayout
   TypePHP\Qt\WidgetTree            qt_window_poll_event()     QWidget subtree
   (declarative tree + state)       (id→widget map + diff)     (display + report only)
```

- **PHP is the brain.** Data, validation, state transitions, persistence, filtering, counts. Because it is AOT-compiled it runs at machine-code speed — do not be shy about putting real logic here.
- **The C++ bridge is thin.** It creates Qt objects, paints PHP-supplied data onto them, and turns user input into plain PHP arrays. It holds almost no business logic.
- **Qt only displays and reports.** A Qt widget should never decide anything; it just reports "the user clicked X".

## The journey of one click

The fastest way to understand the framework is to follow a single click:

```
(1) The user clicks a button
      ↓
(2) Qt emits clicked → the bridge's lambda does exactly one thing: enqueue
      box->enqueue("click", "greet_btn");
      ↓
(3) The PHP main loop (run()) each frame:
      qt_window_process_events()    ← pump ~16ms of Qt events
      while (poll_event) pull events
      ↓
(4) Dispatch to the registered handler (on('greet_btn','click', …))
      The handler only changes $state — it never touches a widget
      ↓
(5) Next frame: the view() closure runs again, returning a fresh widget tree
      ↓
(6) qt_window_render(tree) → C++ diffs by id
      new id → create widget; missing id → delete widget; existing → update changed props only
      ↓
(7) The screen updates. Cursor, table selection and scroll offset are all where they were
```

Step (6) is the crux: **widget state survives because the diff keys on `id`, not on position.** See [Diff Engine](/advanced/diff-engine.md).

## The bridge contract

The PHP/C++ boundary is declared by two files, and the compiler needs **both**:

| File | Role |
|---|---|
| `php-src/qt.stub.php` | PHP-visible signatures. **Bodies must be empty.** This is the only place the compiler learns the bridge's types. |
| `cpp-src/*.cc` | The implementations. PHP `qt_foo()` maps to C++ `php_qt_foo()`. |

There are 24 functions, all listed in the [bridge contract reference](/reference/api.md#bridge-contract).

`qtphp lint` verifies the two agree (every declared function has an implementation, every implementation has a declaration):

```bash
qtphp lint
# [OK] 契约一致！
```

## Why PHP drives the event loop

Qt wants `app.exec()` to own the main loop; TypePHP needs PHP to own it. So the relationship is inverted: **Qt pumps events in small slices, and user input queues up for PHP to pull.**

C++ side:

```cpp
void processEvents() {
    if (!qt_application) return;
    QEventLoop loop;
    QTimer::singleShot(16, &loop, &QEventLoop::quit);   // about one frame, then hand back to PHP
    loop.exec(QEventLoop::AllEvents);
}
```

PHP side:

```php
while ($app->isOpen()) {
    $app->runFrames(1);      // pump + dispatch + re-render from state
}
```

The payoff: business rules stay in PHP (testable, and optimizable by the AOT compiler), and the error path collapses into one `try/catch` — a handler that throws shows an error box instead of killing the process.

## Error fallback

An exception thrown by a handler is caught by `QtApp`, recorded in `lastError()`, and shown in an error box — **but the loop keeps running**:

```php
$app->on('boom', 'click', function () {
    throw new RuntimeException('something broke');
});

// afterwards:
$app->lastError();   // 'something broke @ /path/to/main.php:42'
$app->clearError();  // clear it
```

The `file:line` in `lastError()` is the main way to locate problems under AOT, because a compiled binary's stack trace is far less readable than an interpreter's. Headless verification (`--selftest`) reports failures through it.
