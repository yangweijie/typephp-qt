# Installation

## Install the package

```bash
composer require yangweijie/typephp-qt
```

The package provides the `qtphp` executable; it lands in `vendor/bin/qtphp`.

## Requirements

| Component | Version | Note |
|---|---|---|
| PHP | >= 8.1 | The compiled binary uses TypePHP's PHP, not this one |
| TypePHP (`tpc`) | >= 0.9 | The AOT compiler — see below |
| Qt | 6.x | Widgets is enough; the QML route also needs Qml/Quick |
| C++ compiler | MSVC 2022 / clang++ / g++ | Must match whatever built your Qt SDK |

## Check the toolchain

```bash
qtphp doctor
```

It checks each item and prints the resolved paths:

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

`tpc` and "PHP runtime library" are two **separate** checks — they are not always the same directory. Look at those two lines first when a build fails. The reason is in [the two tpc supply routes](/advanced/aot-notes.md#the-two-tpc-supply-routes).

## Per-platform dependencies

### Windows

- MSVC 2022 (or BuildTools) — the Qt SDK is built against MSVC, so MinGW will not link against it.
- Qt 6 MSVC x64.
- `qtphp` calls `vcvars64.bat` for you; you do not need to enter an MSVC environment by hand.

### macOS

```bash
brew install qtbase libiconv
```

The first `qtphp build` makes tpc compile a private embed runtime from php-src and cache it under `~/.typephp`; later builds reuse it. Note that tpc contacts php.net's releases index on every build to verify the source SHA-256 — **a fully offline machine fails on the very first build**.

### Linux (Debian/Ubuntu)

```bash
apt install -y qt6-base-dev cmake g++ pkg-config bison re2c autoconf xz-utils patchelf \
  zlib1g-dev libxml2-dev libsqlite3-dev libonig-dev libgmp-dev libmpfr-dev
```

On Linux `qtphp doctor` additionally checks these, and prints a paste-ready `apt install -y …` for whatever is missing (WARN only — it does not affect the exit code).

## Picking a compiler

If a machine has more than one tpc, name the one you want:

```bash
TPC=/path/to/tpc.exe   qtphp build .   # point straight at the executable
TPC_DIR=/path/to/dir   qtphp build .   # point at the install directory
```

Without either, `qtphp` automatically picks the one that **has a runtime**. Details in [AOT Notes](/advanced/aot-notes.md).
