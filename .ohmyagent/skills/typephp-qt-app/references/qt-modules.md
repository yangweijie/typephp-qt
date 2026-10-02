# Qt modules: what's installed, and how to link one

A Qt install ships as many independent modules. You only pay for the ones you link. This file lists what a standard desktop Qt 6.9.3 install contains, what it lacks, and the exact three edits to enable a new module in a TypePHP `project.yml`.

## Installed modules (Qt 6.9.3, msvc2022_64)

Verify on any machine with:
```bash
ls <QT_ROOT>/lib | grep -E '^Qt6.*\.lib$'        # linkable modules
ls <QT_ROOT>/include | grep -E '^Qt'             # headers
```

### Core & desktop UI

| Module | Link lib | Purpose |
|---|---|---|
| QtCore | `Qt6Core.lib` | Strings, containers, event loop, timers, file IO, settings |
| QtGui | `Qt6Gui.lib` | Window backing, painting, fonts, images, OpenGL context, clipboard |
| QtWidgets | `Qt6Widgets.lib` | Classic desktop controls (see `qt-widgets-catalog.md`) |

### Desktop UI add-ons

| Module | Link lib | Purpose |
|---|---|---|
| QtSvg / QtSvgWidgets | `Qt6Svg.lib` `Qt6SvgWidgets.lib` | Vector graphics rendering |
| QtOpenGL / QtOpenGLWidgets | `Qt6OpenGL.lib` `Qt6OpenGLWidgets.lib` | GPU rendering; the `…Widgets` variant drops into a normal layout |
| QtPrintSupport | `Qt6PrintSupport.lib` | Printing and print preview |
| QtUiTools | `Qt6UiTools.lib` | Load `.ui` files produced by Qt Designer at runtime |
| QtDesigner / QtDesignerComponents | `Qt6Designer*.lib` | Writing Qt Designer plugins (rarely needed) |

### QML / Quick

| Module | Link lib | Purpose |
|---|---|---|
| QtQml (+ QmlModels, QmlWorkerScript, QmlCore, QmlLocalStorage, QmlXmlListModel, QmlMeta, QmlNetwork) | `Qt6Qml.lib` … | The QML engine |
| QtQuick | `Qt6Quick.lib` | QML UI runtime |
| QtQuickControls2 (+ Basic / Fusion / Material / Universal / Imagine / FluentWinUI3 styles) | `Qt6QuickControls2.lib` … | Ready-made QML controls in six styles |
| QtQuickLayouts / Shapes / Particles / Effects / Templates2 / Dialogs2 / VectorImage | same-named `.lib` | QML layouts, vector shapes, particles, effects, dialogs |
| QtQuickWidgets | `Qt6QuickWidgets.lib` | **Embeds a QML scene inside a Widgets window** — the bridge between the two routes |

### Data, IO, system

| Module | Link lib | Purpose |
|---|---|---|
| QtSql | `Qt6Sql.lib` | Database access (drivers: SQLite, ODBC, PostgreSQL, Oracle, iBase, Mimer) |
| QtXml | `Qt6Xml.lib` | DOM / SAX parsing |
| QtNetwork | `Qt6Network.lib` | HTTP / TCP / UDP; TLS via Schannel on Windows |
| QtConcurrent | `Qt6Concurrent.lib` | Thread pool, map/reduce, `QFuture` |
| QtDBus | `Qt6DBus.lib` | D-Bus (Linux only — useless on Windows) |
| QtHelp | `Qt6Help.lib` | Help/documentation browser framework |

### Testing

`Qt6Test.lib`, `Qt6QuickTest.lib`.

### Bundled plugins (deployed by `windeployqt`, never linked directly)

- **Platforms:** `qwindows`, `qminimal`, `qoffscreen`, `qdirect2d`
- **Image formats:** `qgif`, `qico`, `qjpeg`, `qsvg`
- **Styles:** `qmodernwindowsstyle`
- **SQL drivers:** `qsqlite`, `qsqlodbc`, `qsqlpsql`, `qsqloci`, `qsqlibase`, `qsqlmimer`
- **TLS:** `qschannelbackend`, `qcertonlybackend`
- **Icon engines / network info / generic / designer / help / qmltooling**

## NOT installed (add via the Qt Maintenance Tool if needed)

`QtMultimedia` (audio/video), `QtWebEngine` (embedded browser), `QtCharts` / `QtDataVisualization` (charts), `QtQuick3D` / `Qt3D` (3D), `QtWebSockets`, `QtPdf`, `QtSerialPort`, `QtBluetooth`, `QtPositioning`, `QtSensors`, `QtTextToSpeech`, `QtRemoteObjects`, `QtHttpServer`, `QtGrpc`, `QtVirtualKeyboard`, `QtScxml`, `QtNetworkAuth`, `QtLocation`, `QtWebChannel`, `QtStateMachine`.

A desktop "Widgets + QML" install is exactly what you see above — it is not a minimal install, but it is not "everything" either. Check before assuming a module exists; the failure mode is a missing `.lib` at link time or a missing DLL at run time.

## Enabling a new module — three edits

Say you want **QtSql** instead of doing persistence in PHP. Add the include dir, the module define, and the link lib:

```yaml
cxx-flags:
  - '/I"<QT_ROOT>/include/QtSql"'
  - '/DQT_SQL_LIB'
ld-flags:
  - '/LIBPATH:"<QT_ROOT>/lib"'
  - Qt6Sql.lib
```

Then in C++: `#include <QtSql/QSqlDatabase>`, and in PHP use the usual `QT_SQL_LIB`-style guard only if you actually branch on it.

For **QML** modules add the module's own `.lib` **and** remember the deployment step — see `qml-route.md`. A module that only works in C++ (QtSql, QtNetwork, QtConcurrent, QtXml) needs no QML deployment at all.

### Rules of thumb

- One module = one `-I<QT_ROOT>/include/QtXxx` + one `Qt6Xxx.lib`. No other configuration.
- `Qt6EntryPoint.lib` is the `WinMain` shim for Windows GUI apps — `tpc` handles the entry, you normally don't add it by hand.
- Debug builds use the `d`-suffixed libs (`Qt6Cored.lib`). Ship release; only link debug libs if you are debugging Qt itself.
- If linking fails with `LNK2019` on a `Q…` symbol, you are missing the `.lib` for the module that class belongs to — check the class's module in the Qt docs, not just the one you assumed.
