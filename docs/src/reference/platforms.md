# Platform Support

## Capability matrix

| Platform | Build / run / headless verify | Packaging | Dependencies |
|---|---|---|---|
| Windows | ✅ | ✅ a `dist/` directory (windeployqt) | Qt 6 + MSVC |
| macOS (Apple Silicon) | ✅ | ✅ `dist/<Name>.app` (macdeployqt + ad-hoc signature) | `brew install qtbase libiconv` |
| Linux (Debian/Ubuntu) | ✅ | ✅ `dist/<name>/` (ldd closure + patchelf + `qt.conf`) | see below |

All three platforms pass `--selftest` 14/14 and `--difftest` 20/20.

## Tested environments

| Platform | Environment |
|---|---|
| Windows | Windows 10.0.26100 · MSVC 2022 BuildTools · Qt 6.9.3 msvc2022_64 · tpc v0.9.4 |
| macOS | Apple Silicon · Homebrew `qtbase` 6.11.2 · tpc-built private embed runtime |
| Linux | Debian 12 arm64 · `qt6-base-dev` 6.4.2 · cmake 3.25.1 · Qt 6.4.2 |

On Linux, `build` produces an ELF PIE aarch64 binary (about 58 MB); under `QT_QPA_PLATFORM=offscreen` `--selftest` is 14/14, `--difftest` 20/20 and `--shot` renders, all rc=0. `package` produces `dist/hello/` (measured: 85 `.so` files + 10 Qt plugins, 140.3 MB), and the artifact is equally green under `env -i QT_QPA_PLATFORM=offscreen`.

## Linux dependencies

The first build compiles a private PHP embed runtime, so besides Qt you need:

```bash
apt install -y qt6-base-dev cmake g++ pkg-config bison re2c autoconf xz-utils patchelf \
  zlib1g-dev libxml2-dev libsqlite3-dev libonig-dev libgmp-dev libmpfr-dev
```

| Package | Purpose |
|---|---|
| `qt6-base-dev` | Qt 6 Widgets |
| `cmake` `g++` `pkg-config` | build |
| `bison` `re2c` `autoconf` `xz-utils` | the PHP source build flow |
| `patchelf` | rewrites DT_RPATH when packaging |
| `zlib1g-dev` `libxml2-dev` `libsqlite3-dev` `libonig-dev` | PHP extensions |
| `libgmp-dev` `libmpfr-dev` | phpx dependencies |

On Linux `qtphp doctor` checks these 12 items and prints a paste-ready `apt install -y …` for whatever is missing (WARN only).

## Platform differences at a glance

| Concern | Windows | macOS | Linux |
|---|---|---|---|
| Artifact name | `<name>.exe` | `<name>` | `<name>` |
| Build entry yml | `project.yml` | `project.macos.yml` | `project.linux.yml` |
| C++ compiler | MSVC (`cl.exe`) | `clang++` | `g++` |
| Qt linking | `Qt6Xxx.lib` | frameworks (`-F` + `-I`) | `-lQt6Xxx` |
| Qt location | common `C:/D:` paths | brew keg-only | Debian multiarch `/usr` |
| PHP runtime | DLLs must be copied | **fully static, nothing to copy** | as macOS |
| Packaging tool | `windeployqt` | `macdeployqt` | assemble by hand (ldd closure) |
| Dependency check | DLLs present | `otool -L` | `ldd` |
| GUI subsystem | `--no-console` | n/a | n/a |
| Headless platform plugin | `qwindows.dll` | must **copy in** `libqoffscreen.dylib` | the whole `platforms/` dir is already in the bundle |

## Known boundaries

The following are **not yet verified** (per the project's own records):

- **xcb on a real X desktop** — the Linux side has only been verified under offscreen.
- **The `eglfs` / `linuxfb` / `vnc` platform plugins** — untested.
- **Cross-distro** — only tested on Debian 12; Ubuntu / Fedora untested.
- **Copying the offscreen plugin on Windows** — the logic mirrors macOS, but no machine was available to verify it.
- **Pure tray apps** — the package treats the tray as an add-on to a window app; a background-resident tray app needs a hand-written bridge.

## Platform-specific build flags

| Flag | Platform | Why |
|---|---|---|
| `/Zc:__cplusplus` | Windows | required by the Qt MSVC headers |
| `/permissive-` | Windows | as above |
| `/EHsc` | Windows | exception handling |
| `-fPIC` | Linux | position-independent code |
| `-F<QT>/lib` | macOS | must appear in **both** `cxx-flags` and `ld-flags` |

There is a macOS gotcha with `-F`: framework forwarding headers such as `QtWidgets/qabstractitemview.h` use qualified names internally, so `-F` is needed at **compile** time as well as link time.
