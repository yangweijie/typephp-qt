# Packaging

`qtphp package .` assembles a self-contained artifact **and verifies it** — a failed check exits `1` rather than claiming success.

```bash
qtphp package .
```

## Why it is needed

`windeployqt` (and `macdeployqt` on macOS) covers **Qt only**. Two more classes of file must be copied along, and missing either is the classic "works on my machine":

| What | Why the deploy tool misses it |
|---|---|
| The TypePHP / PHPX runtime | not a Qt library |
| Your own files (icons, templates, data) | not a Qt library either |

## Windows

The artifact is a `dist/` directory:

```
dist/
├── <app>.exe
├── Qt6Core.dll  Qt6Gui.dll  Qt6Widgets.dll     ← windeployqt
├── platforms/qwindows.dll                       ← windeployqt
├── phpx.dll  php8ts.dll  libmpdec*.dll          ← copied explicitly
│   gmp-10.dll  mpfr-6.dll
├── assets/                                      ← copied explicitly
└── runtime.ini                                  ← if PHP extensions are used
```

### Where the runtime DLL list comes from

It is not guesswork — it is the executable's **import-table closure**. For a plain Widgets app it is:

```
phpx.dll  php8ts.dll  libmpdec++-4.0.1.dll  libmpdec-4.0.1.dll  gmp-10.dll  mpfr-6.dll
```

Verify it yourself:

```bat
dumpbin /nologo /dependents <app>.exe | findstr /I /R /C:"\.dll"
```

Then repeat on each non-system DLL until only `api-ms-win-*` / `KERNEL32` / `USER32` and friends remain. **Add to the list if you load PHP extensions** — `pdo_sqlite` needs `libsqlite3.dll`, for example.

### The `assets/` convention

Put anything the app reads at runtime in the project's `assets/`. The structure is preserved (`assets/icon.png` → `<app-dir>/assets/icon.png`), and because a relative path resolves against the **executable's** directory, the same `'assets/icon.png'` string works from the project directory and from `dist/`.

Inside a macOS `.app` the executable lives in `Contents/MacOS` while assets land in `Contents/Resources/assets`, so the resolver checks one extra level. Full order: **executable directory → bundle's `Contents/Resources` → working directory**. No need to duplicate assets under `MacOS/`.

The convention exists because "copy the icon too" is exactly the step that gets forgotten. A directory plus a script that copies it beats a note in a README.

## macOS

The artifact is `dist/<Name>.app`:

```
dist/MyApp.app/
└── Contents/
    ├── Info.plist
    ├── MacOS/myapp
    ├── Frameworks/          ← Qt frameworks
    ├── PlugIns/
    │   └── platforms/
    │       ├── libqcocoa.dylib
    │       └── libqoffscreen.dylib    ← copied in by qtphp
    └── Resources/           ← your assets/
```

The flow: `macdeployqt -always-overwrite -no-codesign` → copy in the offscreen plugin → ad-hoc `codesign --force --deep --sign -` → `plutil -lint` + `codesign --verify`.

::: tip PHP is linked fully statically on macOS
So unlike Windows, there are **no PHP/PHPX runtime libraries to copy**.
:::

::: warning Why the offscreen plugin is copied in
`macdeployqt` only copies the platform plugin for the target platform — a bundle carrying only `libqcocoa.dylib` is aborted outright by Qt (rc=134) when `QT_QPA_PLATFORM=offscreen` is set.

`qtphp package` copies `libqoffscreen.dylib` into `Contents/PlugIns/platforms/` and rewrites its Qt references to `@executable_path` (about +156 KB). That is what makes the packaged artifact headlessly verifiable.
:::

The ad-hoc signature is **for local testing only** — distributing to other Macs needs a real signing identity and notarization.

## Linux

The artifact is `dist/<name>/`:

```
dist/myapp/
├── myapp
├── lib/              ← the ldd transitive closure (on the order of 85 .so files)
├── plugins/
│   ├── platforms/    ← including libqxcb.so, libqoffscreen.so
│   └── xcbglintegrations/
└── qt.conf           ← points at the plugin directory
```

There is no `windeployqt` equivalent on Linux, so the bundle is assembled by hand. The key points:

| Practice | Why |
|---|---|
| Move the **`ldd` transitive closure** | direct dependencies alone are not enough |
| Name each `.so` by its **soname** | not its symlink name |
| Leave the glibc family **on the system** | copying it breaks things |
| **Do copy `libstdc++`** | the GLIBCXX version pins the ABI |
| `patchelf --force-rpath` | so the loader finds `$ORIGIN/lib` first |
| Run `ldd` on **the executable and each plugin** | the xcb plugin's dependencies are not in the executable's closure |

::: danger Why `--force-rpath` rather than `RUNPATH`
`RUNPATH` is searched **after** `ld.so.cache` — it would silently prefer the build machine's copies. Only `RPATH` comes before.

`qtphp`'s self-check reads `readelf -d` and asserts "there is an `(RPATH)` and no `(RUNPATH)`".
:::

## The self-check

The packaging script does not just copy files — it **proves the result runs**. The last step reduces `PATH` to the minimum (on Windows `C:\Windows\System32`; on macOS/Linux `env -i`) so nothing can leak in from the build machine, then checks three things:

1. **Exit code** — non-zero means a missing DLL/so, and the error names which.
2. **No PHP startup problem** — the embedded runtime reports a missing extension as a **warning** and carries on, so a clean exit code is not enough. The check scans stdout *and* stderr for `PHP Startup` / `Fatal error`.
3. **A rendered frame** — the app must actually produce its screenshot.

::: tip Why step 2 scans stdout
PHP writes those startup warnings to **stdout**, not stderr. Scanning stderr alone misses them.
:::

Any failure exits `1`, so CI catches it.

## Verifying by hand

```bash
# Windows
set "PATH=C:\Windows\System32;C:\Windows"
dist\<app>.exe

# macOS
env -i QT_QPA_PLATFORM=offscreen PATH=/usr/bin:/bin HOME="$HOME" \
    dist/MyApp.app/Contents/MacOS/myapp --selftest

# Linux
cd dist/myapp && env -i QT_QPA_PLATFORM=offscreen ./myapp --selftest
```

**Always do this before shipping** — it is the only check that actually catches a missing dependency.

## Using PHP extensions

If the app uses a PHP extension (say `pdo_sqlite`), you need `runtime.ini`:

```ini
extension_dir=D:\git\php\tpc_v0.9.4_windows_x64\ext
extension=php_pdo_sqlite.dll
```

Before running:

```bat
set "PHPRC=<project-dir>\runtime.ini"
set "PHP_INI_SCAN_DIR="
<app>.exe
```

`PHP_INI_SCAN_DIR=` matters: without it, the host's `php.ini` scan directory may load conflicting extensions.

And do not forget to add the extension's own DLL dependencies to the deployment list (`pdo_sqlite` → `libsqlite3.dll`).
