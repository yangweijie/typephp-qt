# AOT pitfalls — the traps that only exist in a compiled binary

TypePHP compiles PHP ahead-of-time to native code. The compiler is stricter than the interpreter, so code that passes `php -l`, PHPUnit, and a full run under the normal interpreter can still fail in the compiled binary — sometimes only when a particular button is clicked.

**The uncomfortable corollary: a green unit-test suite proves nothing about this file.** The package's own `FakeBridge` runs on a tolerant interpreter, which is exactly why it cannot reproduce rule 1 below. Every trap here was found by running the real AOT binary.

Read this before your first compiled run.

---

## 1. Closure arity is validated exactly

**Symptom** — the app builds, launches, and looks fine; clicking one specific button (or a menu item) throws:

```
stdClass::{closure}() expects exactly 0 arguments, 1 given
```

**Cause** — the Zend engine silently ignores extra arguments to user functions. AOT-compiled closures do not: they check the count and raise `ArgumentCountError`. A dispatcher that calls every handler as `$handler($event)` therefore breaks every handler declared `function () {...}`.

This is the worst trap in the set because it is **latent**: nothing fails until the exact control is exercised, so a smoke test that clicks nothing passes.

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

// dispatch
if ($arity <= 0) {
    $handler();
} else {
    $handler($event);
}
```

Reflection **is** supported under AOT, and `getNumberOfRequiredParameters()` returns the right answer for closures, `[object, 'method']` arrays, and function-name strings alike. Fall back to arity 1 if reflection throws.

**Why not "call with the event, retry without on failure"?** Using exceptions as control flow re-runs the first half of a handler that has already mutated state, and it misreads a genuine `ArgumentCountError` raised *inside* a handler as an arity mismatch.

**Test it** — a `--selftest` switch that dispatches every registered event once is the only check that catches this. See `SKILL.md`.

---

## 2. Global scope takes declarations only — `require_once` included

**Symptom**

```
All execution code must be within a function, found stray code
```

**Cause** — the compiler wants the global scope to contain declarations and nothing else. `require_once` is a *statement*, so it counts as stray code — at global scope **and** inside a function. There is no working `require` anywhere.

**Fix** — stop using `require` for cross-file visibility. List the files (or their directories) in `project.yml`'s `sources:` and the compiler makes every declaration visible:

```yaml
sources:
  - main.php
  - app
  - php-src
  - cpp-src
```

This surprises people coming from Composer-autoloaded PHP. The package's `qtphp` CLI generates a correct `sources:` list for you.

---

## 3. `main()` is a global function, and `global $argv` crashes

**Symptom** — either a missing entry point, or a hard crash (`0xC0000409`, stack buffer overrun) with no PHP-level message.

**Fix** — declare it at global scope with this exact signature, and read arguments from the parameter:

```php
function main(int $argc, array $argv): void
{
    // use $argv here; never `global $argv`
}
```

`global $argv` is the crash. If you need a flag check, iterate `$argv` starting at index 1.

---

## 4. Closure parameters need explicit types

**Symptom** — `The variable $event is undefined` at runtime, inside a closure that plainly takes that parameter.

**Fix** — annotate the type:

```php
$app->on('btn', 'click', function (array $event) { /* … */ });   // not function ($event)
```

The compiler infers parameter types; an unannotated parameter is not bound.

---

## 5. Modal dialogs block forever with no user

**Symptom** — a headless run (`--selftest`, CI, a build server) hangs. The process never exits and prints nothing after the point of the dialog. A stack sample shows `QDialog::exec` waiting inside a nested event loop.

**Cause** — `QMessageBox::exec()`, `QMessageBox::question()`, `QFileDialog::getOpenFileName()` and friends spin their own loop until a human interacts. There is no human.

**Fix** — a headless flag that makes every blocking dialog return a default without touching Qt's modal API:

```php
$app->headless(true);   // message() returns its `default`; file dialogs return empty
```

Anything that can reach a dialog needs the guard — including indirect paths. One real case: with no system tray available, Qt falls back to showing `QSystemTrayIcon::showMessage` as a **modal** message box, so a `notify()` call became the one blocking call that was missed.

---

## 6. Reflection: one variable, one type

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

---

## 7. `Array*` implicitly converts to `bool`

**Symptom** — at runtime, `parameter 1 must be of type array, bool given`, from a bridge function you passed an array to.

**Cause** — `php::Array` has an implicit conversion to `bool`. Passing `&$spec` where the parameter is a pointer-typed value yields `Array(true)` — "an array whose value is a bool" — not a reference.

**Fix** — drop the `&` when passing an `array`-typed value through the bridge.

---

## 8. Bridge calls from a namespaced file need a leading `\`

**Symptom** — compile error about an unresolved identifier, or a generated `php_qt_foo(...)` call with no global qualification.

**Fix** — the bridge functions are global. In a namespaced file write `\qt_foo(...)`.

---

## The two `tpc` supply routes (Windows)

A machine can have two `tpc` installs, and their runtimes come from completely different places. Mixing them fails at configure time, not compile time.

| | Native release package | Composer driver |
|---|---|---|
| Location | unzipped dir, e.g. `tpc_v0.9.4_windows_x64/tpc.exe` | `vendor/bin/tpc.php` → `vendor/swoole/typephp/bin/tpc.php` |
| Runtime | **self-contained** — `phpx.dll` / `SDK/` sit beside the executable | the `vendor/swoole/phpx` **source tree** |
| Usable as-is | yes, unzip and go | no — that tree ships no `build/phpx.dll` or `lib/phpx.lib`; they must be built with the phpx toolchain first |

**Symptom of the wrong pick**

```
Fatal error: The PHPX runtime library was not found at:
  ...\phpx\build\phpx.dll
Build PHPX first (for example, run `nmake phpx` in ...\phpx\build)
```

**Why** — `Windows.php`'s `getBuildLibraryWarnings()` checks `<phpxDir>\build\phpx.dll` and `<phpxDir>\lib\phpx.lib`, and `<phpxDir>` comes from `PhpxLocator::resolve()`, which looks for **`vendor/swoole/phpx`** (overridable with `PHPX_HOME`).

**Fix** — do not hard-order the two; both are legitimate on their own platform (macOS/Linux full builds rest on the Composer driver plus a privately built runtime). Instead **probe for a runtime**: prefer a candidate that has one, and only fall back to a runtime-less candidate if nothing does. The `qtphp` CLI does this; `TPC` / `TPC_DIR` force a specific compiler and skip the probe. `qtphp doctor` prints both the chosen `tpc` and its runtime dir — check those two lines first when a build fails this way.

---

## Debugging discipline

- **Do not trust a passing unit test for these traps.** Reproduce with the real binary.
- **Injecting diagnostics can create the bug you are hunting.** A marker injected into a source file is a code change; if it breaks a string literal or a structure, you will spend rounds chasing a fault you just introduced. After injecting, run once and confirm the markers appear *before* drawing conclusions — and prefer a real edit over scripted text substitution. (`php -l` passing does not mean the semantics survived.)
- **"It worked last time" is not proof of a regression.** It can be environment resolution picking a different toolchain — the two-`tpc` case above is exactly that.
- **A crash with no output at all** usually means it died before PHP flushed anything, or before the branch you think it reached. Write markers to a file with `FILE_APPEND` rather than relying on buffered stdout.
