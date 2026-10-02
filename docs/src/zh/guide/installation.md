# 安装

## 装包

```bash
composer require yangweijie/typephp-qt
```

包提供 `qtphp` 可执行文件，装完就在 `vendor/bin/qtphp`。

## 前置依赖

| 组件 | 要求 | 说明 |
|---|---|---|
| PHP | >= 8.1 | 编译产物用的是 TypePHP 的 PHP 版本，不是这个 |
| TypePHP (`tpc`) | >= 0.9 | AOT 编译器，见下 |
| Qt | 6.x | Widgets 模块即可；QML 路线另需 Qml/Quick |
| C++ 编译器 | MSVC 2022 / clang++ / g++ | Qt SDK 用什么编译器，你就得用什么 |

## 检查工具链

```bash
qtphp doctor
```

它会逐项检查并打印实际路径：

```
=== TypePHP\Qt 工具链检查 ===

[OK] PHP 8.5.11
[OK] tpc: D:\git\php\tpc_v0.9.4_windows_x64\tpc.exe
[OK] PHP 运行时库: D:\git\php\tpc_v0.9.4_windows_x64
[OK] Qt: D:/tools/Qt/6.9.3/msvc2022_64
[OK] MSVC: ...\vcvars64.bat
[OK] PHPUnit: ...\vendor/bin/phpunit

[OK] 核心工具链就绪！
```

`tpc` 与「PHP 运行时库」是两行独立的检查 —— 它们不总是同一个目录，排查构建失败时先看这两行。原因见 [两条 tpc 供给路线](/zh/advanced/aot-notes.md#两条-tpc-供给路线)。

## 安装 Qt 本身

下面的命令顺带把 Qt 也装上了。要了解细节 —— 需要哪些模块、其他发行版、非默认 Qt 版本、
链接失败怎么查 —— 看 **[Qt 的安装与编译](/zh/guide/qt-setup.md)**。

## 平台依赖

### Windows

- MSVC 2022（或 BuildTools）—— Qt SDK 是按 MSVC 编译的，MinGW 链接不上。
- Qt 6 MSVC x64。
- `qtphp` 会自动 `call vcvars64.bat`，你不需要手动进 MSVC 环境。

### macOS

```bash
brew install qtbase libiconv
```

首次 `qtphp build` 会让 tpc 从 php-src 现编一份私有 embed 运行时，缓存在 `~/.typephp`；之后复用。注意 tpc 每次构建都要访问 php.net 的 releases 索引核对源码 SHA-256，**纯离线机器第一次构建会失败**。

### Linux（Debian/Ubuntu）

```bash
apt install -y qt6-base-dev cmake g++ pkg-config bison re2c autoconf xz-utils patchelf \
  zlib1g-dev libxml2-dev libsqlite3-dev libonig-dev libgmp-dev libmpfr-dev
```

`qtphp doctor` 在 Linux 上会额外检查这些前置，缺哪个就打出可直接粘贴的 `apt install -y …`（只 WARN，不影响退出码）。

## 指定编译器

如果机器上有多个 tpc，用环境变量显式指定：

```bash
TPC=/path/to/tpc.exe   qtphp build .   # 直接指定编译器
TPC_DIR=/path/to/dir   qtphp build .   # 指定安装目录
```

不指定时 `qtphp` 会自动选**带运行时**的那个。细节见 [AOT 注意事项](/zh/advanced/aot-notes.md)。
