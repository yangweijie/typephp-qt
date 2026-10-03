# 打包

`qtphp package .` 组装自包含产物**并自检** —— 自检失败会 `rc=1`，不会假装成功。

```bash
qtphp package .
```

## 为什么需要它

`windeployqt`（macOS 上是 `macdeployqt`）只覆盖 **Qt**。还有两类文件必须一起拷，漏掉任一类就是经典的"在我机器上能跑"：

| 什么 | 为什么部署工具漏了它 |
|---|---|
| TypePHP / PHPX 运行时 | 不是 Qt 的库 |
| 你自己的文件（图标、模板、数据） | 也不是 Qt 的库 |

## Windows

产物是 `dist/` 目录：

```
dist/
├── <app>.exe
├── Qt6Core.dll  Qt6Gui.dll  Qt6Widgets.dll     ← windeployqt
├── platforms/qwindows.dll                       ← windeployqt
├── phpx.dll  php8ts.dll  libmpdec*.dll          ← 显式拷贝
│   gmp-10.dll  mpfr-6.dll
├── assets/                                      ← 显式拷贝
└── runtime.ini                                  ← 若用了 PHP 扩展
```

### 运行时 DLL 清单怎么来的

不是猜的 —— 是**可执行文件的导入表闭包**。一个纯 Widgets 应用是：

```
phpx.dll  php8ts.dll  libmpdec++-4.0.1.dll  libmpdec-4.0.1.dll  gmp-10.dll  mpfr-6.dll
```

自己核对：

```bat
dumpbin /nologo /dependents <app>.exe | findstr /I /R /C:"\.dll"
```

然后对每个非系统 DLL 重复，直到只剩 `api-ms-win-*` / `KERNEL32` / `USER32` 之类。**如果加载了 PHP 扩展要往清单里加** —— `pdo_sqlite` 需要 `libsqlite3.dll`。

### `assets/` 约定

运行时读的文件放项目的 `assets/`。目录结构会保留（`assets/icon.png` → `<app目录>/assets/icon.png`），而且相对路径按**可执行文件所在目录**解析，所以同一句 `'assets/icon.png'` 在项目目录和 `dist/` 里都成立。

macOS 的 `.app` 里可执行文件在 `Contents/MacOS`，而 assets 落在 `Contents/Resources/assets` —— 解析器会在这两种情况之间多查一层 `Contents/Resources`，完整顺序是：**exe 目录 → bundle 的 `Contents/Resources` → 工作目录**。所以打包产物里不必把 assets 再拷一份到 `MacOS/` 下。

这个约定存在，是因为"图标也要拷"正是最容易忘的那一步。一个目录 + 一个负责拷它的打包脚本，胜过 README 里的一句话。

## macOS

产物是 `dist/<Name>.app`：

```
dist/MyApp.app/
└── Contents/
    ├── Info.plist
    ├── MacOS/myapp
    ├── Frameworks/          ← Qt frameworks
    ├── PlugIns/
    │   └── platforms/
    │       ├── libqcocoa.dylib
    │       └── libqoffscreen.dylib    ← qtphp 补拷的
    └── Resources/           ← 你的 assets/
```

流程：`macdeployqt -always-overwrite -no-codesign` → 补拷 offscreen 插件 → ad-hoc `codesign --force --deep --sign -` → `plutil -lint` + `codesign --verify`。

::: tip PHP 在 macOS 上是全静态链接的
所以和 Windows 不同，**没有 PHP/PHPX 运行时库要搬**。
:::

::: warning 为什么要补拷 offscreen 插件
`macdeployqt` 只按目标平台拷插件 —— 只带 `libqcocoa.dylib` 的 bundle 在设了 `QT_QPA_PLATFORM=offscreen` 时会被 Qt 直接 abort（rc=134）。

`qtphp package` 会把 `libqoffscreen.dylib` 补拷进 `Contents/PlugIns/platforms/` 并把 Qt 引用改写成 `@executable_path`（约 +156 KB）。这样打包产物才能无头验收。
:::

ad-hoc 签名**只用于本机测试** —— 分发给别的 Mac 需要真实签名身份与公证。

## Linux

产物是 `dist/<name>/`：

```
dist/myapp/
├── myapp
├── lib/              ← ldd 传递闭包（85 个 .so 量级）
├── plugins/
│   ├── platforms/    ← 含 libqxcb.so、libqoffscreen.so
│   └── xcbglintegrations/
└── qt.conf           ← 指插件目录
```

Linux 上没有 `windeployqt` 等价物，所以要自己组装。关键点：

| 做法 | 原因 |
|---|---|
| 搬 **`ldd` 传递闭包** | 只搬直接依赖不够 |
| `.so` 用 **soname** 命名 | 不是符号链接名 |
| glibc 家族**留系统** | 搬了会崩 |
| `libstdc++` **要搬** | GLIBCXX 版本卡 ABI |
| `patchelf --force-rpath` | 让 loader 先找 `$ORIGIN/lib` |
| 对**可执行文件和每个插件**各跑一次 `ldd` | 插件的 xcb 依赖不在可执行文件的闭包里 |

::: danger 为什么必须 `--force-rpath` 而不是 `RUNPATH`
`RUNPATH` 是在 `ld.so.cache` **之后**搜索的 —— 它会静默地优先用构建机上的副本。`RPATH` 才在之前。

`qtphp` 的自检会读 `readelf -d` 断言「有 `(RPATH)` 且无 `(RUNPATH)`」。
:::

## 自检机制

打包脚本不只是拷文件 —— 它**证明结果能跑**。macOS 侧 `qtphp package` 最后会扫一遍依赖自包含性并真跑产物；手工验收时把 `PATH` 缩到最小（Windows 上是 `C:\Windows\System32`；macOS/Linux 用 `env -i`）挡住构建机环境，然后检查：

1. **依赖自包含** —— 扫 bundle 内**全部** Mach-O（framework 内部的引用也算），任何指向 bundle 之外的绝对路径都判 `rc=1`。`env -i` 挡不住这类文件系统依赖：实测 QtCore 引用 brew 的 ICU，`env -i` 全绿，换台没装 brew 的机器直接 dyld 起不来。
2. **退出码** —— 非零说明缺 DLL/so，报错会指出是哪个。
3. **没有 PHP 启动问题** —— 嵌入式运行时把缺扩展报成 **warning** 然后继续，所以干净退出还不够。自检扫描 stdout 和 stderr 里的 `PHP Startup` / `Fatal error`。
4. **真的渲染出一帧** —— 应用必须能产出它的截图。

::: tip 为什么第 3 条要扫 stdout
PHP 把那些启动 warning 写到 **stdout**，不是 stderr。只扫 stderr 会漏。
:::

任何一项失败都 `rc=1`，CI 能捕获。

## 手工验证

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

**发布前一定要做这一步** —— 它是唯一能真正抓到缺失依赖的检查。

## 用了 PHP 扩展

如果应用用了 PHP 扩展（如 `pdo_sqlite`），需要 `runtime.ini`：

```ini
extension_dir=D:\git\php\tpc_v0.9.4_windows_x64\ext
extension=php_pdo_sqlite.dll
```

运行前：

```bat
set "PHPRC=<项目目录>\runtime.ini"
set "PHP_INI_SCAN_DIR="
<app>.exe
```

`PHP_INI_SCAN_DIR=` 很重要：不设的话宿主机的 `php.ini` 扫描目录可能加载冲突的扩展。

而且别忘了把扩展依赖的 DLL 也加进部署清单（`pdo_sqlite` → `libsqlite3.dll`）。
