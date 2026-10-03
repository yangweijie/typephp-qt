# Headless Verification

A GUI app needs to prove it still works on a machine with no display and no hands. **Build two switches, not one.**

## `--shot <path>` — visual

Render a few frames, save a PNG, exit:

```php
$shot = shot_path($argv);          // reads `--shot <path>` from argv
if ($shot !== '') {
    $app->runFrames(3);
    $app->snapshot($shot);
    $app->destroy();
    return;
}
```

```bash
qtphp run . --shot out.png
```

Read the PNG back and look at it — the fastest way to confirm a layout change.

::: tip Use a command-line argument, not an environment variable
Passing an env var into a `.bat` from a shell has to survive several layers of quoting, and it is fragile. An argument is reliable. The example's `run.bat shot.png` does exactly this.
:::

## Turning the PNG into a CI baseline

A PNG you can hash is a regression test you get for free. Three things must hold, each measured
on `examples/hello`:

1. **Nothing wall-clock driven may be on screen.** The 1-second heartbeat label made consecutive
   shots differ by `(25,366)–(159,714)` until the timer registration moved *after* the `--shot`
   branch. Same for anything that prints the time.
2. **Transient animations must be settled — `snapshot()` does this for you.** Grabbing used to be
   a coin toss after only a few frames: 8 runs produced **4 distinct hashes**, all differing inside
   `(622,66)-(636,80)` — the `QLineEdit` clear button's fade animation caught mid-flight. Pumping
   120 frames hid it (at ~3 s per shot); `snapshot()` now pushes any running animation to its end
   value before grabbing, so 3 frames are enough and the frame no longer depends on wall-clock
   phase.
3. **Focus is cleared before grabbing.** A focused `QLineEdit` paints a Fusion highlight (`#7e9dc2`)
   and a caret instead of the plain `#b6b6b6` border, and which one you get is a race.
   `snapshot()` does the `clearFocus()`/restore itself.

Then verify convergence before trusting a number — the acceptance criterion is *different starting
points must converge to one frame*:

```bash
for i in $(seq 8); do ./build/hello --shot /tmp/s$i.png; done
shasum -a 256 /tmp/s*.png | awk '{print $1}' | sort -u | wc -l   # must print 1
```

Current values for `examples/hello` on Apple Silicon (they change with any UI edit — treat them as
"this build's fingerprint", not as constants):

| Platform / backend | sha256 |
|---|---|
| `cocoa`, WKWebView enabled | `2728fb1a6a0652625483166097bda44a23f051f48e629b7acf9dac793ce1e4d7` |
| `QT_QPA_PLATFORM=offscreen`, WKWebView enabled | `6c4832a6b738145ef902a131f98ff832e769d2eb5aa1485b2295502ec9b0111b` |
| `cocoa`, `-DQT_WEBVIEW_WK` commented out (QTextBrowser) | `a70cd7ae05b6f3099ee799204547ba040d35be4f93cc3bc8af4494e81a7d9bbc` |

::: warning Hashes prove "no change", one direction only
Equal hashes mean the pixels did not change. They do **not** prove a feature works: the native
webview backends paint outside Qt, so `QWidget::grab()` leaves a hole there no matter what the page
shows ([WebView](/widgets/webview.md)). Verify those with events, not pixels.
:::

## `--selftest` — behavioural

Fire every registered event once and report `ok` / `FAIL` per case:

```php
$app->headless(true);      // modal dialogs must not block — see AOT Notes
$app->run(1);

$app->dispatch(['type' => 'click', 'id' => 'greet_btn']);
$app->dispatch(['type' => 'submit', 'id' => 'name_input', 'value' => 'Ada']);
// … one dispatch per control …

echo $app->lastError() === '' ? "selftest passed\n" : "selftest failed\n";
```

```bash
qtphp run . --selftest
```

```
ok   click greet_btn
ok   submit name_input
ok   toggle dark_toggle
ok   change theme_combo
…
selftest passed
```

::: warning This is the switch that earns its keep
The [closure-arity trap](/advanced/aot-notes.md#closure-arity-is-validated-exactly) is invisible both to unit tests and to a smoke test that clicks nothing — it only fires when you actually exercise that control. `--selftest` exercises them all, headlessly, in seconds.
:::

Have the app expose the last error programmatically (`lastError()`) so the check can print **why** a case failed, not just that it did.

## `--difftest` — diff boundaries

Table/tree diff boundaries (selection, row ids, column-count changes, patches) can only run on real Qt — the diff engine and `patch()`'s `call` both live in C++, where PHPUnit cannot reach them.

```bash
qtphp run . --difftest
```

The example app's 20 assertions live in `diff_test()` inside `examples/hello/src/main.php`; write your own app's boundary assertions the same way.

## The three switches side by side

| Switch | Verifies | Runs on |
|---|---|---|
| `--shot` | layout, rendering, CJK fonts | real Qt (offscreen is fine) |
| `--selftest` | every handler is callable, AOT traps | real Qt (offscreen is fine) |
| `--difftest` | the diff engine and `patch()`'s C++ behaviour | real Qt (offscreen is fine) |

All three are platform-agnostic. To run them in a headless session (CI), set `QT_QPA_PLATFORM=offscreen`.

::: warning `--shot` under offscreen cannot check CJK text
Qt's offscreen platform uses its own font enumeration and may not find a CJK font, so
`--shot` renders Chinese/Japanese/Korean text as boxes (tofu). `--selftest` and
`--difftest` are unaffected — they never rasterise anything.

If your UI has CJK text and you want to *look* at the PNG, run `--shot` **without**
`QT_QPA_PLATFORM=offscreen` (needs a desktop session). Keep offscreen for the other two.
:::

On Windows, `qtphp build` deploys `qwindows.dll` / `qoffscreen.dll` / `qminimal.dll`
into `build/platforms/` so all of these work straight from the dev artifact.

## What headless mode does

`headless(true)` makes every blocking call return immediately without touching Qt's modal API:

| Call | In headless mode |
|---|---|
| `message()` / `alert()` / `error()` | returns `spec['default']` (or `'ok'`) |
| `confirm()` | returns `false` |
| `openFile()` / `saveFile()` | returns `[]` |
| `pickDirectory()` | returns `''` |
| `notify()` | returns immediately (with no tray it would otherwise fall back to a modal box) |

Without it, any dialog hangs CI forever.

## Running in CI

```bash
# compile
qtphp build .

# behavioural check (no display)
QT_QPA_PLATFORM=offscreen qtphp run . --selftest

# visual check (archive the PNG as a build artifact, for a human or a pixel diff)
QT_QPA_PLATFORM=offscreen qtphp run . --shot out.png

# unit tests (no Qt, no compiler)
qtphp test

# contract check
qtphp lint
```

## Verifying the packaged artifact

The packaged artifact must be verifiable headlessly too — that is what proves "packaging missed nothing".

```bash
# macOS: the bundle must carry the offscreen plugin itself
env -i QT_QPA_PLATFORM=offscreen PATH=/usr/bin:/bin HOME="$HOME" \
    dist/MyApp.app/Contents/MacOS/myapp --selftest

# Linux: the bundle already contains the whole platforms/ directory
cd dist/myapp && env -i QT_QPA_PLATFORM=offscreen ./myapp --selftest
```

`env -i` clears the environment so nothing leaks in from the build machine — the same idea as reducing `PATH` to `C:\Windows\System32` on Windows.

::: warning A macOS gotcha
`macdeployqt` only copies the platform plugin for the target platform — a bundle carrying only `libqcocoa.dylib` is aborted outright by Qt (rc=134) when `QT_QPA_PLATFORM=offscreen` is set.

`qtphp package` copies `libqoffscreen.dylib` into `Contents/PlugIns/platforms/` and rewrites its Qt references automatically (about +156 KB). See [Packaging](/reference/packaging.md).
:::

## How unit tests and headless verification divide the work

| | `qtphp test` (PHPUnit + FakeBridge) | `--selftest` (real AOT binary) |
|---|---|---|
| Needs Qt | ❌ | ✅ |
| Needs a compiler | ❌ | ✅ (already compiled) |
| Speed | milliseconds | seconds |
| Catches AOT traps | ❌ **structurally cannot** | ✅ |
| Catches logic bugs | ✅ | generally |

**You need both.** The former is fast and covers domain logic; the latter is the only check that proves the compiled artifact actually works.
