# AOT Notes

TypePHP compiles PHP ahead of time to native code. **The compiler is stricter than the interpreter** — code that passes `php -l`, passes PHPUnit, and runs end to end under the interpreter can still fail in the compiled binary.

::: danger The uncomfortable corollary
**A green unit-test suite proves nothing about any of this.** The package's own `FakeBridge` runs on a tolerant interpreter, so it **structurally cannot** reproduce trap 1 below. Every trap on this page was found by running the real AOT binary.
:::

## Closure arity is validated exactly

**Symptom** — the app compiles, launches, and looks right; clicking **one specific** button (or menu item) throws:

```
stdClass::{closure}() expects exactly 0 arguments, 1 given
```

**Cause** — the Zend engine **silently ignores** extra arguments to user functions. AOT-compiled closures do not: they check the count and raise `ArgumentCountError`. So a dispatcher that calls every handler as `$handler($event)` breaks every handler declared `function () {...}`.

**Why this is the worst one** — it is **latent**: nothing fails until you click that control. A smoke test that clicks nothing passes completely.

**Fix** — call each handler with the number of arguments it declares. Probe once at registration time:

```php
private function arityOf(callable $handler): int
{
    if (is_array($handler)) {
        $methodRef = new \ReflectionMethod($handler[0], $handler[1]);
        return $methodRef->getNumberOfRequiredParameters();
    }
    $functionRef = new \ReflectionFunction($handler);
    return $functionRef->getNumberOfRequiredParameters();
}
```

The framework already does this (`QtApp::on()` stores `[handler, arity]` and dispatches with the actual count), so handlers registered through `on()` / `onAny()` may declare either form. **But keep it in mind for your own dispatch logic.**

::: tip Reflection is supported under AOT
`getNumberOfRequiredParameters()` returns the right answer for closures, `[object, 'method']` arrays and function-name strings alike. No need to work around reflection.
:::

**How to test it** — a `--selftest` switch that fires every registered event once is the only check that catches this. See [Headless Verification](/advanced/headless.md).

## `require` is unusable (`require_once` too)

**Symptom**

```
All execution code must be within a function, found stray code
```

**Cause** — the compiler wants the global scope to hold **declarations only**. `require_once` is a **statement**, so it counts as stray code — at global scope **and** inside a function.

**Fix** — cross-file visibility comes from `project.yml`'s `sources:` list, not from `require`:

```yaml
sources:
  - src/main.php
  - ../../src/QtApp.php
  - ../../src/WidgetTree.php
  - ../../php-src/qt.stub.php
  - ../../cpp-src/qt_bridge.cc
```

This surprises people arriving from Composer autoloading. The `sources:` list `qtphp new` generates is correct.

## `main()` is a global function, and `global $argv` crashes

**Symptom** — either a missing entry point, or a hard crash (`0xC0000409`, stack buffer overrun) with no PHP-level error.

**Fix** — declare it at global scope with the exact signature:

```php
function main(int $argc, array $argv): void
{
    // use $argv; never `global $argv`
}
```

`global $argv` is the crash. To check a flag, iterate `$argv` starting at index 1:

```php
function has_flag(array $argv, string $flag): bool
{
    $count = count($argv);
    for ($i = 1; $i < $count; $i++) {
        if ($argv[$i] === $flag) {
            return true;
        }
    }
    return false;
}
```

## Closure parameters need explicit types

**Symptom** — `The variable $event is undefined` at runtime, inside a closure that plainly takes that parameter.

**Fix** — annotate the type:

```php
$app->on('btn', 'click', function (array $event) { /* … */ });   // not function ($event)
```

The compiler infers parameter types; an unannotated parameter is not bound.

## A modal dialog blocks forever with no user

**Symptom** — a headless run (`--selftest`, CI, a build server) hangs. The process never exits and prints nothing after the dialog. A stack sample shows `QDialog::exec` waiting inside a nested event loop.

**Cause** — `QMessageBox::exec()`, `QMessageBox::question()`, `QFileDialog::getOpenFileName()` and friends spin their own loop until a human interacts. There is no human.

**Fix** — a headless flag that makes every blocking dialog return a default without touching Qt's modal API:

```php
$app->headless(true);   // message() returns its default; file dialogs return empty
```

**Anything that can reach a dialog needs the guard**, including indirect paths. One real case: with no system tray available, Qt falls back to showing `QSystemTrayIcon::showMessage` as a **modal** message box — so `notify()` became the one blocking call that was missed.

## Reflection: one variable, one type

**Symptom**

```
Cannot re-assign typed object $ref from ReflectionMethod to ReflectionFunction
```

**Fix** — the compiler fixes a variable's inferred type at first assignment. Use two variables:

```php
if (is_array($handler)) {
    $methodRef = new \ReflectionMethod($handler[0], $handler[1]);
    return $methodRef->getNumberOfRequiredParameters();
}
$functionRef = new \ReflectionFunction($handler);
return $functionRef->getNumberOfRequiredParameters();
```

Reflection itself works fine under AOT — this is purely a type-inference constraint on the variable, not a missing feature.

## `Array*` implicitly converts to `bool`

**Symptom** — at runtime, `parameter 1 must be of type array, bool given`, from a bridge function you plainly passed an array to.

**Cause** — `php::Array` has an implicit conversion to `bool`. Passing `&$spec` where the parameter is a pointer-typed value yields `Array(true)` — "an array whose value is a bool" — not a reference.

**Fix** — drop the `&` when passing an `array`-typed value.

## Bridge calls from a namespaced file need a leading `\`

**Symptom** — a compile error about an unresolved identifier, or a generated `php_qt_foo(...)` call with no global qualification.

**Fix** — the bridge functions are global. In a namespaced file write `\qt_foo(...)`.

## The two tpc supply routes

A machine can have two `tpc` installs, and their runtimes come from completely different places. Mixing them fails at configure time.

| | Native release package | Composer driver |
|---|---|---|
| Location | an unzipped dir, e.g. `tpc_v0.9.4_windows_x64/tpc.exe` | `vendor/bin/tpc.php` → `vendor/swoole/typephp/bin/tpc.php` |
| Runtime | **self-contained** — `phpx.dll` / `SDK/` sit beside the executable | the `vendor/swoole/phpx` **source tree** |
| Usable as-is | yes, unzip and go | no — that tree ships no `build/phpx.dll` or `lib/phpx.lib`; they must be built with the phpx toolchain first |

**Symptom of the wrong pick**

```
Fatal error: The PHPX runtime library was not found at:
  ...\phpx\build\phpx.dll
Build PHPX first (for example, run `nmake phpx` in ...\phpx\build)
```

`<phpxDir>\build\phpx.dll` and `<phpxDir>\lib\phpx.lib`, and `<phpxDir>` comes from `PhpxLocator::resolve()`, which looks for **`vendor/swoole/phpx`** (overridable with `PHPX_HOME`).

**How `qtphp` handles it** — it does not hard-order the two (both are legitimate on their own platform: the macOS/Linux full builds rest on the Composer driver plus a privately built runtime). Instead it **probes for a runtime**: it prefers a candidate that has one, and only falls back otherwise.

```bash
qtphp doctor   # prints the resolved tpc and runtime dir — check these two lines first when a build fails
```

To force a choice:

```bash
TPC=/path/to/tpc.exe   qtphp build .   # skip the probe
TPC_DIR=/path/to/dir   qtphp build .
```

## Debugging discipline

- **Do not trust unit tests for these traps.** Reproduce with the real binary.
- **Injecting diagnostics can create the bug you are hunting.** A marker you inject is a code change; if it breaks a string literal or a structure, you will spend rounds chasing a fault you just introduced. After injecting, run once and confirm the markers appear **before** drawing conclusions — and prefer a real editor edit over scripted text substitution. (`php -l` passing does not mean the semantics survived.)
- **"It worked last time" is not proof of a regression.** It can be environment resolution picking a different toolchain — the two-tpc case above is exactly that.
- **A crash with no output at all** usually means it died before PHP flushed anything, or before the branch you think it reached. Write markers to a file with `FILE_APPEND` rather than relying on buffered stdout.
