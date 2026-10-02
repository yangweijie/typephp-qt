# 平台支持

## 能力矩阵

| 平台 | 编译 / 运行 / 无头验收 | 打包 | 依赖 |
|---|---|---|---|
| Windows | ✅ | ✅ `dist/` 目录（windeployqt） | Qt 6 + MSVC |
| macOS (Apple Silicon) | ✅ | ✅ `dist/<Name>.app`（macdeployqt + ad-hoc 签名） | `brew install qtbase libiconv` |
| Linux (Debian/Ubuntu) | ✅ | ✅ `dist/<name>/`（ldd 闭包 + patchelf + `qt.conf`） | 见下 |

三个平台都是 `--selftest` 14/14、`--difftest` 20/20。

## 实测环境

| 平台 | 环境 |
|---|---|
| Windows | Windows 10.0.26100 · MSVC 2022 BuildTools · Qt 6.9.3 msvc2022_64 · tpc v0.9.4 |
| macOS | Apple Silicon · Homebrew `qtbase` 6.11.2 · tpc 自建私有 embed 运行时 |
| Linux | Debian 12 arm64 · `qt6-base-dev` 6.4.2 · cmake 3.25.1 · Qt 6.4.2 |

Linux 侧 `build` 产出 ELF PIE aarch64（约 58 MB），`QT_QPA_PLATFORM=offscreen` 下 `--selftest` 14/14、`--difftest` 20/20、`--shot` 出图全部 rc=0。`package` 出 `dist/hello/`（实测 85 个 `.so` + 10 个 Qt 插件，140.3 MB），产物在 `env -i QT_QPA_PLATFORM=offscreen` 下同样全绿。

## Linux 依赖

首次编译要现编私有 PHP embed 运行时，除 Qt 外还需要：

```bash
apt install -y qt6-base-dev cmake g++ pkg-config bison re2c autoconf xz-utils patchelf \
  zlib1g-dev libxml2-dev libsqlite3-dev libonig-dev libgmp-dev libmpfr-dev
```

| 包 | 用途 |
|---|---|
| `qt6-base-dev` | Qt 6 Widgets |
| `cmake` `g++` `pkg-config` | 构建 |
| `bison` `re2c` `autoconf` `xz-utils` | PHP 源码构建流程 |
| `patchelf` | 打包时改 DT_RPATH |
| `zlib1g-dev` `libxml2-dev` `libsqlite3-dev` `libonig-dev` | PHP 扩展 |
| `libgmp-dev` `libmpfr-dev` | phpx 依赖 |

`qtphp doctor` 在 Linux 上会检查这 12 项，缺哪个就打出可直接粘贴的 `apt install -y …`（只 WARN）。

## 平台差异速查

| 事项 | Windows | macOS | Linux |
|---|---|---|---|
| 产物名 | `<name>.exe` | `<name>` | `<name>` |
| 编译入口 yml | `project.yml` | `project.macos.yml` | `project.linux.yml` |
| C++ 编译器 | MSVC（`cl.exe`） | `clang++` | `g++` |
| Qt 链接方式 | `Qt6Xxx.lib` | framework（`-F` + `-I`） | `-lQt6Xxx` |
| Qt 定位 | `C:/D:` 盘常见路径 | brew keg-only | Debian 多架构 `/usr` |
| PHP 运行时 | 需搬 DLL | **全静态，无需搬** | 同 macOS |
| 打包工具 | `windeployqt` | `macdeployqt` | 自己组装（ldd 闭包） |
| 部署检查 | 查 DLL | `otool -L` | `ldd` |
| GUI 子系统 | `--no-console` | 不适用 | 不适用 |
| 无头平台插件 | `qwindows.dll` | 需**补拷** `libqoffscreen.dylib` | 包内已有整个 `platforms/` |

## 已知边界

以下**未实测**（来自项目记录）：

- **真实 X 桌面上的 xcb 运行** —— Linux 侧只在 offscreen 下验证过。
- **`eglfs` / `linuxfb` / `vnc` 平台插件** —— 未测。
- **跨发行版** —— 只在 Debian 12 上测过，Ubuntu / Fedora 未测。
- **Windows 上的 offscreen 插件补拷** —— 逻辑与 macOS 同款，本机无环境验证。
- **纯托盘应用** —— 包当前把托盘定位为窗口应用的附加能力，常驻后台的纯托盘应用需要手写桥接。

## 平台相关的构建开关

| 开关 | 平台 | 说明 |
|---|---|---|
| `/Zc:__cplusplus` | Windows | Qt MSVC 头文件要求 |
| `/permissive-` | Windows | 同上 |
| `/EHsc` | Windows | 异常处理 |
| `-fPIC` | Linux | 位置无关代码 |
| `-F<QT>/lib` | macOS | 必须同时在 `cxx-flags` 和 `ld-flags` 里 |

macOS 的 `-F` 有个坑：`QtWidgets/qabstractitemview.h` 这类 framework 转发头内部用限定名，所以 `-F` 除了链接期，**编译期也要给**。
