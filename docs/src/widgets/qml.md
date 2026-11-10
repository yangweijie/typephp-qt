# QML

`WidgetTree::qml()` embeds a QML component in your window via a `QQuickWidget`. Like the
[web view](/widgets/webview.md), the feature is switched on **at compile time** — your PHP
code is identical either way, and when the switch is off the control degrades to a labeled
placeholder instead of a blank box.

| | When `QT_QML` is defined | When it is not |
|---|---|---|
| Control | `QQuickWidget` loading a `.qml` component | A placeholder label saying QML is not enabled, showing the control's id and how to turn it on |
| Properties / read-back | work | all no-ops (`value()` returns an empty array) |
| Signal events | wired into the event queue **synchronously** — the bridge relays each signal through a real Qt slot (an invisible button's `click()`, see [Events](#events)), so one emission is one event with no frame delay; argumentless signals only | all no-ops |
| `qmlAvailable()` | `true` | `false` |
| `qmlStatus('id')` | the component's real status | `['status' => 'error', …]` with one diagnostic |

## The control

```php
WidgetTree::qml('assets/clock.qml', ['id' => 'clock']);
```

It is a **leaf**: the node's `children` are ignored — QML organizes its own internal structure,
and the framework does not grow Qt Quick nodes inside your PHP tree. Give the node an `id`,
like every other control that updates.

### The four dedicated keys

| Key | Meaning |
|---|---|
| `source` | The `.qml` file to load. Without a scheme it is resolved against the executable's directory (same contract as the web view's `url`); with a scheme (`file://` etc.) it is used as-is |
| `signals` | List of signal names exposed by the component's **root object**. Each is wired into the event queue — see [Events](#events) |
| `props` | An associative array written onto the root object's dynamic properties (e.g. `['timeText' => '12:00:00']`) |
| `read` | List of property names to read back. Both the event `payload` and `value('id')` are a **snapshot** of exactly these properties |

Every other key follows the usual widget-tree semantics (`id` `visible` `enabled` `style` `size` `grow` …).

A working shape:

```php
WidgetTree::qml('assets/clock.qml', [
    'id'      => 'clock',
    'signals' => ['tick'],
    'props'   => ['timeText' => $state['time']],
    'read'    => ['timeText'],
    'grow'    => 1,
]);
```

The component you point `source` at is plain QML: its root object can expose properties and
signals, and that is the entire contract with PHP.

```qml
Rectangle {
    id: root
    property string timeText: "00:00:00"
    signal tick

    Text { text: timeText; anchors.centerIn: parent }
    Timer { interval: 1000; repeat: true; running: true
            onTriggered: { root.timeText = new Date().toTimeString().slice(0, 8); root.tick(); } }
}
```

## Enabling it

You must **opt in per app**. The framework ships with it off: `qt_qml.cc` always compiles, but
without the switch it builds only the diagnostic placeholder.

Adding it takes three Qt modules — `QtQml`, `QtQuick`, `QtQuickWidgets` — on top of the usual
`Core` / `Gui` / `Widgets` / `Network`.

```yaml
# project.yml (Windows): cxx-flags / link-paths / link-libs
cxx-flags:
  - /DQT_QML
  - /I"…/Qt/include/QtQml"
  - /I"…/Qt/include/QtQuick"
  - /I"…/Qt/include/QtQuickWidgets"
link-libs:
  - Qt6Qml.lib
  - Qt6Quick.lib
  - Qt6QuickWidgets.lib
```

```yaml
# project.macos.yml: cxx-flags / ld-flags
cxx-flags:
  - -DQT_QML
  - -I/opt/homebrew/opt/qtbase/lib/QtQml.framework/Headers
  - -I/opt/homebrew/opt/qtbase/lib/QtQuick.framework/Headers
  - -I/opt/homebrew/opt/qtbase/lib/QtQuickWidgets.framework/Headers
ld-flags:
  - -Wl,-framework,QtQml
  - -Wl,-framework,QtQuick
  - -Wl,-framework,QtQuickWidgets
```

```yaml
# project.linux.yml: cxx-flags / ld-flags
cxx-flags:
  - -DQT_QML
  - -I/usr/include/x86_64-linux-gnu/qt6/QtQml
  - -I/usr/include/x86_64-linux-gnu/qt6/QtQuick
  - -I/usr/include/x86_64-linux-gnu/qt6/QtQuickWidgets
ld-flags:
  - -lQt6Qml
  - -lQt6Quick
  - -lQt6QuickWidgets
```

::: warning The QML files are not deployed for you
`source` is just a path to the runtime, and packaging does not copy your `.qml` files next to
the binary. For a runnable package, ship them the same way you ship assets — e.g. under
`assets/` — and point `source` at `assets/your-component.qml` so it resolves against the
executable's directory.
:::

The **QML modules themselves** (the QtQuick engine side) need no extra packaging on macOS:
`qtphp package` was verified self-contained by hiding every developer-machine QML import path
and running the bundle headless — the QML type registration is embedded in the QtQuick
framework, and the engine reads `qmldir` from its built-in resources (F55). The dynamic
`qml/` plugin tree is a Windows packaging question and is still unverified there.

## Events

Each name in `signals` is looked up on the component's root object and, when found, every
emission queues an event through the usual loop:

```php
['type' => 'qml', 'id' => 'clock', 'value' => 'tick', 'payload' => ['timeText' => '12:00:01']]
```

- `value` is the **signal name** — handlers dispatch on it.
- `payload` is a snapshot of the `read` properties, taken at emission time. Signal **arguments
  are not passed through** in v1; that is why `read` exists. (The bridge's event queue carries a
  string value plus a flat payload, so structured arguments would need a new conversion path.)

The bridge has no moc toolchain, so it cannot declare new slots the normal way. It relays each
declared signal through a **real moc'd slot** instead: `QObject::connect(root, signal, relay,
click())`, where the receiver is an invisible `QPushButton` parented to the control and `click()`
is a slot of its own metaobject. Invoking it emits `clicked()` synchronously, and a lambda
forwards that into the event queue — one emission, one event, zero frame delay. Two behaviors
of Qt matter here and are pinned by probes: there is **no** `connect(QMetaMethod, functor)`
overload (a receiver metaobject is unavoidable), and Qt does **not** deduplicate repeated
connections (the bridge keeps a wired-set per component and refuses to wire the same signal
twice). Failures stay loud: a signal name that does not exist on the root, or one with
parameters (v1 wires argumentless signals only), is recorded in `qmlStatus()`'s error list
and, with `QT_DEBUG_STRICT=1`, warned on stderr.

```php
$app->on('clock', 'tick', function (array $event) use (&$state, $app) {
    $state['time'] = $event['payload']['timeText'];
});
```

A signal name that does not exist on the root object is not dropped: it is recorded in the
component's error list (readable via `qmlStatus()`) and, when `QT_DEBUG_STRICT=1` is set,
warned on stderr.

## Reading and writing the component

| Call | What it does |
|---|---|
| `$app->value('clock')` | A snapshot of the `read` properties (empty array when `read` is empty). `props` writes take effect on the next frame, so this is the headless way to verify a property round-trip |
| `qmlReload('clock')` | Imperative action via `patch`: discards the root object and reloads `source` |

Writing properties goes through the declarative `props` key; the component's own animation or
timer keeps changing them, and you always read the current state through `value()`.

## Capability check and error channel

```php
if ($app->qmlAvailable()) {
    // full-featured branch
} else {
    // the build did not define QT_QML: show a fallback
}

$status = $app->qmlStatus('clock');
// ['status' => 'none'|'loading'|'ready'|'error',
//  'errors' => [['line' => int, 'message' => string], …]]
// [] when the control does not exist or is not a qml type
```

::: warning Do not gate on `status === 'ready'` in headless tests
On the offscreen platform the component's status can stay `loading` forever — that is the
platform's behavior, not a failure. The reliable acceptance criteria are **events arriving +
properties reading back**, never the status string. (This is why `qt_qml_status()` is an
informational channel: a QML load failure is still surfaced, loudly — a blank component with
zero diagnostics is the worst kind of defect — it just is not a gate.)
:::

`qmlStatus()` returns `[]` for a non-existent or non-qml id, and the C++ side reports
`status => 'error'` with one "not defined at build time" diagnostic when the app was built
without `QT_QML`.

## Caveats

::: warning `--shot` cannot capture the component
`QQuickWidget` renders into its own renderer, outside Qt's widget painting — `QWidget::grab()`
(which `--shot` uses) does not capture it. Verify a QML component by its **events and read-back
properties**, never by pixels. (`--selftest` and `--difftest` dispatch events and read
`value()`, so they work headless; `--shot` does not.)
:::

::: tip Give it a height
A `QQuickWidget` contributes nothing to Qt's layout size calculation, so a container that cannot
expand will collapse it to a thin strip. Give it `'grow' => 1` inside a container that can
expand, the same advice as for the web view.
:::

::: warning QML load failures are reported, not silent
A component that fails to load (bad file, compile error) records the per-line errors in the
component's state (readable via `qmlStatus()`) and — with `QT_DEBUG_STRICT=1` — warns on
stderr. The degraded placeholder (when `QT_QML` is not defined at all) shows diagnostic text
instead of a blank box. Read the errors with `qmlStatus()` rather than guessing from the UI.
:::

## Related

- [WebView](/widgets/webview.md) — the sibling embed control
- [Widget Catalog](/widgets/) — the other controls
- [Properties](/guide/properties.md) — common properties (`grow`, `size`, …)
- [Events](/guide/events.md) — the event model in general
