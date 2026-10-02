# Installing and Building Qt

This page covers Qt itself: **which modules you need, how to install them on each OS, and how the
build links them.** If you only want to get a window on screen, [Installation](/guide/installation.md)
has the short version — come here when Qt is missing, the version differs, or linking fails.

## What you actually need

The bridge uses **three Qt modules and nothing else**:

| Module | Provides |
|---|---|
| `QtCore` | `QString`, containers, event loop, timers, `QFileInfo`, `QDir` |
| `QtGui` | `QIcon`, `QPixmap`, `QFont`, `QClipboard`, `QScreen`, `QAction` |
| `QtWidgets` | every control: `QMainWindow`, `QPushButton`, `QTableWidget`, … |

No QML/Quick, no Network, no Sql — verified against every `#include` in `cpp-src/`.
So a **minimal Qt Widgets install is enough**, which is what the commands below give you.

::: tip Version
Qt **6.0 or newer**. The project is developed and tested against **6.9.3**; `qtphp`'s automatic
detection looks for 6.9.3 specifically, so any other version needs `QT_DIR` (see below).
Qt 5 will not work — the code uses Qt 6 APIs throughout.
:::

## Windows

Windows needs **two** things installed, and they must match each other:

1. **A C++ compiler** — MSVC 2022 (or BuildTools). MinGW will *not* link against an MSVC-built Qt.
2. **Qt 6 built for that same compiler** — the `msvc2022_64` kit.

### Option A — the official online installer (recommended)

Download the Qt Online Installer from <https://www.qt.io/download-qt-installer>, sign in with a
free Qt account, then under **Qt → Qt 6.x.x** tick exactly:

- **MSVC 2022 64-bit** ← the kit you need
- (optional) *Qt 5 Compatibility Module* — not used by this project

Skip *Sources*, *Qt Debug Information Files*, and any other kit (MinGW, Android, WebAssembly,
ARM) — they are gigabytes you will never link.

Install to a path **without spaces** (the default `C:\Qt` is fine). `qtphp` probes
`C:/Qt/6.9.3/msvc2022_64`, `D:/Qt/6.9.3/msvc2022_64`, and `D:/tools/Qt/6.9.3/msvc2022_64`.

### Option B — aqtinstall (scriptable, no account)

```bash
pip install aqtinstall
aqt install-qt windows desktop 6.9.3 win64_msvc2022_64 -O C:/Qt
```

Handy for CI or a locked-down machine. Same layout as the official installer, so detection works.

### Verify

```bat
C:\Qt\6.9.3\msvc2022_64\bin\qmake.exe -query QT_VERSION
```

You should also see `windeployqt.exe` in that `bin\` directory — `qtphp package` needs it.

::: warning MSVC environment
`cl.exe` on `PATH` is not enough; `INCLUDE` and `LIB` must be set too. `qtphp` handles this by
calling `vcvars64.bat` itself — you do not need a "Developer Command Prompt".
:::

## macOS

The official Qt installer works, but **Homebrew's `qtbase` is smaller and simpler** — it is exactly
the three modules above:

```bash
brew install qtbase libiconv
```

- `qtbase` is **keg-only**, so it is *not* symlinked into `/usr/local` — that is why `qtphp`
  probes `/opt/homebrew/opt/qtbase` (Apple Silicon) and `/usr/local/opt/qtbase` (Intel).
- `libiconv` is also keg-only; the generated `project.macos.yml` passes its `-L` and `-rpath`
  explicitly.
- If you want the full Qt (QML, Charts, …): `brew install qt` — heavier, and `qtphp` probes
  `/opt/homebrew/opt/qt` as a fallback.

### Why macOS links differently

Homebrew's `qtbase` uses the **framework** layout: module headers live in
`lib/Qt<Module>.framework/Headers/`, not in `include/Qt<Module>/`. And Qt's forwarding headers
use qualified includes internally (`<QtWidgets/qabstractitemview.h>`), so the build needs
**both** `-I` (to resolve bare names) and `-F` (to resolve qualified ones). The scaffold's
`project.macos.yml` passes both; drop either and the compile fails.

```yaml
cxx-flags:
  - -F/opt/homebrew/opt/qtbase/lib
  - -I/opt/homebrew/opt/qtbase/lib/QtCore.framework/Headers
  # … Gui, Widgets
ld-flags:
  - -F/opt/homebrew/opt/qtbase/lib
  - -Wl,-framework,QtWidgets
  - -Wl,-framework,QtGui
  - -Wl,-framework,QtCore
```

## Linux

The distro package is the right answer. On **Debian / Ubuntu**:

```bash
apt install -y qt6-base-dev
```

`qt6-base-dev` is exactly Core + Gui + Widgets. That single package is enough for the Qt side —
the other packages in [Installation](/guide/installation.md#linux-debian-ubuntu) are for building
the private PHP embed runtime, not for Qt.

Other distributions:

| Distro | Command | Qt headers land in |
|---|---|---|
| Debian / Ubuntu | `apt install qt6-base-dev` | `/usr/include/<triple>/qt6/` |
| Fedora / RHEL | `dnf install qt6-qtbase-devel` | `/usr/include/qt6/` |
| Arch | `pacman -S qt6-base` | `/usr/include/qt6/` |
| openSUSE | `zypper install qt6-base-devel` | `/usr/include/qt6/` |

::: warning Debian's multiarch layout
Debian puts headers under `/usr/include/x86_64-linux-gnu/qt6/` (or `aarch64-linux-gnu`), not
`/usr/include/qt6/`. `qtphp` detects this with `dpkg-architecture -qDEB_HOST_MULTIARCH` and
generates the right paths.

**On Arch / Fedora / openSUSE**, edit `project.linux.yml` and replace the Debian-style paths with
the flat `/usr/include/qt6` and `/usr/lib` shown in the table above — that is the one manual step
on those distros.
:::

### Verify

```bash
pkg-config --modversion Qt6Widgets    # needs qt6-base-dev
```

## Pointing the build at a non-default Qt

`qtphp`'s detection covers the common locations for 6.9.3. **Anything else — a different version,
a custom prefix, a second Qt — needs `QT_DIR`, which takes precedence over every probed path:**

```bash
QT_DIR=/path/to/Qt/6.8.2/gcc_64    qtphp build .
QT_DIR=C:/Qt/6.10.0/msvc2022_64    qtphp build .
```

`qtphp new` bakes the detected path into `project.yml` / `project.macos.yml` / `project.linux.yml`
as plain strings, so you can also edit those directly — that is often simpler for a project you
will build repeatedly:

```yaml
include-paths:
  - /opt/Qt/6.10.0/gcc_64/include
  - /opt/Qt/6.10.0/gcc_64/include/QtCore
  - /opt/Qt/6.10.0/gcc_64/include/QtGui
  - /opt/Qt/6.10.0/gcc_64/include/QtWidgets
link-paths:
  - /opt/Qt/6.10.0/gcc_64/lib
```

Then confirm:

```bash
qtphp doctor     # prints the Qt path it will use
```

`QT_DIR` wins over automatic detection, so it works even on a machine that already has a Qt in one
of the probed locations. If it points at a directory that does not exist, `qtphp` warns and falls
back to probing.

## Troubleshooting

| Symptom | Cause and fix |
|---|---|
| `Qt: 未找到` in `doctor` | Qt is not in a probed location → set `QT_DIR` |
| `fatal error: 'QApplication': No such file or directory` | The `include/QtWidgets` (or framework `-I`) path is missing from `include-paths` |
| `cannot open file 'Qt6Widgets.lib'` (MSVC) | `link-paths` lacks `<QT>/lib`, **or** you installed the MinGW kit instead of `msvc2022_64` |
| `undefined reference to 'QApplication::…'` (GCC/Clang) | `link-libs` lacks `-lQt6Widgets` (or `-Wl,-framework,QtWidgets` on macOS) |
| `'QtWidgets/qabstractitemview.h' file not found` (macOS) | Missing `-F` at **compile** time — see the macOS section |
| LNK2038 / MSVC runtime mismatch | Qt was built with a different MSVC toolset than your compiler → reinstall matching kits |
| `could not find or load the Qt platform plugin windows` at run time | Plugins were not deployed → `qtphp build` does it for you; see [Packaging](/reference/packaging.md) |

## Related

- [Installation](/guide/installation.md) — the whole toolchain, short version
- [Platforms](/reference/platforms.md) — per-platform capability matrix
- [Packaging](/reference/packaging.md) — what must ship alongside the binary
