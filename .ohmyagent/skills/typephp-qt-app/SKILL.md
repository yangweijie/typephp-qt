---
name: typephp-qt-app
description: Build native desktop GUI applications with TypePHP (the PHP AOT compiler) and Qt. Default route is the `yangweijie/typephp-qt` Composer package — declarative widget trees, the `qtphp` CLI (doctor/new/build/run/package/test/lint), a pure-PHP bridge double for unit tests, and one-command packaging on Windows/macOS/Linux. Also covers the advanced hand-written C++ bridge route (php_* functions, php::Box, Variant/QString conversion, the manual event loop) when you need custom C++ widgets. Includes the AOT pitfalls that only surface in compiled binaries. Use this skill whenever the user wants a desktop UI backed by compiled PHP, mentions TypePHP together with Qt / QtWidgets / QML / QMainWindow / QPushButton / tpc / project.yml with Qt link flags / phpx / windeployqt / qtphp / WidgetTree, or asks to turn a PHP program into a native windowed app — even when they never say "Qt" or "TypePHP" but are clearly building a native GUI on top of compiled PHP.
---

# TypePHP × Qt — Native Desktop Apps

TypePHP compiles PHP to native machine code, but it **cannot draw a window**. Qt (C++) draws the window. So the job is always the same shape: a thin C++ bridge exposes Qt to PHP, and every decision lives in PHP.

There are two ways to get that bridge. **Pick route 0 unless you have a reason not to.**

| Route | What it is | Use when |
|---|---|---|
| **0. The `typephp-qt` package** *(default)* | A Composer package that already contains the bridge, a declarative UI layer, a test double and a CLI. You write only PHP. | Almost always. Forms, tables, dashboards, tools, admin panels. |
| **1. Hand-write the bridge** | You write the `.stub.php` + `.cc` yourself and drive `tpc` by hand. | You need a Qt widget the package does not expose, or you are working inside the TypePHP compiler repo. |

---

# Route 0 — the `typephp-qt` package (default)

`yangweijie/typephp-qt` is the packaged form of everything in route 1. You never touch C++: you describe the UI as a PHP array, and the bundled bridge diffs it against the live Qt widgets each frame.

```bash
composer require yangweijie/typephp-qt
qtphp doctor            # check PHP / tpc / Qt / compiler / PHPUnit before anything else
qtphp new myapp         # scaffold a project that compiles as-is
cd myapp
qtphp build .           # tpc compile (+ auto-deploys runtime DLLs on Windows)
qtphp build . --nano    # nano mode: php-nano + PHPX compiled in, links NO PHP runtime (Apple Silicon verified)
qtphp run .             # run; args after the path pass straight through
qtphp package .         # self-contained bundle + self-check (scans every Mach-O, not just the exe)
```

## The shape of a package app

Three ideas, and that is the whole framework:

1. **State is plain PHP.** An array (or your own objects) holds everything the UI shows.
2. **`view()` describes the UI from state.** It runs every frame and returns a widget tree. You never mutate widgets.
3. **Handlers only change state.** The next frame re-describes the UI; the C++ side diffs by `id` and keeps widget state (cursor, selection, scroll) intact.

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

- **Give every control you touch an `id`.** Nodes without one get a structural-path id (`_p0.1.2`), which is stable but unreadable; `text('name_input')` needs a real id.
- **Containers**: `vbox hbox grid form group frame scroll tabs tab stack page split spacer separator`.
- **Controls**: `label button lineedit textedit spin doublespin slider progress checkbox radio combo list table tree image link`.
- **Embedded web**: `webview` — the backend is picked at compile time per platform: Windows = WebView2 (full Chromium + JS), macOS = the system WKWebView (JS + remote `https://`), anything else = QTextBrowser (HTML subset, **no JS**, remote `url` unsupported). Ask at runtime with `QtApp::webViewBackend()` (`webview2` / `wkwebview` / `textbrowser`) and `webViewSupportsJs()` and degrade instead of failing silently; history is driven by `webViewReload()` / `webViewGoBack()` / `webViewGoForward($id)`.
- **Events**: `click press release change submit commit toggle select activate itemClick cell expand collapse tab close menu timer tray loaded navigating title`. Register with `on($id, $type, $handler)` or `onAny($type, $handler)` — when both match **both fire** (specific first, then the wildcard), which is why `onAny` is the place for logging and telemetry.
- **`patch()`** is the imperative escape hatch for hot paths (log streams, progress ticks): `set` props or `call` one of `appendRows / clear / setText / setValue / select / focus`. It is a bypass — the next `render()` re-syncs from the tree, so write long-lived changes back into state.

Full control/property/event tables: the package `README.md`. Multi-window and timers: also there — one `QtApp` is one window, so `run()` only pumps its own; multi-window apps pump each in a `while` loop. The tray is declarative too: `setTray(['icon' => …, 'tooltip' => …, 'visible' => …, 'menu' => […]])`. Activation arrives as an **id-less** `tray` event — catch it with `onAny('tray', …)`, the gesture is in `$event['value']` (`left` / `right` / `double` / `middle`) — and once you pass `menu`, Qt owns the right-click and items fire ordinary `menu` events.

## Why this route first

- **No C++ to maintain**, so the bridge can never drift from your Qt or PHPX version.
- **The domain layer is unit-testable without Qt.** The package ships `FakeBridge` — a pure-PHP stand-in for the `qt_*` functions — so `qtphp test` runs with no Qt and no compiler. Write your logic against it.
- **One CLI covers all three platforms.** `qtphp` picks the right entry yml, deploys runtime DLLs, and packages a `.app` / `dist/` / `ld-lib` bundle with a self-check.

## Verify without a human

Every package app should keep three headless switches. The scaffold's do:

```bash
qtphp run . --shot out.png   # render a few frames, save PNG, exit — visual check
qtphp run . --selftest       # fire every registered event — catches handler wiring bugs
qtphp run . --difftest       # table/tree diff boundaries (selection, row ids, patches)
```

Set `$app->headless(true)` for these: it makes `message()` return its default and file dialogs return empty, so a modal dialog cannot block a CI run forever. On a box with no GUI session also set `QT_QPA_PLATFORM=offscreen`.

**`--selftest` is not optional polish.** It is the only check that catches the AOT handler-arity trap below, because the PHP unit tests run on a tolerant interpreter and cannot reproduce it.

---

# Route 1 — hand-write the bridge

Everything here is what the package already does for you. Read it when you need a widget the package does not expose, or when you are inside the TypePHP compiler repo (whose `examples/qt-taskboard` is the canonical hand-written reference).

## The architecture (memorize this shape)

```
        PHP (brain)              C++ bridge (nerves)            Qt (face)
   ─────────────────────     ───────────────────────     ─────────────────────
    TaskStore.php        ──►  php_qt_board_create()    ──►  QApplication
    TaskBoardApplication      php_qt_board_set_view()      QMainWindow / QTableWidget
    main.php                  php_qt_board_poll_event()    QPushButton / QLineEdit
                              cpp-src/*.cc                   ← real Qt objects
```

- **PHP owns the brain.** Data, validation, state transitions, persistence, filtering. Because it is AOT-compiled it runs at C++ speed — do not be shy about real logic here.
- **The C++ bridge is thin.** It creates Qt objects, paints PHP-supplied data onto them, and converts input into plain PHP arrays. Almost no business logic.
- **Qt only displays and captures.** A widget never decides anything; it reports "the user clicked X".

Two files declare the boundary, and the compiler needs **both**:

| File | Role |
|---|---|
| `php-src/<name>.stub.php` | PHP-visible signatures. **Function bodies must be empty.** This is the only place the compiler learns the bridge's types. |
| `cpp-src/<name>.cc` | The implementations. PHP `foo_bar()` ⇄ C++ `php_foo_bar()`. |

## Step 0 — Pick the UI route

| Route | Use when | Cost |
|---|---|---|
| **Qt Widgets** | Desktop tools, forms, tables, dashboards. **Default.** | Lowest: no QML engine, no `qml/` directory to deploy. |
| **QML / Quick** | Heavy animation, touch/mobile feel, fully custom styling. | Needs Qt6Qml + Qt6Quick and `windeployqt --qmldir`, or the app launches blank. |

Start with Widgets unless the user explicitly asks for QML. If they do, read `references/qml-route.md`. You can also mix: `QtQuickWidgets` embeds a QML scene in a Widgets window.

## The workflow

### 1. Write the PHP domain layer — no Qt involved

Ordinary PHP classes for data and rules, kept Qt-free so they unit-test with a plain interpreter (`php tests/store_test.php`) and no build. The domain test in `examples/qt-taskboard/tests/` runs with just `php`.

### 2. Declare the bridge in a `.stub.php`

PHP-side signatures with **empty bodies** — the compiler uses this file for types only.

```php
<?php
/** Qt renders the view and returns user input; all decisions live in PHP. */
function qt_board_create(string $title): mixed {}
function qt_board_is_open(mixed $window): bool {}
function qt_board_process_events(mixed $window): void {}
function qt_board_poll_event(mixed $window): array {}
function qt_board_set_view(mixed $window, array $rows, array $metrics, string $selectedId): void {}
function qt_board_show_error(mixed $window, string $message): void {}
function qt_board_destroy(mixed $window): void {}
```

Design rule: **arrays in, arrays out.** `array` crosses cheaply and keeps C++ dumb. Opaque C++ objects travel as `mixed` (a `php::Box`), never as raw pointers.

### 3. Implement the bridge in C++

Four parts, always. Full annotated code in `references/bridge-pattern.md`; copy-paste skeleton in `assets/templates/bridge.skeleton.cc`.

1. **Conversion helpers** — `QString ↔ php::String`, always UTF-8.
2. **A `php::Box` subclass** holding every Qt pointer plus an event queue. This is the `mixed` PHP holds.
3. **A `QApplication`** created lazily on first `create`, stylesheet applied once.
4. **`php_*` entry points** — one per stub function, each delegating to the Box.

### 4. Write `project.yml`

Qt needs include paths, a C++ standard, and link libraries. Ready-to-edit Windows/macOS/Linux variants in `assets/templates/`; what each flag is for in `references/build-and-deploy.md`.

```yaml
name: typephp_taskboard
mode: bin
cxx-std: c++17
sources:            # files OR directories; directories are scanned
  - main.php
  - app
  - php-src
  - cpp-src
cxx-flags:
  - '/Zc:__cplusplus'          # required by the Qt MSVC SDK
  - '/permissive-'
  - '/I"<QT_ROOT>/include"'
  - '/I"<QT_ROOT>/include/QtCore"'
  - '/I"<QT_ROOT>/include/QtGui"'
  - '/I"<QT_ROOT>/include/QtWidgets"'
ld-flags:
  - '/LIBPATH:"<QT_ROOT>/lib"'
  - Qt6Widgets.lib
  - Qt6Gui.lib
  - Qt6Core.lib
```

### 5. Compile, deploy, run

```bash
tpc project.yml --job 2 --no-progress     # expect: Build successful: <name>.exe
windeployqt --release --no-translations <name>.exe   # Qt DLLs AND plugins
```

Then run with the runtime ini pointed at any PHP extensions you use. `windeployqt` is **not enough** for shipping — it copies Qt and nothing else; TypePHP/PHPX runtime DLLs and your `assets/` must be copied too. Per-platform commands, the DLL closure, and the self-verification trick are in `references/build-and-deploy.md`.

Scaffold a whole project in one shot:

```bash
scripts/scaffold.sh <dir> <app_name> [qt_root]          # window app
scripts/scaffold.sh --tray <dir> <app_name> [qt_root]   # system-tray app
```

**The scaffold output is verified: it compiles and renders a real window as-is.** Treat it as the known-good baseline — if your build fails in a way the scaffold does not, the difference is in your edits. `run.bat shot.png` renders one frame and exits.

---

# Hard rules

Each of these silently breaks a build, a launch, or a click. Rules 1–6 are **AOT-specific** — they do not reproduce under a normal PHP interpreter, so a passing unit test proves nothing about them. Details and fixes: `references/aot-pitfalls.md`.

1. **Closure arity is checked exactly.** AOT-compiled closures raise `ArgumentCountError` on an extra argument; plain PHP silently ignores it. `function () {}` called as `$f($event)` works everywhere except in the compiled binary — where it fails **only when that control is actually clicked**. Call each handler with the argument count it declares, or dispatch defensively.
2. **Global scope takes declarations only.** No `require_once`/`require` anywhere — not at global scope, not inside a function. It is `All execution code must be within a function, found stray code`. Cross-file visibility comes from `project.yml`'s `sources:`, never from `require`.
3. **`main()` must be a global function**, signature `main(int $argc, array $argv): void`. `global $argv` crashes the AOT binary (`0xC0000409`).
4. **Closure parameters need explicit types** — `function (array $event)`, not `function ($event)`, or you get `The variable $event is undefined`.
5. **A modal dialog blocks forever with no user.** `QMessageBox::exec()` / `QFileDialog::get*()` spin a nested loop. In CI, `--selftest`, or any headless run, gate them behind a headless flag so they return a default instead.
6. **Do not reuse one variable for two reflection types.** `$ref = new ReflectionMethod(...)` then `$ref = new ReflectionFunction(...)` is `Cannot re-assign typed object`. Use two variables. (AOT *does* support reflection — `getNumberOfRequiredParameters()` works on closures, `[obj,'method']`, and function-name strings.)

Rules 7–14 apply to both routes:

7. **Stub bodies must be empty.** A body in a `.stub.php` is a compile error.
8. **The C++ symbol needs the `php_` prefix.** PHP `qt_board_create` ⇄ C++ `php_qt_board_create`. Wrong ⇒ unresolved symbol at link time.
9. **`QApplication` must exist before any widget**, created lazily behind a null check so it happens exactly once.
10. **You drive the event loop yourself.** Qt must not block on `exec()`. Pump in ~16 ms slices and drain a queue into PHP. (The package does this for you.)
11. **Deploy plugins, not just DLLs.** Skipping `windeployqt` gives "could not find or load the Qt platform plugin windows". For QML add `--qmldir`.
12. **UTF-8 at the boundary** — always `QString::fromUtf8` / `toUtf8`, or CJK text mangles.
13. **Include the header for every class you name**, and declare a file-scope `static` *above* the class that uses it. Qt has no guaranteed transitive includes; both mistakes surface as unrelated-looking `C2027` / `C2065`.
14. **A tray app inverts two assumptions**: `setQuitOnLastWindowClosed(false)`, and loop `while (is_alive)` not `while (is_open)`. See `references/system-tray.md`.

## Windows runtime + toolchain

- **`PDO_SQLITE` (or any PHP extension) needs a runtime ini.** Point `PHPRC` at an ini setting `extension_dir` and `extension=php_pdo_sqlite.dll`, and clear `PHP_INI_SCAN_DIR`. Otherwise the app starts and dies inside the embedded PHP runtime.
- **`--no-console`** for a GUI-subsystem build on Windows (not needed for macOS bundles).
- **`/Zc:__cplusplus` and `/permissive-` are mandatory** with the Qt MSVC SDK.
- **Two `tpc` distributions exist and their runtimes differ.** A native release package is self-contained (`phpx.dll`/`SDK/` sit beside the executable); the Composer-installed driver instead needs a `swoole/phpx` source tree whose build artifacts usually do not exist yet. Picking the wrong one fails with `The PHPX runtime library was not found at: ...\phpx\build\phpx.dll`. `qtphp` probes for the one that has a runtime; `TPC`/`TPC_DIR` override it. See `references/aot-pitfalls.md`.

# Reference index

Read the file matching what you are doing — do not read them all.

- `references/aot-pitfalls.md` — **read this before your first compiled run.** The AOT traps (rules 1–6) with symptoms, root causes and fixes, plus the two-`tpc` supply routes.
- `references/bridge-pattern.md` — the C++/PHP bridge in depth: conversion helpers, `php::Box`, the event queue, the event loop, every `php_*` entry-point pattern, and the declarative diff the package uses.
- `references/qt-widgets-catalog.md` — **every** Qt Widgets class in the install, grouped by purpose. Consult when choosing controls.
- `references/qt-modules.md` — which Qt modules are installed, which are not, and the three edits to link a new one.
- `references/build-and-deploy.md` — per-platform build/deploy/run, `runtime.ini`, packaging (`windeployqt`, macOS `.app`, Linux bundle), and the shell gotchas that waste the most time.
- `references/qml-route.md` — the QML/Quick route: `QQmlApplicationEngine` bridge, `.qml` deployment, when to prefer it.
- `references/system-tray.md` — system tray with `QSystemTrayIcon`, cross-platform (Windows notification area / macOS menu bar / Linux SNI).

Templates: `assets/templates/` (`project.windows.yml`, `project.macos.yml`, `project.linux.yml`, `runtime.ini`, `stub.skeleton.php`, `bridge.skeleton.cc`, `main.skeleton.php`, `store.skeleton.php`, `controller.skeleton.php`, and for tray apps `tray.stub.skeleton.php` + `tray.skeleton.cc`).
