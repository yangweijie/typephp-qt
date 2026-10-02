# CLI

`qtphp` 是包提供的可执行文件，装完在 `vendor/bin/qtphp`。

```
用法:
  qtphp doctor            检查工具链
  qtphp new <name>        创建新项目
  qtphp build <path>      编译项目
  qtphp run <path> [app参数…]  运行项目，其后的参数原样透传给应用
  qtphp package <path>    打包项目
  qtphp test              运行测试
  qtphp lint              校验桥接契约
```

## `qtphp doctor`

检查工具链，逐项打印实际路径：

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

检查项按平台分派：

| 项 | Windows | macOS | Linux |
|---|---|---|---|
| C++ 编译器 | MSVC（自动找 `vcvars64.bat`） | `clang++` | `g++` |
| Qt 位置 | `C:/D:` 盘常见路径 | brew keg-only | Debian 多架构 `/usr` |
| PHP 运行时库 | 与 tpc 同目录的 `phpx.dll` 等 | `~/.typephp/php-builder/*/install/lib` | 同 macOS |
| 额外前置 | — | — | 12 项（见下） |

Linux 上额外检查 12 项构建/打包前置，缺哪个就打出可直接粘贴的命令：

```
[WARN] 缺少构建前置：bison re2c patchelf
       可运行：apt install -y bison re2c patchelf
```

**只 WARN，不影响退出码** —— doctor 的 rc 只由 error 级项决定。

退出码：`0` 全部就绪，`1` 有 error 级问题。

## `qtphp new <name>`

创建新项目。生成的文件：

```
<name>/
├── src/main.php            # 入口骨架（可编译可运行）
├── project.yml             # Windows 编译入口
├── project.macos.yml       # macOS 入口
├── project.linux.yml       # Linux 入口
├── Info.macos.plist        # 打包 .app 用
├── build.bat / run.bat / package.bat   # Windows 便捷脚本
├── assets/                 # 运行时资源
└── README.md
```

**生成的骨架开箱即可编译运行** —— 你只需要替换界面和数据层。

`<name>` 必须是合法标识符（字母开头，只含字母数字下划线）。

## `qtphp build <path>`

编译。入口 yml 按平台挑：

| 平台 | 入口 |
|---|---|
| Windows | `project.yml` |
| macOS | `project.macos.yml`（回落 `project.yml`） |
| Linux | `project.linux.yml`（回落 `project.yml`） |

Windows 上编译成功后**自动部署运行时 DLL** 到 `build/`：

```
[OK] 已部署 11 个运行时文件到 build/
```

产物：`build/<name>.exe`（Windows）或 `build/<name>`（macOS/Linux）。

::: tip 首次构建会慢
macOS/Linux 上第一次 `build` 会让 tpc 从 php-src 现编一份私有 embed 运行时，缓存在 `~/.typephp`。之后复用。注意 tpc 每次构建都要访问 php.net 核对源码 SHA-256 —— **纯离线机器第一次会失败**。
:::

## `qtphp run <path> [应用参数…]`

运行产物。**第三个参数起原样透传**：

```bash
qtphp run . --selftest
qtphp run . --shot out.png
qtphp run . --difftest
```

启动前做依赖自检：

| 平台 | 检查方式 |
|---|---|
| Windows | 查 DLL 是否齐全 |
| macOS | `otool -L` 查是否有 bundle 外绝对路径 |
| Linux | `ldd` 查 `not found` |

退出码逐位传递（应用返回 7，`qtphp run` 也返回 7）。

## `qtphp package <path>`

打包自包含产物并**自检**：

| 平台 | 产物 |
|---|---|
| Windows | `dist/` 目录（windeployqt + PHP/PHPX 运行时 + 平台插件） |
| macOS | `dist/<Name>.app`（macdeployqt + ad-hoc 签名 + 补拷 offscreen 插件） |
| Linux | `dist/<name>/`（ldd 闭包搬进 `lib/` + `patchelf` 改 DT_RPATH + `qt.conf`） |

自检失败会 `rc=1` 并说明原因，不会假装成功。详见[打包](/zh/reference/packaging.md)。

## `qtphp test`

跑 PHPUnit。**不需要 Qt，也不需要编译器** —— 全部走纯 PHP 的 `FakeBridge` 替身。

```
OK (112 tests, 183 assertions)
```

## `qtphp lint`

校验桥接契约（stub ⇄ C++ 符号一致）：

```
[OK] 契约一致！
```

不一致时列出具体符号。改桥接后必跑。

## 环境变量

| 变量 | 作用 |
|---|---|
| `TPC` | 直接指定 tpc 可执行文件路径（跳过运行时探测） |
| `TPC_DIR` | 指定 tpc 安装目录 |
| `TPC_RUNTIME_DIR` | 指定 PHP/PHPX 运行时库目录 |
| `QT_DIR` | 指定 Qt 安装目录 |
| `PHPX_HOME` | 指定 phpx 源码树位置（影响 tpc 的运行时解析） |
| `QT_QPA_PLATFORM` | Qt 平台插件；无头环境设 `offscreen` |
