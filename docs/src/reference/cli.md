# CLI

`qtphp` is the executable the package provides; it lands in `vendor/bin/qtphp`.

```
Usage:
  qtphp doctor            check the toolchain
  qtphp new <name>        create a new project
  qtphp build <path>      compile a project
  qtphp run <path> [args…]  run it; args pass straight through to the app
  qtphp package <path>    package a project
  qtphp test              run the tests
  qtphp lint              verify the bridge contract
```

## `qtphp doctor`

Checks the toolchain and prints the resolved paths:

```
=== TypePHP\Qt toolchain check ===

[OK] PHP 8.5.11
[OK] tpc: D:\git\php\tpc_v0.9.4_windows_x64\tpc.exe
[OK] PHP runtime library: D:\git\php\tpc_v0.9.4_windows_x64
[OK] Qt: D:/tools/Qt/6.9.3/msvc2022_64
[OK] MSVC: ...\vcvars64.bat
[OK] PHPUnit: ...\vendor/bin/phpunit

[OK] Core toolchain ready!
```

Checks are dispatched per platform:

| Item | Windows | macOS | Linux |
|---|---|---|---|
| C++ compiler | MSVC (finds `vcvars64.bat`) | `clang++` | `g++` |
| Qt location | common `C:/D:` paths | brew keg-only | Debian multiarch `/usr` |
| PHP runtime library | `phpx.dll` etc. beside tpc | `~/.typephp/php-builder/*/install/lib` | as macOS |
| Extra prerequisites | — | — | 12 items (below) |

On Linux it additionally checks 12 build/packaging prerequisites and prints a paste-ready command for whatever is missing:

```
[WARN] Missing build prerequisites: bison re2c patchelf
       Try: apt install -y bison re2c patchelf
```

**WARN only — it does not affect the exit code.** doctor's rc is decided by error-level items alone.

Exit code: `0` all ready, `1` an error-level problem.

## `qtphp new <name>`

Creates a new project:

```
<name>/
├── src/main.php            # entry scaffold (compiles and runs as-is)
├── project.yml             # Windows build entry
├── project.macos.yml       # macOS entry
├── project.linux.yml       # Linux entry
├── Info.macos.plist        # for packaging the .app
├── build.bat / run.bat / package.bat   # Windows convenience scripts
├── assets/                 # runtime resources
└── README.md
```

**The generated scaffold compiles and runs as-is** — you only replace the UI and the data layer.

`<name>` must be a valid identifier (starts with a letter, letters/digits/underscores only).

## `qtphp build <path>`

Compiles. The entry yml is picked per platform:

| Platform | Entry |
|---|---|
| Windows | `project.yml` |
| macOS | `project.macos.yml` (falls back to `project.yml`) |
| Linux | `project.linux.yml` (falls back to `project.yml`) |

On Windows a successful compile **auto-deploys the runtime DLLs** into `build/`:

```
[OK] Deployed 11 runtime files to build/
```

Artifact: `build/<name>.exe` (Windows) or `build/<name>` (macOS/Linux).

::: tip The first build is slow
On macOS/Linux the first `build` makes tpc compile a private embed runtime from php-src, cached under `~/.typephp`; later builds reuse it. Note that tpc contacts php.net on every build to verify the source SHA-256 — **a fully offline machine fails the first time**.
[`--nano`](#nano) skips that step entirely.
:::

## nano

`qtphp build <path> --nano` compiles in **nano mode**: php-nano and PHPX are compiled straight
into the artifact, so it links **no PHP runtime at all**.

```bash
qtphp build examples/hello --nano
```

Measured on Apple Silicon macOS with `examples/hello` (artifacts are built at `-O2`, pinned in
the entry yml as `optimize: 2`; the nano figures use the **clean first-build baseline**, i.e.
after clearing `build/cache`):

| Mode | Artifact | After `strip -u -r` |
|---|---|---|
| default (embed) | 25,569,608 B | — |
| `--nano` | 4,370,328 B | 3,639,472 B |

`otool -L` on the nano artifact lists only the three Qt frameworks plus
`Foundation`/`AppKit`/`WebKit`, brew `libiconv` and `libc++`/`libSystem` — no libphp, no phpx.
Behaviour is unchanged: `--selftest` (25 cases) and `--difftest` (20 cases) pass, and `--shot`
renders byte-identical PNGs to the embed baseline. Packaged on macOS, `dist/Hello.app` is
**64.1 MiB** (the embed build is 83.6 MiB).

::: tip Switching from embed to --nano in the same build/ carries ~33 KB
The incremental cache keeps the literal-string-table flavour from the embed round, inflating
the artifact to 4,403,640 B. Delete the `objects`/`incremental`/`link` directories under
`build/cache/` and rebuild to get back to the clean baseline; the difference is size only —
selftest/snapshot output stays byte-identical.
:::

Worth knowing:

- **No extra yml.** The same entry yml works. tpc ignores a `php-builder:` section under nano
  (nano links no runtime, so there is nothing to prepare); the C++ standard is a non-issue too —
  the entry yml pins `cxx-std: c++17` (nano rejects `c++20`), and `qtphp build --nano` additionally
  overrides it to `c++17` on the CLI as a backstop.
- **`build/` is shared** between the two modes: switching re-takes the affected translation units
  and relinks, it is not a no-op.
- On Windows the runtime DLL deployment step is **skipped** under nano — the artifact imports no
  `php*.dll`, and tpc's own dependency audit fails the build if one shows up.
- nano ships a **subset** of the PHP runtime: tpc rejects functions nano does not support at
  compile time rather than letting them fail at runtime.
- **Only Apple Silicon macOS is verified on real hardware.** On Windows/Linux the flag is handed
  straight to tpc; treat those as untested.

## `qtphp run <path> [app args…]`

Runs the artifact. **The third argument onward passes straight through**:

```bash
qtphp run . --selftest
qtphp run . --shot out.png
qtphp run . --difftest
```

It runs a dependency self-check before launching:

| Platform | How |
|---|---|
| Windows | checks the DLLs are present |
| macOS | `otool -L` for absolute paths outside the bundle |
| Linux | `ldd` for `not found` |

The exit code passes through bit for bit (the app returns 7, so does `qtphp run`).
The three acceptance switches use it too: `0` all good, `1` on a `FAIL` or a failed `--shot`
(see [Headless Verification → Exit codes](/advanced/headless.md#exit-codes)).

## `qtphp package <path>`

Packages a self-contained artifact and **verifies it**:

| Platform | Artifact |
|---|---|
| Windows | a `dist/` directory (windeployqt + PHP/PHPX runtime + platform plugins) |
| macOS | `dist/<Name>.app` (macdeployqt + ad-hoc signature + offscreen plugin copied in) |
| Linux | `dist/<name>/` (the ldd closure moved into `lib/` + `patchelf` DT_RPATH + `qt.conf`) |

A failed self-check exits `1` and says why — it never claims success falsely. See [Packaging](/reference/packaging.md).

## `qtphp test`

Runs PHPUnit. **Needs neither Qt nor a compiler** — everything goes through the pure-PHP `FakeBridge` double.

```
OK (112 tests, 183 assertions)
```

## `qtphp lint`

Verifies the bridge contract (stub ⇄ C++ symbols agree):

```
[OK] 契约一致！
```

On a mismatch it lists the specific symbols. Run it after any bridge change.

## Environment variables

| Variable | Effect |
|---|---|
| `TPC` | Point straight at a tpc executable (skips the runtime probe) |
| `TPC_DIR` | Point at a tpc install directory |
| `TPC_RUNTIME_DIR` | Point at the PHP/PHPX runtime library directory |
| `QT_DIR` | Point at the Qt install directory |
| `PHPX_HOME` | Point at the phpx source tree (affects tpc's runtime resolution) |
| `QT_QPA_PLATFORM` | Qt platform plugin; set `offscreen` in a headless environment |
