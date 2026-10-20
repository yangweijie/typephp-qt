# Pitfalls

A checklist of the traps that cost real debugging time, roughly in the order you are likely to meet them. Each one has a symptom first, so you can scan for what you are seeing right now.

Most of these are *silent* by design — the framework ignores what it does not understand instead of throwing. When a symptom does not match anything here, turn on strict mode and re-run:

```bash
# Windows
$env:QT_DEBUG_STRICT = "1"
# macOS / Linux
QT_DEBUG_STRICT=1 ./build/app
```

Strict mode makes unknown widget types, unknown properties and unknown `patch()` methods print a warning to stderr. In normal builds they are dropped without a sound.

## Widgets disappear or flicker as the page grows

**Symptom.** Short pages are fine. As the page gets longer — or as soon as a list, table or conditional branch changes size — parts of the UI go blank, or flicker, or a control you did not touch loses focus.

**Cause.** The node has no `id`. Un-identified nodes get an implicit id derived from their position (`_p<path>`), and that path is only stable *within one frame*. Insert or remove an earlier sibling and every following anonymous node gets a new id — so the next frame rebuilds them from scratch instead of updating them. The old widgets fall out of the layout.

**Fix.** Give every child inside a dynamic container (list rows, table rows, tree nodes, conditional branches) an explicit `id`. An id is the identity of the widget across frames; changing it is the same as throwing the widget away. See [Diff Engine](/advanced/diff-engine.md) for the full picture.

## You changed a widget with `patch()` and the next render reverted it

**Symptom.** You call `patch(['op'=>'call', 'id'=>'t', 'method'=>'appendRows', ...])`, rows appear — then the next `render()` puts the old content back.

**Cause.** `patch()` is an imperative bypass: it changes the widget, not the tree. The tree is still the source of truth, and the diff sees "the tree says X, the widget says Y" and re-applies the tree.

**Fix.** This is not a bug to work around — it is the contract. Either update your own state *and* the tree so the next frame agrees, or keep using the imperative call every frame as a fast path. The bridge automatically invalidates the diff signature of the properties the call touched, so the next `render()` re-syncs from the tree instead of trusting a stale signature. You never call `forgetProps()` yourself — it is internal to the bridge.

## A widget type or property seems to do nothing

**Symptom.** You add a property or a control type and the app runs, but nothing changes. No error.

**Cause.** Unknown control types, unknown properties and unknown `patch()` methods are **silently ignored**. That is deliberate: a typo in one property must not take down the whole app.

**Fix.** Run with `QT_DEBUG_STRICT=1` and look at stderr. Check the property name against [Properties](/guide/properties.md) — the usual culprits are using a container property on a leaf widget, or `value` on a widget that reads `text`.

## A large table re-renders on every frame

**Symptom.** CPU climbs with row count. Scrolling a 5,000-row table is heavy even though nothing changed.

**Cause.** `rows` / `nodes` are structural: their signature is computed every frame, and a changed signature rebuilds the widget. For big data this dominates the budget.

**Fix.** Append incrementally instead of rebuilding:

```php
$app->patch([[ 'op' => 'call', 'id' => 't', 'method' => 'appendRows', 'args' => [$newRows] ]]);
```

Remember the bypass rule above: the tree still has to agree, or the next frame will undo it. See [Performance](/advanced/performance.md).

## The tray icon freezes a headless run

**Symptom.** `--selftest` or a CI run hangs forever with no window.

**Cause.** When there is no system tray, `qt_window_notify()` degrades to a **modal dialog**, which blocks until a human clicks it. Headless mode has no human.

**Fix.** Skip notifications in headless mode (`$app->headless(true)` makes `show()` / `notify()` no-ops), or guard the call yourself:

```php
if (!$app->headless()) {
    $app->notify('Build finished');
}
```

## A namespaced file's bridge call is "undefined"

**Symptom.** `\qt_...()` works in `main`, but calling the same function from a file with a `namespace` fails to resolve.

**Cause.** The bridge functions are global. Inside a namespace, an unqualified call looks in the current namespace first.

**Fix.** Always call bridge functions with a leading backslash: `\qt_window_focus($w)`.

## Adding a bridge function broke `qtphp lint`

**Symptom.** After adding a `php_*` function, `qtphp lint` reports a contract mismatch.

**Cause.** Every bridge API must exist in **both** places: the stub in `php-src/qt.stub.php` with an *empty body* `{}`, and the implementation in `cpp-src/*.cc` with the `php_` prefix. The linter compares them.

**Fix.** Add the stub declaration first, then the C++ implementation. If PHP-side unit tests then fail with "function does not exist", you also forgot the matching stub in `src/FakeBridge.php`. See [Hand-written Bridge](/advanced/bridge.md) for the four-part recipe.

## The window closes but the process stays alive

**Symptom.** You close the window and the process keeps running in the task manager.

**Cause.** A tray app sets `setQuitOnLastWindowClosed(false)` so closing the window does not quit — the loop must be told to exit some other way.

**Fix.** Drive the main loop on `is_alive` (your own flag), not `is_open`. Set the flag when the user actually asks to quit (tray "Quit" item, menu action), and the loop ends.

## The second window never repaints

**Symptom.** With two `QtApp` instances, the first window updates and the second is frozen — or they take turns badly.

**Cause.** `run()` pumps only its own window's events. Two independent `run()` calls means one loop starves the other.

**Fix.** Drive the frames yourself: call `runFrames(1)` on each app in turn, once per tick. See [Multiple Windows, Tray and Timers](/guide/menus-tray-timers.md).

## The compiled binary cannot find Qt DLLs or plugins

**Symptom.** The exe builds but exits immediately, or starts with no usable window, complaining about `qwindows.dll` or a missing platform plugin.

**Cause.** The Qt runtime — the DLLs plus the `platforms/` plugin directory — is not next to the binary.

**Fix.** Use `qtphp run` (which sets the paths) or `qtphp package` (which copies the runtime and writes `qt.conf`). When you build by hand, deploy `qwindows`, `qoffscreen` and `qminimal` into `build/platforms/`. On Windows, `--no-console` is what produces a GUI subsystem binary.

## HTTPS fails in the packaged app

**Symptom.** Everything works in development; after packaging, every HTTPS request from `qt_http_request()` returns an error.

**Cause.** The package was built with the `tls` / `networkinformation` plugins skipped.

**Fix.** Do **not** skip them. They are not optional if the app talks to the network — skipping saves a little size and breaks all HTTPS. `--no-opengl-sw` is the safe size saving; that one only drops the 20 MB software rasterizer.

## The project builds locally but not on the CI machine

**Symptom.** `tpc` refuses the `project.yml`, or MSVC rejects a flag.

**Cause.** nano validates the C++ standard strictly, and MSVC needs explicit flags for Qt.

**Fix.** Use **`c++17`** in the example/application `project.yml` (the root library config uses `c++20`). On MSVC, Qt requires `/Zc:__cplusplus /permissive-`. On Windows, WebView2 needs `third_party/webview2/x64/WebView2Loader.dll.lib` present — a fresh clone that drops it fails with `LNK1181` (the repo's `.gitignore` keeps that file on purpose).

## Related

- [AOT Notes](/advanced/aot-notes.md) — the traps that only appear in a compiled binary.
- [Diff Engine](/advanced/diff-engine.md) — why ids matter and what `patch()` really bypasses.
- [Performance](/advanced/performance.md) — what one frame costs, and how to measure it.
