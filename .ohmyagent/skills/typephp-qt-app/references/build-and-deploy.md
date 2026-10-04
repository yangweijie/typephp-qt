# Build, deploy and run

Compiling is only half the job. A Qt app needs its DLLs **and its plugins** beside the binary at run time, and an embedded PHP runtime needs to know where its extensions are. This file is the per-platform recipe plus the gotchas that cost the most time.

The workflow is identical everywhere: **compile with `tpc` → deploy Qt with `windeployqt` → run with the runtime ini.**

## If you are using the `typephp-qt` package, skip this file

The package's CLI does all of the below, on all three platforms, including the packaging self-check:

```bash
qtphp doctor            # verify PHP / tpc / Qt / C++ compiler / PHPUnit, and print the resolved tpc + runtime dir
qtphp build .           # picks the right entry yml per platform; deploys runtime DLLs on Windows
qtphp build . --nano    # nano mode: php-nano + PHPX are compiled into the artifact — it links NO PHP runtime
qtphp run . [args…]     # runs the artifact, args pass through; does a dependency self-check first
qtphp package .         # self-contained bundle + self-check
```

`--nano` is the size lever when the client complains about a 25 MB binary: the artifact drops to ≈4.4 MB (Apple Silicon measured, `-O2`) and a packaged `.app` from 83.6 to 64.1 MiB. No extra yml is needed — nano ignores a `php-builder:` section, and the package templates already pin `cxx-std: c++17` (nano rejects `c++20`) plus `optimize: 2`. **Only Apple Silicon has been verified on real hardware**; Windows/Linux pass the flag through untested. What nano cannot shrink is Qt: ICU alone is ~56% of the `.app`.

What follows is the manual route — read it when you are hand-writing the bridge (SKILL.md route 1), when you need to understand what `qtphp` is doing, or when a package build fails and you have to drop a level.

### Which `tpc` is being used

Two distributions exist and their runtimes differ — a native release package is self-contained, while the Composer driver needs a `swoole/phpx` source tree whose build artifacts usually do not exist yet. Choosing the wrong one fails with `The PHPX runtime library was not found at: ...\phpx\build\phpx.dll`. `qtphp doctor` prints the resolved `tpc` and runtime dir; `TPC` / `TPC_DIR` override the choice. Full explanation in `references/aot-pitfalls.md`.

## Windows

### Prerequisites

- MSVC 2022 (or BuildTools) — the Qt SDK is built against MSVC, so MinGW will not link against it.
- Qt 6 MSVC x64 SDK.
- The TypePHP package, with `PHP_HOME` and `PHPX_HOME` pointing at it (`PHPX_HOME` → the bundled `phpx` dir, which must contain `include/`, `lib/phpx.lib`, `src/`).

### Step 1 — MSVC environment

```bat
call "C:\Program Files (x86)\Microsoft Visual Studio\2022\BuildTools\VC\Auxiliary\Build\vcvars64.bat"
```

Without this, `cl.exe` is not on `PATH` and the build fails immediately.

### Step 2 — environment variables

```bat
set "PHP_HOME=D:\git\php\tpc_v0.9.4_windows_x64"
set "PHPX_HOME=D:\git\php\tpc_v0.9.4_windows_x64\phpx"
set "PATH=<QT_ROOT>\bin;%PHP_HOME%;%PATH%"
```

### Step 3 — a `project.yml` for this machine

`project.windows.yml` in the repo carries **placeholder Qt paths** (`D:/workspace/qt/6.8.3/…`). Always replace them with the real `<QT_ROOT>` on the machine. Start from `assets/templates/project.windows.yml`.

### Step 4 — compile

```bat
cd /d <project-root>
tpc.exe project.windows.yml --job 2 --no-progress
```

Expect `Build successful: <name>.exe` in the working directory.

`tpc` optimizes at `-O0` unless the entry yml pins `optimize: 2` — the package's own templates and `examples/hello` do pin it, together with `cxx-std: c++17` (which `--nano` requires).

### Step 5 — deploy Qt

```bat
windeployqt --release --no-translations <name>.exe
```

This copies `Qt6Core/Gui/Widgets.dll` plus `platforms\qwindows.dll` and the other plugin folders next to the exe. **Skip it and the app dies with "could not find or load the Qt platform plugin windows".**

### Step 6 — run with the PHP runtime ini

If your app uses any PHP extension (PDO_SQLITE is the common one), create `runtime.ini`:

```ini
extension_dir=D:\git\php\tpc_v0.9.4_windows_x64\ext
extension=php_pdo_sqlite.dll
```

Then:

```bat
set "PHPRC=<project-root>\runtime.ini"
set "PHP_INI_SCAN_DIR="
<name>.exe
```

`PHP_INI_SCAN_DIR=` matters: without it, a host `php.ini` scan directory can load conflicting extensions.

### Optional — GUI subsystem

Add `--no-console` to the `tpc` invocation for a build that does not attach a console window. Validate with console output first, then add the flag.

---

## macOS (arm64)

Tested on Apple Silicon with Homebrew `qtbase`. **First build compiles a private PHP embed runtime** and caches it under `~/.typephp` (the cache dir name carries a platform fingerprint, so macOS and Linux never share one); later builds reuse it. Note that `tpc` contacts php.net's releases index to verify the source SHA-256 — a fully offline machine fails on the very first build.

```bash
brew install qtbase
export PHP_HOME="$HOME/.phpbrew/php/php-8.5-zts"
export PHPX_HOME="$HOME/workspace/phpx"

# confirm PDO + pdo_sqlite are compiled in
"$PHP_HOME/bin/php" -n -m | grep -E '^(PDO|pdo_sqlite)$'

php bin/tpc.php examples/qt-taskboard/project.macos.yml --job 2 --no-progress
./typephp_taskboard
```

- `project.macos.yml` uses the Apple Silicon Homebrew framework path `/opt/homebrew/lib`; adjust for another Qt install.
- macOS launches desktop apps from `.app` bundles. The example ships `package-macos-app.sh`, which collects the binary, Qt frameworks and plugins, and the PHP/PHPX libraries, then applies an **ad-hoc signature** so it launches locally:

```bash
sh examples/qt-taskboard/package-macos-app.sh
open "examples/qt-taskboard/dist/TypePHP Taskboard.app"
```

- `--no-console` is a Windows-only concept; it is not needed here.
- Distributing to other Macs needs a real signing identity and notarization — the ad-hoc signature is for local testing only.
- **PHP is linked fully statically on macOS**, so unlike Windows there are no PHP/PHPX runtime libraries to copy into the bundle.
- **`macdeployqt` only copies the platform plugin for the target platform.** A bundle with just `libqcocoa.dylib` aborts (rc=134) if you set `QT_QPA_PLATFORM=offscreen`. To make the packaged artifact headlessly verifiable, copy `libqoffscreen.dylib` into `Contents/PlugIns/platforms/` and rewrite its Qt references to `@executable_path` (about +156 KB). `qtphp package` does this automatically.
- **Rewrite references in *every* Mach-O, not just the executable.** `macdeployqt` leaves absolute build-machine paths *inside* the frameworks: measured, `QtCore` referenced `/opt/homebrew/opt/icu4c@78/lib/libicu{i18n,uc,data}.78.dylib`, so the bundle's own ICU copies were never loaded and the app died on any Mac without that brew install (`dyld: Library not loaded`, rc=134) — while passing every check run on the build machine. `qtphp package` now walks all bundled Mach-O, rewrites those references to `@executable_path`, and fails `rc=1` if any absolute non-system path survives. A framework's own install name (`otool -D`) is not a load reference; those may stay absolute.

---

## Linux

```bash
php bin/tpc.php examples/qt-taskboard/project.yml --job 2 --no-progress
./typephp_taskboard
```

`project.yml` carries the Linux include paths (`/usr/include/x86_64-linux-gnu/qt6/…`) and links `-lQt6Widgets -lQt6Gui -lQt6Core`. Use `-fPIC` in `cxx-flags`. Install `qt6-base-dev` (Debian/Ubuntu) or the distro equivalent.

Building the private embed runtime needs more than Qt — on Debian 12:

```bash
apt install -y qt6-base-dev cmake g++ pkg-config bison re2c autoconf xz-utils patchelf \
  zlib1g-dev libxml2-dev libsqlite3-dev libonig-dev libgmp-dev libmpfr-dev
```

There is no `windeployqt` on Linux. A working bundle is the **`ldd` transitive closure** of the executable plus the Qt plugins, located via `qt.conf` and a `DT_RPATH` of `$ORIGIN/lib`:

- Move each `.so` under `lib/`, named by its **soname**, not its symlink name.
- Keep the glibc family on the system; ship `libstdc++` (the GLIBCXX ABI pins it).
- Use `patchelf --force-rpath` so the loader finds `$ORIGIN/lib` before `ld.so.cache` — a `RUNPATH` is searched *after* the cache and will silently pick up the build machine's copies.
- `qt.conf` must point at the relocated plugin directory.
- Verify with `readelf -d` (expect `(RPATH)` and no `(RUNPATH)`) and run `ldd` **on the executable and on each plugin** — the xcb plugin's dependencies are not in the executable's closure, so only a per-plugin check sees them.

`qtphp package` implements exactly this and fails the build if any `ldd` line lands outside the bundle.

---

## Packaging for distribution

`windeployqt` covers Qt and **only** Qt. Two more classes of file have to be
copied by hand, and forgetting either is the classic "works on my machine" bug:

| What | Why `windeployqt` misses it |
|---|---|
| TypePHP / PHPX runtime DLLs | not Qt libraries |
| Your own files — icons, templates, data | not Qt libraries either |

The scaffold generates `package.bat`, which assembles a `dist\` folder:

```
dist\<app>.exe
dist\                 Qt DLLs + plugins            <- windeployqt
dist\                 TypePHP / PHPX runtime DLLs  <- copied explicitly
dist\assets\          your own runtime files       <- copied explicitly
dist\runtime.ini      PHP extension config, if used
```

Run it after `build.bat`:

```bat
package.bat
```

### Which runtime DLLs

The list is not guesswork — it is the import-table closure of the executable.
For a plain Widgets app it is:

```
phpx.dll  php8ts.dll  libmpdec++-4.0.1.dll  libmpdec-4.0.1.dll  gmp-10.dll  mpfr-6.dll
```

Confirm it for any build with:

```bat
dumpbin /nologo /dependents <app>.exe | findstr /I /R /C:"\.dll"
```

then repeat on each non-system DLL it names, until you reach only
`api-ms-win-*` / `KERNEL32` / `USER32` / … . **Add to the list if you load PHP
extensions**: `pdo_sqlite` needs `libsqlite3.dll`, for example.

### The `assets/` convention

Put anything the app reads at runtime in the project's `assets/` folder. The
structure is preserved (`assets/icon.png` → `<app-dir>/assets/icon.png`), and
because relative paths resolve against the *executable's* folder, the same
`'assets/icon.png'` string works from the project directory and from `dist\`.

This exists because "copy the icon too" is exactly the step that gets
forgotten. A folder plus a packaging script that copies it beats a note in a
README.

### The package verifies itself

`package.bat` does not just copy files — it proves the result works. Its last
step runs the packaged executable with `PATH` reduced to
`C:\Windows\System32`, so nothing can leak in from the build machine, and
checks three things:

1. **Exit code** — non-zero means a missing DLL, and the error names it.
2. **No PHP startup problem** — the embedded runtime reports a missing
   extension as a *warning* and carries on, so a clean exit code is not enough.
   The check scans both stdout and stderr for `PHP Startup` / `Fatal error` and
   fails the build. Scanning stdout is not optional: PHP writes those warnings
   to stdout, not to stderr.
3. **A rendered frame** — the app must actually produce its screenshot.

Any failure exits non-zero, so CI catches it. Pass `--no-verify` to skip the
check on a headless box — the script then says plainly that the package was
**not** verified instead of claiming success.

To do it by hand:

```bat
set "PATH=C:\Windows\System32;C:\Windows"
dist\<app>.exe
```

Do this before shipping — it is the only check that actually catches a missing
dependency.

### Other platforms

- **macOS**: `package-macos-app.sh` in the TypePHP repo example collects the
  binary, Qt frameworks and plugins, and the PHP/PHPX libraries into a `.app`.
  Apply the same rule: your `assets/` must be copied into
  `Contents/Resources/` explicitly. And apply the reference rule too: run
  `otool -L` on **every** Mach-O in the bundle, not just the executable — an
  absolute path left inside a framework ships a bundle that only launches on
  the machine that built it.
- **Linux**: there is no `windeployqt` equivalent; assemble the bundle yourself
  (or use `linuxdeploy`), and copy `assets/` alongside.

---

## Verifying without a human

A GUI app needs a way to prove it still works on a machine with no display and no hands. Build **two** switches, not one.

### `--shot <path>` — visual

Render a few frames, save a PNG, exit:

```php
$shot = shot_path($argv);          // reads `--shot <path>` from argv
if ($shot !== '') {
    $app->runFrames(3);
    $app->snapshot($shot);         // clears focus + pushes running animations to their end value
    $app->destroy();
    return;
}
```

The frame count is no longer load-bearing: `snapshot()` freezes transient state itself (clears focus, clears `WA_UnderMouse`, and jumps any running animation to its end value). Before that, grabbing after a few frames caught the `QLineEdit` clear-button fade mid-flight — 8 runs produced 4 distinct hashes — and the only workaround was pumping ~120 frames (~3 s per shot).

Read the PNG back and look at it — this is the fastest way to confirm a layout change. (Prefer an argument over an env var: passing an env var into a `.bat` from a shell is fragile, see the gotchas below.)

### `--selftest` — behavioural

Dispatch every registered event once and report per-case `ok` / `FAIL`:

```php
$app->headless(true);      // modal dialogs must not block — see aot-pitfalls.md #5
$app->run(1);
$app->dispatch(['type' => 'click', 'id' => 'greet_btn']);
// … one dispatch per control …
echo $app->lastError() === '' ? "selftest passed\n" : "selftest failed\n";
```

**This is the switch that earns its keep.** The handler-arity trap (`aot-pitfalls.md` #1) is invisible to unit tests and to a smoke run that clicks nothing — it only fires when a control is actually exercised. `--selftest` exercises them all, headlessly, in seconds.

Have the app expose the last error programmatically (`lastError()`) so the check can print *why* a case failed instead of just that it did — and **exit non-zero when a case fails**. The package's examples and `qtphp new` scaffolds `exit(1)` on failure; a self-check that always returns 0 is decoration, not a gate, because CI reads the exit code.

### Running the packaged artifact headlessly

Set `QT_QPA_PLATFORM=offscreen`. On macOS the bundle then needs the offscreen plugin shipped inside it (see above). Linux bundles already carry the whole `platforms/` directory.

```bash
QT_QPA_PLATFORM=offscreen ./build/hello --selftest          # dev artifact
env -i QT_QPA_PLATFORM=offscreen PATH=/usr/bin:/bin HOME="$HOME" \
    dist/Hello.app/Contents/MacOS/hello --selftest          # packaged bundle
```

`env -i` strips the environment — the same idea as reducing `PATH` to `C:\Windows\System32` on Windows. **It does not isolate the filesystem, so it is not a self-containment proof.** A bundle whose frameworks still point at build-machine paths (measured: `QtCore` → Homebrew's ICU) passes `env -i` cleanly and dies at `dyld: Library not loaded` on the next Mac. Trustworthy criteria, in order of convenience:

1. **the package's own scan** — `qtphp package` walks every Mach-O in the bundle and fails `rc=1` on any absolute non-system path;
2. **hide the dependency** — rename the brew opt symlink (or the DLL's directory) just for the run, then restore it and re-verify the restore;
3. **a clean machine** — the real thing, when you have one.

---

## Gotchas that waste the most time

1. **`project.windows.yml` paths are placeholders.** The single most common first-run failure. Replace `<QT_ROOT>` before anything else.
2. **Missing `/Zc:__cplusplus` and `/permissive-`.** The Qt MSVC headers will not compile without them.
3. **Forgetting `windeployqt`.** Compiles fine, dies at launch with a platform-plugin error.
4. **Missing `runtime.ini` / `PHPRC`.** The app launches and immediately exits while the embedded PHP runtime looks for an extension that is not loaded.
5. **git-bash mangles `cmd /c`.** MSYS turns `/c` into a path (`C:\`). Use `cmd //c "…"` (double slash) when driving `cmd` from bash, or just run the `.bat` from a real `cmd` prompt.
6. **`set VAR=value && next` in `cmd` captures the space before `&&`.** The value ends up with a trailing space — which is how you get a screenshot saved as `shot.png ` (with a trailing space in the name). Prefer `set "VAR=value"` with quotes, or set the variable inside a `.bat` file.
7. **Passing an env var into a `.bat` from a shell is fragile.** `cmd //c "set \"X=y\" && call run.bat"` loses the variable somewhere in the quoting layers. Make the `.bat` accept the value as an argument instead — the generated `run.bat` takes an optional PNG path (`run.bat shot.png`) and sets `<APP>_SCREENSHOT` from it. This is the reliable pattern for driving a GUI build from a script.
8. **Rebuilding after a Qt path change.** Incremental builds reuse object caches. If you change Qt roots and see stale link errors, add `--force`.
9. **ABI mismatch.** PHP headers, `php-config`/`PHP_HOME`, `libphp`, PHPX and the extension ABI must all come from the same PHP version and the same ZTS/NTS mode. Mixing produces startup crashes.
10. **A class you use needs its own header included.** Qt does not guarantee transitive includes — `verticalHeader()` needs `<QHeaderView>`, `QAbstractItemView::SelectRows` needs `<QAbstractItemView>`. A missing include shows up as `error C2027: use of undefined type`.
11. **Generated `.bat` files must have CRLF line endings.** cmd tolerates LF for plain commands, so it looks fine — until a `goto` or a label fails with "The system cannot find the batch label specified". If you generate batch files from a shell script, convert them afterwards.
12. **`^&` is an escape *inside* a block, and the opposite of what you want outside one.** Inside a parenthesised block, `endlocal ^& exit /b 1` hands `endlocal` the literal arguments `& exit /b 1`, so it does not exit and execution falls through with the localised variables already gone. At the top level the same caret makes `&` literal for exactly the same reason. So: inside a block use a bare `exit /b 1`; at the top level use a bare `&` — `endlocal & exit /b %RC%`.
13. **A trailing `endlocal` swallows the exit code.** A script that ends with `endlocal` returns 0 no matter what happened — so `build.bat && package.bat` cheerfully packages a stale binary, and a crashed app looks like a success in CI. Capture the code and return it: `set "RC=%errorlevel%"` … `endlocal & exit /b %RC%`. The scaffolded `build.bat`, `run.bat` and `package.bat` all do this, and `build.bat` also fails fast when `vcvars64.bat` is missing rather than producing a wall of unrelated errors.

### These rules are enforced, not just written down

`scripts/lint-bats.sh` encodes the batch pitfalls above as static checks, and
`scaffold.sh` runs it on every project it generates — a template that
reintroduces one of them fails the scaffold instead of shipping.

Run it by hand on any batch file:

```bash
bash scripts/lint-bats.sh <dir>
```

It checks: CRLF line endings, every `goto`/`call :label` resolving, the `^&`
escape, a trailing `endlocal` with no other failure path, unquoted
`set X=value &&`, and `setlocal` without `endlocal`.

**Why static and not "just run it through cmd":** cmd has no dry-run or
syntax-check mode, and it parses batch files lazily — a syntax error later in a
file is only reported once execution reaches it. Executing the scripts to find
out is not an option either, since they build, package and `rmdir`. So these
rules stand in for a parser, and each one exists because it bit us for real.

When writing the checks, prefer counting bytes (`od`) over regex escape
sequences: a `$'\r'` pattern silently degraded into "match every line" and
reported a clean LF-only file as all-CRLF. A linter that lies is worse than no
linter.
