# Qt 的安装与编译

这一页讲 Qt 本身：**需要哪些模块、各系统怎么装、编译时怎么链接**。只想先跑出窗口的话看
[安装](/zh/guide/installation.md) 的简版即可 —— 来这里是因为 Qt 找不到、版本不一样，或者链接报错。

## 到底需要什么

桥接只用**三个 Qt 模块，别的都不用**：

| 模块 | 提供 |
|---|---|
| `QtCore` | `QString`、容器、事件循环、定时器、`QFileInfo`、`QDir` |
| `QtGui` | `QIcon`、`QPixmap`、`QFont`、`QClipboard`、`QScreen`、`QAction` |
| `QtWidgets` | 全部控件：`QMainWindow`、`QPushButton`、`QTableWidget` … |

没有 QML/Quick，没有 Network，没有 Sql —— 这是对着 `cpp-src/` 里每一个 `#include` 核过的。
所以**装一个最小的 Qt Widgets 就够了**，下面给的就是最小集。

::: tip webview 控件不需要额外的 Qt 模块
`WidgetTree::webView()` 在 **Windows 上用 WebView2**（微软 Edge 内核，不是 Qt 模块 ——
SDK 已 vendor 在 `third_party/`，运行时随 Windows 自带），在 **macOS 上用 WKWebView**（系统自带的
WebKit 框架，同样不是 Qt 模块），**其余平台用 QTextBrowser**，它是 QtWidgets 的一部分。
所以启用 webview 永远不需要装 QtWebEngine —— 那会多出 1.5–2 GB。
详见 [WebView](/zh/widgets/webview.md)。
:::

::: tip 版本要求
Qt **6.0 及以上**。项目开发和实测用的是 **6.9.3**；`qtphp` 的自动探测也是照 6.9.3 的路径找的，
所以**其他版本要显式给 `QT_DIR`**（见下文）。Qt 5 不行 —— 代码全程用的是 Qt 6 API。
:::

## Windows

Windows 要装**两样东西**，而且必须彼此匹配：

1. **C++ 编译器** —— MSVC 2022（或 BuildTools）。MinGW **链接不上** MSVC 编译的 Qt。
2. **用同一个编译器构建的 Qt 6** —— 也就是 `msvc2022_64` 这个 kit。

### 方案 A —— 官方在线安装器（推荐）

从 <https://www.qt.io/download-qt-installer> 下载 Qt Online Installer，用免费 Qt 账号登录，
然后在 **Qt → Qt 6.x.x** 下面**只勾**：

- **MSVC 2022 64-bit** ← 要的就是它
- （可选）*Qt 5 Compatibility Module* —— 本项目用不到

*Sources*、*Qt Debug Information Files* 以及其他 kit（MinGW、Android、WebAssembly、ARM）
全都别勾 —— 那是几个 G 的空间，你永远不会链接它们。

装到**没有空格的路径**（默认 `C:\Qt` 就行）。`qtphp` 会探测
`C:/Qt/6.9.3/msvc2022_64`、`D:/Qt/6.9.3/msvc2022_64` 和 `D:/tools/Qt/6.9.3/msvc2022_64`。

### 方案 B —— aqtinstall（可脚本化，不用账号）

```bash
pip install aqtinstall
aqt install-qt windows desktop 6.9.3 win64_msvc2022_64 -O C:/Qt
```

适合 CI 或受限的机器。装出来的目录结构和官方安装器一致，所以自动探测照样有效。

### 验证

```bat
C:\Qt\6.9.3\msvc2022_64\bin\qmake.exe -query QT_VERSION
```

同目录下还应该有 `windeployqt.exe` —— `qtphp package` 需要它。

::: warning MSVC 环境
光有 `cl.exe` 在 `PATH` 上不够，`INCLUDE` 和 `LIB` 也得设好。`qtphp` 会自己调
`vcvars64.bat`，所以你不需要开「Developer Command Prompt」。
:::

## macOS

官方安装器也能用，但 **Homebrew 的 `qtbase` 更小更简单** —— 它正好就是上面那三个模块：

```bash
brew install qtbase libiconv
```

- `qtbase` 是 **keg-only**，**不会**软链进 `/usr/local` —— 所以 `qtphp` 去探测
  `/opt/homebrew/opt/qtbase`（Apple Silicon）和 `/usr/local/opt/qtbase`（Intel）。
- `libiconv` 同样是 keg-only；生成的 `project.macos.yml` 会把它的 `-L` 与 `-rpath` 显式带上。
- 想要完整版 Qt（QML、Charts 等）：`brew install qt` —— 更重，`qtphp` 也把
  `/opt/homebrew/opt/qt` 作为兜底候选。

### 为什么 macOS 的链接方式不一样

Homebrew 的 `qtbase` 是 **framework** 布局：模块头在 `lib/Qt<Module>.framework/Headers/`，
不在 `include/Qt<Module>/`。而且 Qt 的转发头内部用的是**限定名** include
（`<QtWidgets/qabstractitemview.h>`），所以编译期必须**同时**给 `-I`（解裸名）和 `-F`（解限定名）。
脚手架生成的 `project.macos.yml` 两个都给了；少任何一个都会编译失败。

```yaml
cxx-flags:
  - -F/opt/homebrew/opt/qtbase/lib
  - -I/opt/homebrew/opt/qtbase/lib/QtCore.framework/Headers
  # … Gui、Widgets
ld-flags:
  - -F/opt/homebrew/opt/qtbase/lib
  - -Wl,-framework,QtWidgets
  - -Wl,-framework,QtGui
  - -Wl,-framework,QtCore
```

## Linux

用发行版的包就对了。**Debian / Ubuntu**：

```bash
apt install -y qt6-base-dev
```

`qt6-base-dev` 正好就是 Core + Gui + Widgets。Qt 这边装这一个包就够 ——
[安装](/zh/guide/installation.md#linux-debian-ubuntu) 里那一长串包是给「构建私有 PHP embed 运行时」用的，
不是 Qt 需要的。

其他发行版：

| 发行版 | 命令 | Qt 头文件位置 |
|---|---|---|
| Debian / Ubuntu | `apt install qt6-base-dev` | `/usr/include/<三元组>/qt6/` |
| Fedora / RHEL | `dnf install qt6-qtbase-devel` | `/usr/include/qt6/` |
| Arch | `pacman -S qt6-base` | `/usr/include/qt6/` |
| openSUSE | `zypper install qt6-base-devel` | `/usr/include/qt6/` |

::: warning Debian 的多架构布局
Debian 把头文件放在 `/usr/include/x86_64-linux-gnu/qt6/`（或 `aarch64-linux-gnu`），
而不是 `/usr/include/qt6/`。`qtphp` 会用 `dpkg-architecture -qDEB_HOST_MULTIARCH` 探测，
并生成正确的路径。

**Arch / Fedora / openSUSE 上**，要手动改 `project.linux.yml`，把 Debian 风格的路径换成上表里的
扁平路径 `/usr/include/qt6` 与 `/usr/lib` —— 这是那几个发行版上唯一需要手改的地方。
:::

### 验证

```bash
pkg-config --modversion Qt6Widgets    # 需要 qt6-base-dev
```

## 让构建指向非默认位置的 Qt

`qtphp` 的探测覆盖的是 6.9.3 的常见位置。**其他情况 —— 不同版本、自定义前缀、第二份 Qt ——
都要给 `QT_DIR`，它的优先级高于所有自动探测到的路径：**

```bash
QT_DIR=/path/to/Qt/6.8.2/gcc_64    qtphp build .
QT_DIR=C:/Qt/6.10.0/msvc2022_64    qtphp build .
```

`qtphp new` 会把探测到的路径**以纯字符串**写进 `project.yml` / `project.macos.yml` /
`project.linux.yml`，所以你也可以直接改那几个文件 —— 对要反复构建的项目往往更省事：

```yaml
include-paths:
  - /opt/Qt/6.10.0/gcc_64/include
  - /opt/Qt/6.10.0/gcc_64/include/QtCore
  - /opt/Qt/6.10.0/gcc_64/include/QtGui
  - /opt/Qt/6.10.0/gcc_64/include/QtWidgets
link-paths:
  - /opt/Qt/6.10.0/gcc_64/lib
```

改完确认一下：

```bash
qtphp doctor     # 打印它将要使用的 Qt 路径
```

`QT_DIR` 优先于自动探测，所以即使机器上已经有一份位于探测路径里的 Qt，它照样生效。
如果它指向的目录不存在，`qtphp` 会告警并回落到自动探测。

## 排错

| 现象 | 原因与解法 |
|---|---|
| `doctor` 报 `Qt: 未找到` | Qt 不在探测位置上 → 设 `QT_DIR` |
| `fatal error: 'QApplication': No such file or directory` | `include-paths` 里缺 `include/QtWidgets`（或 framework 的 `-I`） |
| MSVC 报 `cannot open file 'Qt6Widgets.lib'` | `link-paths` 缺 `<QT>/lib`，**或**装成了 MinGW kit 而不是 `msvc2022_64` |
| GCC/Clang 报 `undefined reference to 'QApplication::…'` | `link-libs` 缺 `-lQt6Widgets`（macOS 上是 `-Wl,-framework,QtWidgets`） |
| macOS 报 `'QtWidgets/qabstractitemview.h' file not found` | **编译期**缺 `-F` —— 见上面 macOS 那节 |
| LNK2038 / MSVC 运行时版本不匹配 | Qt 用的 MSVC 工具集和你的编译器不一致 → 装匹配的 kit |
| 运行时报 `could not find or load the Qt platform plugin windows` | 平台插件没部署 → `qtphp build` 会自动做；见[打包](/zh/reference/packaging.md) |

## 相关

- [安装](/zh/guide/installation.md) —— 整套工具链的简版
- [平台支持](/zh/reference/platforms.md) —— 各平台能力矩阵
- [打包](/zh/reference/packaging.md) —— 二进制旁边要带哪些东西
