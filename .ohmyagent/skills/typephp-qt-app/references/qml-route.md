# The QML / Quick route

Widgets and QML are two ways to build the same Qt app. The bridge pattern is nearly identical — the difference is **what the C++ side creates** and **what you must deploy**.

> **Confidence note:** the Widgets route in this skill was verified end-to-end on a real machine. The QML route below follows standard Qt 6 practice and the Qt modules confirmed installed, but has not been run end-to-end here. Verify the deployment step (`windeployqt --qmldir`) on the target before relying on it.

## When to choose QML over Widgets

| Prefer QML when… | Prefer Widgets when… |
|---|---|
| The UI is animation-heavy or highly custom-styled | The UI is a form, table, or tool window |
| Touch / mobile / tablet is a target | It is a desktop keyboard-and-mouse tool |
| Designers hand you `.qml` files | You want the smallest deployment |
| You need `QtQuickControls2` styles (Material, Universal, FluentWinUI3) | You want plain native-looking controls |

The two can coexist: `QtQuickWidgets` (`QQuickWidget`) drops a QML scene into a Widgets window, so you can keep a Widgets shell and put a QML canvas in one panel.

## What changes in the bridge

Widgets route: C++ builds a widget tree.
QML route: C++ builds a `QQmlApplicationEngine`, loads a `.qml` file, and exposes data/objects to QML.

```cpp
#include <QGuiApplication>
#include <QQmlApplicationEngine>
#include <QQmlContext>

static QGuiApplication *qt_application = nullptr;
static QQmlApplicationEngine *qt_engine = nullptr;

php::Variant php_ui_create(php::String qmlPath) {
    if (!qt_application) qt_application = new QGuiApplication(qt_argc, qt_argv);
    if (!qt_engine)      qt_engine = new QQmlApplicationEngine();
    qt_engine->load(QUrl::fromLocalFile(toQString(qmlPath)));
    return { /* a Box holding the engine, or a QObject bridge */ };
}
```

Two ways to push PHP data into QML:

1. **Context properties** — simplest. `qt_engine->rootContext()->setContextProperty("rows", qmlArrayFromPhp(rows));` and reference `rows` from QML. Good for read-mostly data that you re-push on each `set_view`.
2. **A `QObject` bridge with signals/slots** — register a C++ class with `qmlRegisterType`, expose `Q_INVOKABLE` methods that PHP calls, and `Q_PROPERTY`/signals for QML→PHP. More code, but it is the idiomatic route and scales better.

For a TypePHP app, start with **context properties** — it mirrors the Widgets bridge's "arrays in, arrays out" shape and needs no meta-object plumbing.

QML calling back into PHP works exactly like a widget signal: a QML signal handler calls a C++ slot, which `enqueue()`s an event, which PHP drains on the next tick. Same event-queue contract as `bridge-pattern.md`.

## QML needs an extra deployment step

This is the part that silently breaks: **`windeployqt` does not know about your `.qml` files unless you tell it.** Without `--qmldir` the app launches to a blank/black window because the QtQuick runtime modules are missing.

```bash
windeployqt --release --qmldir <dir-containing-your-qml> <name>.exe
```

`--qmldir` scans your QML imports and copies the matching modules out of `<QT_ROOT>/qml` (QtQuick, QtQml, QtQuick/Controls, QtQuick/Window, QtQuick/Layouts, Dialogs, Effects, Particles, Shapes, Templates, LocalStorage, …) next to the binary.

At run time, QML also needs `<QT_ROOT>/qml` reachable — either deployed alongside (via `--qmldir`) or via `QML2_IMPORT_PATH`. A deployed app must ship the `qml/` tree; there is no way around it.

## Link flags

In addition to the Widgets set, link the QML/Quick libraries you use:

```yaml
cxx-flags:
  - '/I"<QT_ROOT>/include/QtQml"'
  - '/I"<QT_ROOT>/include/QtQuick"'
  - '/I"<QT_ROOT>/include/QtQuickControls2"'
  - '/DQT_QML_LIB'
  - '/DQT_QUICK_LIB'
ld-flags:
  - Qt6Qml.lib
  - Qt6Quick.lib
  - Qt6QuickControls2.lib
  - Qt6QuickTemplates2.lib
```

Add `Qt6QuickWidgets.lib` if you embed QML inside Widgets.

## Practical guidance

- **Do not mix routes casually.** Pick one as the shell. If you must mix, use `QQuickWidget` for the QML panel and keep the rest Widgets.
- **Keep the PHP side identical.** The domain layer, the stub file, the event queue and the PHP `main()` loop do not change at all between the two routes. Only `cpp-src/` and the deployment step differ. That is the payoff of the "arrays in, arrays out" boundary.
- **Verify the blank-window failure early.** On a new machine, build the smallest possible QML app and confirm it renders before building anything real — the missing-`qml/` failure is otherwise easy to misdiagnose as a bridge bug.
