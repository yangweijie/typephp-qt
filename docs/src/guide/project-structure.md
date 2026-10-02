# Project Structure

## The package itself

```
typephp-qt/
├── bin/qtphp              # CLI (doctor/new/build/run/package/test/lint)
├── cpp-src/               # the C++ bridge (compiled into your app)
│   ├── qt_common.h        # shared header: converters, Box, ChildSlot
│   ├── qt_bridge.cc       # window / render diff / decoration / dialogs / wrappers
│   └── qt_widgets.cc      # widget factory / property application / value reads
├── php-src/qt.stub.php    # the bridge contract (PHP signatures, empty bodies)
├── src/                   # the PHP framework layer
│   ├── QtApp.php          # application framework (event loop / error fallback)
│   ├── WidgetTree.php     # declarative widget-tree builder
│   └── FakeBridge.php     # pure-PHP bridge double (for tests)
├── tests/                 # unit tests
└── examples/hello/        # example app
```

## An application project

`qtphp new` produces this shape:

```
myapp/
├── src/main.php           # entry. State, view and handlers live here
├── project.yml            # Windows build entry
├── project.macos.yml      # macOS entry
├── project.linux.yml      # Linux entry
├── Info.macos.plist       # for packaging the .app
├── build.bat              # Windows convenience script (same as qtphp build .)
├── run.bat                #   … qtphp run .
├── package.bat            #   … qtphp package .
└── assets/                # files read at runtime
```

### Why three `project.*.yml`

`qtphp build` picks the entry by `PHP_OS_FAMILY`:

| Platform | Entry file |
|---|---|
| Windows | `project.yml` |
| macOS | `project.macos.yml` |
| Linux | `project.linux.yml` |

The macOS and Linux entries use `include: [project.yml]` to pull in the common part, then replace the Qt-related fields wholesale (include-paths / link-libs / link-paths / cxx-flags / ld-flags) — because Qt's location and link style (framework vs `-lQt6Xxx`) differ per platform.

Windows uses `project.yml` directly, so `tpc.exe project.yml` inside `build.bat` keeps working despite the multi-platform support.

### `sources:` is the only source of visibility

```yaml
sources:
  - src/main.php
  - ../../src/QtApp.php
  - ../../src/WidgetTree.php
  - ../../php-src/qt.stub.php
  - ../../cpp-src/qt_bridge.cc
  - ../../cpp-src/qt_widgets.cc
```

tpc resolves relative paths against the **directory containing `project.yml`**, so the same relative paths hold on all three platforms.

Note that `cpp-src/*.cc` is listed **directly in the app's `sources`** — this is the "source-inlined" approach: the bridge C++ compiles as part of your app rather than linking a prebuilt library. Why: [Source Inlining](/advanced/bridge.md#why-source-inlining).

## How the package is consumed

The app does not `require` any package file — `require` is unusable under AOT. Package files are listed in `project.yml`'s `sources:` (with relative paths pointing into `vendor/yangweijie/typephp-qt/`), and the compiler loads them uniformly.

That also means: **if you move `vendor/`, the relative paths in `sources:` must move with it.**
