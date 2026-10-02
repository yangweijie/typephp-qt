# FAQ

## Clicking a button crashes: `expects exactly 0 arguments, 1 given`

This is AOT's closure arity check — **the most insidious trap**, because it only fires when you click that control, while unit tests and a smoke test that clicks nothing pass.

```php
$app->on('btn', 'click', function () { ... });   // a 0-argument handler
```

The framework already handles it (it probes the parameter count at registration), so handlers registered through `on()` / `onAny()` may use either form. If you write your **own** dispatch logic, call each handler with the count it declares.

Full explanation: [AOT Notes](/advanced/aot-notes.md#closure-arity-is-validated-exactly)

## The build says `All execution code must be within a function, found stray code`

You used `require` / `require_once`. It counts as stray code at global scope **and** inside a function.

Use `project.yml`'s `sources:` list for cross-file visibility instead:

```yaml
sources:
  - src/main.php
  - ../../src/QtApp.php
  - ../../src/WidgetTree.php
```

## The build says `The PHPX runtime library was not found at: ...\phpx\build\phpx.dll`

There are two tpc installs on the machine and it picked the one **without a runtime** (the Composer driver needs a `vendor/swoole/phpx` source tree, which ships no build artifacts).

```bash
qtphp doctor   # see where the "tpc" and "PHP runtime library" lines point
```

To force a choice:

```bash
TPC=/path/to/tpc.exe qtphp build .
```

Full explanation: [The two tpc supply routes](/advanced/aot-notes.md#the-two-tpc-supply-routes)

## `--selftest` hangs and never exits

A modal dialog blocks forever with no user. Check that the app turns on headless mode before the self-test:

```php
$app->headless(true);   // message() returns its default, file dialogs return empty
```

Also check for an indirect path that missed the guard — one real case: with no tray, Qt falls back to showing `notify()` as a **modal** message box.

## The UI never updates, or I forgot `&` in `use (&$state)`

An array state must be captured by reference:

```php
$app->view(function () use ($state) { ... });    // wrong: mutates a copy
$app->view(function () use (&$state) { ... });   // right
```

Object state is passed by reference, so no `&` is needed.

## After inserting a row, the table selection jumps to a different row

You did not pass `row_ids`. Without them a row id degrades to the index (`'0'`, `'1'`, …), so inserting a row misplaces the selection:

```php
WidgetTree::table(['Name'], $rows, ['id' => 'tbl', 'row_ids' => ['r1', 'r2']]);   // right
```

See [Why you should always pass row_ids](/widgets/data.md#why-you-should-always-pass-row-ids).

## The second render crashes (`0xC0000005`)

An early version generated automatic ids with an auto-incrementing counter — the id changed every frame, so the diff saw a whole new tree and rebuilt every frame. Rebuilding deleted old widgets while the layout still held dangling pointers.

Automatic ids are now derived from the **structural path** (`_p0.1.2`), which is stable. If you extend the bridge and change id generation, keep that "derive from structure" property.

## Chinese text shows as garbage

The bridge boundary must go through UTF-8: `QString::fromUtf8` / `toUtf8`. Also check that your `project.yml` passes `/utf-8` (MSVC) or an equivalent flag.

## At runtime: `could not find or load the Qt platform plugin windows`

`windeployqt` was not run, or the plugin directory was missed when packaging.

```bash
qtphp package .   # performs the full self-check
```

## The packaged artifact will not start on another machine

Verify it by hand under a minimal environment — the only check that catches a missing dependency:

```bash
# Windows
set "PATH=C:\Windows\System32;C:\Windows"
dist\<app>.exe

# macOS / Linux
env -i QT_QPA_PLATFORM=offscreen ./myapp --selftest
```

Note the self-check must also **scan stdout**: PHP reports a missing extension as a warning written to stdout, so a clean exit code proves nothing.

## `qtphp test` is green but the compiled binary crashes

This is **expected behaviour**, not a bug.

`FakeBridge` runs on a tolerant PHP interpreter and **structurally cannot** reproduce AOT's strictness (closure arity, `require`, `global $argv`, …).

**Unit tests cover logic; `--selftest` covers compilation behaviour — you need both.**

## `qtphp lint` reports a contract mismatch

A function declared in the stub and the C++ implementation disagree. Either add the implementation (`php_<name>` in `cpp-src/*.cc`) or remove the declaration.

Note: every function declared in the stub **must be called**, or `qtphp build` aborts.

## I want a Qt widget the package does not expose

Extend the bridge. The full flow is in [Hand-written Bridge](/advanced/bridge.md#adding-a-new-control-end-to-end) — roughly:

1. Add a widget-factory branch in `cpp-src/qt_widgets.cc`
2. Add the factory method in `src/WidgetTree.php`
3. **Register it in `src/FakeBridge.php` too** (otherwise the unit tests cannot run)
4. Verify with `qtphp lint` + `qtphp build`
5. Add a case to the example's `--selftest` covering it

## The first build is very slow, or an offline build fails

On macOS/Linux the first build makes tpc compile a private embed runtime from php-src (cached under `~/.typephp`, reused afterwards).

And **tpc contacts php.net on every build** to verify the source SHA-256 — a fully offline machine fails the first time.

## Only one of my windows responds

`run()` only pumps **its own** window. Multiple windows must be pumped in turn, frame by frame:

```php
while ($app->isOpen()) {
    $app->runFrames(1);
    if ($state['log'] instanceof QtApp && $state['log']->isOpen()) {
        $state['log']->runFrames(1);
    }
}
```

See [Multiple Windows](/guide/menus-tray-timers.md).

## How do I make the app live in the tray and not quit when the window closes

The package currently treats the tray as an **add-on to a window app**. A pure tray app (no main window, lives in the background) needs a hand-written bridge and two changes:

- `QApplication::setQuitOnLastWindowClosed(false)`
- the loop condition changes from `isOpen()` to a liveness flag

See [Multiple Windows, Tray and Timers](/guide/menus-tray-timers.md#tray-apps-two-assumptions-invert).
