<?php

declare(strict_types=1);

// 由 bin/qtphp 拆分而来（函数体逐字未改）。

function cmdNew(string $name): int
{
    $targetDir = getcwd() . DIRECTORY_SEPARATOR . $name;
    if (is_dir($targetDir)) {
        error("目录已存在: $targetDir");
        return 1;
    }

    info("创建项目: $name");

    // 创建目录结构
    mkdir($targetDir, 0755, true);
    mkdir($targetDir . '/src', 0755, true);
    mkdir($targetDir . '/assets', 0755, true);

    $qtDir = findQt() ?? 'D:/tools/Qt/6.9.3/msvc2022_64';
    // 框架根目录（composer 包位置）：由 CLI 自身位置推导
    $pkgDir = $GLOBALS['rootDir'];

    // project.yml —— 桥接 C++ 源码直接参与本应用编译（源码内联方案）
    $projectYml = <<<YML
name: {$name}
mode: bin
output: build/{$name}

# sources 只写一遍，平台差异用条件条目表达（tpc 的 sources 支持 `path:` + `if:`）。
# include-paths **不支持**条件条目，所以 Linux/macOS 入口把 Qt 头目录放进 cxx-flags 的 -I，
# 而不是去覆盖 include-paths —— 那样做会把这里的两条仓库路径整体替换掉。
sources:
  - src/main.php
  - {$pkgDir}/src/QtApp.php
  - {$pkgDir}/src/WidgetTree.php
  - {$pkgDir}/php-src/qt.stub.php
  - {$pkgDir}/cpp-src/qt_loop.cc
  - {$pkgDir}/cpp-src/qt_render.cc
  - {$pkgDir}/cpp-src/qt_decor.cc
  - {$pkgDir}/cpp-src/qt_widgets.cc
  - {$pkgDir}/cpp-src/qt_webview.cc
  # nano 在 Windows 上缺几个目标文件（crc32 SIMD / Fiber CE / MSVC seed256）
  - path: {$pkgDir}/compat/msvc/php_nano_win_stubs.c
    if: PHP_OS_FAMILY == "Windows"
  # WKWebView 后端是 ObjC++，只有 Darwin 的 clang 能编
  - path: {$pkgDir}/cpp-src/qt_webview_wk.mm
    if: PHP_OS_FAMILY == "Darwin"

build-dir: build

# 固化编译参数：cxx-std 必须恰好 c++17（nano 严格校验），optimize: 2 即命令行 -O2。
cxx-std: c++17
optimize: 2

include-paths:
  - {$pkgDir}/cpp-src
  - {$pkgDir}/php-src
  - {$qtDir}/include
  - {$qtDir}/include/QtCore
  - {$qtDir}/include/QtGui
  - {$qtDir}/include/QtWidgets
  - {$qtDir}/include/QtNetwork

link-paths:
  # WebView2 loader（webview 控件用；SDK 已 vendor 在包里）
  - {$pkgDir}/third_party/webview2/x64
  - {$qtDir}/lib

link-libs:
  - WebView2Loader.dll.lib
  - Qt6Core.lib
  - Qt6Gui.lib
  - Qt6Widgets.lib
  - Qt6Network.lib

cxx-flags:
  - /Zc:__cplusplus
  - /permissive-
  - /EHsc
  # webview 控件用 WebView2 后端（完整 Chromium，支持 JS）。
  # 注释掉这两行就退化成 QTextBrowser（HTML 子集、不支持 JS），一样能编译运行。
  - /DQT_WEBVIEW2
  - /I"{$pkgDir}/third_party/webview2/include"
YML;
    file_put_contents($targetDir . '/project.yml', $projectYml);

    // project.macos.yml —— macOS 入口。brew 前缀按生成机实测推导：
    // Apple Silicon 是 /opt/homebrew/opt，Intel 是 /usr/local/opt，Windows 上生成时取默认值。
    $brewOpt = '/opt/homebrew/opt';
    if (preg_match('#^(.*)/opt/(?:qtbase|qt)$#', str_replace('\\', '/', $qtDir), $m)) {
        $brewOpt = $m[1] . '/opt';
    }
    $macQt = $brewOpt . '/qtbase';

    $macYml = <<<YML
# macOS 编译入口：`qtphp build` 在 Darwin 上用它（Windows 仍用 project.yml）。
# 组织方式对齐 typephp-compiler 的 examples/qt-taskboard/project.macos.yml。
#
# Homebrew 的 qtbase 是 framework 布局：include/ 下没有 QtCore/QtGui/QtWidgets 目录，
# 模块头在 lib/Qt<Module>.framework/Headers/。桥接源码写裸 include，而 QtWidgets 的转发头
# 内部又是限定 include，所以 -I（解裸名）与 -F（解限定名）必须同时给，缺一必失败。
#
# include 合并时列表是整体替换、不是追加。所以这里**故意不写** sources / include-paths：
#   - sources 在 project.yml 里，mac 独有的 qt_webview_wk.mm 由 Darwin 条件条目带进来
#   - Qt 头目录走 cxx-flags 的 -I，include-paths 保持继承，仓库内那两条路径不会丢
include:
  - project.yml

# 清掉 MSVC 的 .lib 名词法与链接目录：mac 走 framework，链接开关全在 ld-flags 里。
link-libs: []
link-paths: []

cxx-flags:
  - -F{$macQt}/lib
  - -I{$macQt}/lib/QtCore.framework/Headers
  - -I{$macQt}/lib/QtGui.framework/Headers
  - -I{$macQt}/lib/QtWidgets.framework/Headers
  - -I{$macQt}/lib/QtNetwork.framework/Headers
  - -DQT_CORE_LIB
  - -DQT_GUI_LIB
  - -DQT_WIDGETS_LIB
  - -DQT_NETWORK_LIB
  # webview 走系统 WebKit（WKWebView）后端：支持 JS、远程 url、data:，且零安装
  # （不像 brew 的 qtwebview 会把 QtWebEngine 拖进来）。去掉下面两行就退回
  # QTextBrowser（HTML 子集、无 JS），一样能编译运行。
  - -DQT_WEBVIEW_WK
  # qt_webview_wk.mm 里 WKWebView* 是 C++ 类的成员，需要 ARC；对纯 C++ 的 TU 无副作用。
  - -fobjc-arc

ld-flags:
  - -F{$macQt}/lib
  - -Wl,-framework,QtWidgets
  - -Wl,-framework,QtGui
  - -Wl,-framework,QtCore
  - -Wl,-framework,QtNetwork
  # .mm 直接使 NSString/NSURL/NSView；QtGui 虽依赖 AppKit，但 ld 不会替我们的目标文件
  # 去它的依赖里找符号 ⇒ 必须显式给。
  - -Wl,-framework,Foundation
  - -Wl,-framework,AppKit
  - -Wl,-framework,WebKit
  # brew 的 libiconv 是 keg-only，不会软链进 brew 的 lib，搜索路径与 rpath 都得显式给。
  - -L{$brewOpt}/libiconv/lib
  - -liconv
  - -Wl,-rpath,{$brewOpt}/libiconv/lib
  - -Wl,-rpath,{$macQt}/lib

# 宿主 PHP 通常只有 cli SAPI（无 libphp），让 tpc 从 php-src 现编私有 embed 运行时。
# 缓存在 ~/.typephp/php-builder 下，之后所有构建复用；首次构建会慢（要编译整个 php-src）。
php-builder:
  extensions: []
  zts: false
YML;
    file_put_contents($targetDir . '/project.macos.yml', $macYml);

    // project.linux.yml —— Linux 入口。Debian/Ubuntu 是「多架构三元组」布局：
    // 头在 /usr/include/<三元组>/qt6，库在 /usr/lib/<三元组>，mkspec 在 /usr/lib/<三元组>/qt6/mkspecs/linux-g++。
    // 三元组按当前机器推导（dpkg-architecture 的约定），不一致时改这三处路径即可。
    // 生成机未必就是目标 Linux 机（在 Windows/macOS 上 scaffold 很常见）。
    // 这时若拿 php_uname('m') 兜底会产出 `AMD64-linux-gnu` 这种不存在的三元组，
    // 生成的配置在任何 Linux 上都不对。宁可退回非 Debian 布局（Arch/openSUSE/Fedora
    // 的 /usr/include/qt6），Debian 用户按文件里的注释改一行即可。
    $triple = linuxMultiarchTriple();
    if ($triple !== null) {
        $linInc = '/usr/include/' . $triple . '/qt6';
        $linLib = '/usr/lib/' . $triple;
    } else {
        $linInc = '/usr/include/qt6';
        $linLib = '/usr/lib';
    }

    $linYml = <<<YML
# Linux 编译入口：`qtphp build` 在 Linux 上用它（Windows 用 project.yml，macOS 用 project.macos.yml）。
#
# Debian 系的 qt6-base-dev 是多架构布局，三元组 = `dpkg-architecture -qDEB_HOST_MULTIARCH`
# （x86_64-linux-gnu / aarch64-linux-gnu）。非 Debian 布局（Arch、openSUSE 的 /usr/include/qt6）
# 把下面推导出的头/库目录换成 `/usr/include/qt6` 与 `/usr/lib` 即可。
#
# 这里**故意不写** include-paths：tpc 合并时 list 是整体替换，一旦写了，
# project.yml 里那两条仓库内路径就会全丢。Qt 头目录改用 cxx-flags 的 -I 传。
include:
  - project.yml

# 清掉 MSVC 的 .lib 名词法；库搜索路径与链接开关全在 ld-flags 里。
link-libs: []
link-paths: []

cxx-flags:
  - -fPIC
  - -I{$linInc}
  - -I{$linInc}/QtCore
  - -I{$linInc}/QtGui
  - -I{$linInc}/QtWidgets
  - -I{$linInc}/QtNetwork
  # Qt 头会引用 mkspec 里的 qplatformdefs.h
  - -I{$linLib}/qt6/mkspecs/linux-g++
  - -DQT_CORE_LIB
  - -DQT_GUI_LIB
  - -DQT_WIDGETS_LIB
  - -DQT_NETWORK_LIB

ld-flags:
  - -L{$linLib}
  - -lQt6Widgets
  - -lQt6Gui
  - -lQt6Core
  - -lQt6Network

# 宿主 PHP 通常只有 cli SAPI（无 libphp），让 tpc 从 php-src 现编私有 embed 运行时。
# 缓存在 ~/.typephp/php-builder 下，之后所有构建复用；首次构建会慢（要编译整个 php-src）。
php-builder:
  extensions: []
  zts: false
YML;
    file_put_contents($targetDir . '/project.linux.yml', $linYml);

    // Info.macos.plist —— `qtphp package` 在 mac 上要往 dist/<Name>.app/Contents/Info.plist
    // 放一份；CFBundleExecutable 必须等于产物文件名（= 项目名）。
    $display = ucfirst($name);
    $plist = <<<PLIST
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>CFBundleDevelopmentRegion</key>
    <string>zh_CN</string>
    <key>CFBundleExecutable</key>
    <string>{$name}</string>
    <key>CFBundleIdentifier</key>
    <string>org.typephp.qt.{$name}</string>
    <key>CFBundleInfoDictionaryVersion</key>
    <string>6.0</string>
    <key>CFBundleName</key>
    <string>{$display}</string>
    <key>CFBundleDisplayName</key>
    <string>TypePHP Qt {$display}</string>
    <key>CFBundlePackageType</key>
    <string>APPL</string>
    <key>CFBundleShortVersionString</key>
    <string>0.1.0</string>
    <key>CFBundleVersion</key>
    <string>1</string>
    <key>LSMinimumSystemVersion</key>
    <string>13.0</string>
    <key>NSHighResolutionCapable</key>
    <true/>
</dict>
</plist>
PLIST;
    file_put_contents($targetDir . '/Info.macos.plist', $plist);

    // main.php —— 状态驱动的声明式示例
    $mainPhp = <<<'PHP'
<?php

declare(strict_types=1);

use TypePHP\Qt\QtApp;
use TypePHP\Qt\WidgetTree;

// 注意：AOT 要求全局作用域只有声明，所以 $state 定义在 main() 里，
// 闭包用 use (&$state) 按引用捕获。命令行参数从 main() 的参数拿。
function main(int $argc, array $argv): void
{
    /** @var array<string, mixed> $state 应用状态：事件改它，视图读它。 */
    $state = ['name' => 'World', 'greeting' => 'Hello, World!', 'clicks' => 0, 'tray' => 0];

    $app = new QtApp();
    $app->create(['name' => 'MyApp', 'version' => '1.0']);
    $app->createWindow('My App', ['width' => 720, 'height' => 480, 'centered' => true]);
    $app->setStatus(['就绪']);

    // 系统托盘：图标路径相对**可执行文件目录**解析（build/ 与 dist/ 都成立）。
    // 不传 icon 时桥接会兜底用窗口图标/标准图标 —— 无图标的托盘项在
    // macOS/Linux 上根本不显示，所以 assets/ 里放了一个默认图标。
    $app->setTray([
        'icon' => 'assets/icon.png',
        'tooltip' => 'My App · 左键点一下，右键出菜单',
        'visible' => true,
        // 右键菜单（可省略）。绑了之后右击由 Qt 弹菜单，不再发 right 事件。
        'menu' => [
            ['type' => 'item', 'id' => 'tray.hello', 'text' => '打个招呼'],
            ['type' => 'separator'],
            ['type' => 'item', 'id' => 'tray.quit', 'text' => '退出'],
        ],
    ]);

    // 托盘激活不带 id（点的是托盘项本身），只能用 onAny 接。
    // $event['value'] 是手势：left / right / double / middle。
    $app->onAny('tray', function (array $event) use ($app, &$state) {
        $state['tray'] = (int) $state['tray'] + 1;
        $kind = (string) ($event['value'] ?? '');
        $app->setStatus(['托盘 ' . $kind . ' × ' . $state['tray']]);
    });

    // 托盘菜单项和菜单栏一样走 menu 事件，按惯例用 'tray.' 前缀区分。
    $app->on('tray.hello', 'menu', function () use ($app, &$state) {
        $state['greeting'] = 'Hello, World!';
        $app->setStatus(['托盘菜单：已重置问候语']);
    });

    $app->on('tray.quit', 'menu', function () use ($app) {
        $app->close();
    });

    // 视图函数每帧按 $state 重新描述界面，控件状态（光标/选中）由 diff 保留
    $app->view(function () use (&$state, $app): array {
        return WidgetTree::vbox([
            WidgetTree::label((string) $state['greeting'], [
                'id' => 'greeting',
                'style' => 'font-size:20px;font-weight:bold',
            ]),
            WidgetTree::hbox([
                WidgetTree::lineEdit((string) $state['name'], ['id' => 'name_input', 'placeholder' => '输入名字']),
                WidgetTree::button('打招呼', ['id' => 'greet_btn']),
            ]),
            WidgetTree::label('点击次数：' . $state['clicks'] . ' · 托盘点击 ' . $state['tray'] . ' 次', ['id' => 'clicks']),

            // 内嵌网页：Windows 上是 WebView2（完整 Chromium、支持 JS），
            // 其余平台是 QTextBrowser（HTML 子集、不支持 JS）。
            // url 也可以是本地文件（相对可执行文件目录解析）。
            WidgetTree::group('WebView（后端 ' . $app->webViewBackend()
                . '，JS ' . ($app->webViewSupportsJs() ? '支持' : '不支持') . '）', [
                WidgetTree::html(
                    '<h3 style="color:#1d4ed8">内嵌网页</h3>'
                    . '<p>这是用 <b>WidgetTree::html()</b> 直接渲染的 HTML。</p>',
                    ['id' => 'demo_webview', 'grow' => 1]
                ),
            ], ['grow' => 1]),
        ]);
    });

    // 事件处理器只改状态。闭包参数必须显式标注类型。
    $app->on('greet_btn', 'click', function () use ($app, &$state) {
        $state['name'] = $app->text('name_input');
        $state['greeting'] = 'Hello, ' . ($state['name'] === '' ? 'World' : $state['name']) . '!';
        $state['clicks'] = (int) $state['clicks'] + 1;
        $app->setStatus(['已打招呼 · 第 ' . $state['clicks'] . ' 次']);
    });

    $app->on('name_input', 'submit', function (array $event) use (&$state) {
        $state['name'] = (string) ($event['value'] ?? '');
        $state['greeting'] = 'Hello, ' . ($state['name'] === '' ? 'World' : $state['name']) . '!';
    });

    // 无头验收：--shot <path> 渲染几帧后存 PNG 退出，便于自动化检查
    $shot = shot_path($argv);
    if ($shot !== '') {
        // 3 帧就够：snapshot() 抓帧前会把还在跑的瞬态动画推到终点
        // （否则抓到 QLineEdit 清除按钮的淡入淡出中途，同一产物连跑会出多种哈希）。
        $app->runFrames(3);
        $ok = $app->snapshot($shot);
        $app->destroy();
        if ($ok) {
            echo "snapshot: $shot\n";
            return;
        }
        echo "snapshot failed: $shot\n";
        exit(1);
    }

    // 无头自检：--selftest 逐个触发所有事件，验证每个 handler 都能调用。
    // AOT 编译后的闭包对实参个数做精确校验，参数个数不匹配只有真正
    // 触发事件时才暴露 —— 所以这个自检要实际分发事件。
    if (has_flag($argv, '--selftest')) {
        $app->headless(true);
        $app->run(1);
        $app->dispatch(['type' => 'click', 'id' => 'greet_btn']);
        $app->dispatch(['type' => 'submit', 'id' => 'name_input', 'value' => 'Ada']);
        $app->dispatch(['type' => 'tray', 'value' => 'left']);
        $app->dispatch(['type' => 'tray', 'value' => 'double']);
        $app->dispatch(['type' => 'menu', 'id' => 'tray.hello']);

        $failed = $app->lastError();
        $app->destroy();
        if ($failed !== '') {
            echo "selftest failed: ", $failed, "\n";
            exit(1);
        }
        echo "selftest passed\n";
        return;
    }

    $app->run();
}

/** 命令行里是否存在某个布尔开关。 */
function has_flag(array $argv, string $flag): bool
{
    $count = count($argv);
    for ($i = 1; $i < $count; $i++) {
        if ($argv[$i] === $flag) {
            return true;
        }
    }
    return false;
}

/** 从命令行参数里解析 `--shot <path>`；没有该参数返回空串。 */
function shot_path(array $argv): string
{
    $count = count($argv);
    for ($i = 1; $i < $count; $i++) {
        if ($argv[$i] === '--shot' && $i + 1 < $count) {
            return (string) $argv[$i + 1];
        }
    }
    return '';
}
PHP;
    file_put_contents($targetDir . '/src/main.php', $mainPhp);

    // 默认图标：托盘项没有图标在 macOS/Linux 上根本不显示，
    // 所以脚手架直接给一个可用的，而不是留空目录让人踩坑。
    $defaultIcon = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAEAAAABACAYAAACqaXHeAAAAAXNSR0IArs4c6QAAAARnQU1BAACxjwv8YQUAAAAJcEhZcwAADsMAAA7DAcdvqGQAAANLSURBVHhe7VsrkBQxED2JRCKRSCQSicTAzWCWqp0cSCRyBVVIJPIk8sxVnVyJpFAnT3HJFOJwJxBDvcwudfNms/PZznQW9lU9tUkm/SbpTndmj44OOOAADRzP7VPmbHZ1j9vtPV7MygdZcf02N+4iN9bmxlVdzIy7yY1bZoV7l5/8eMRjJo/jN/ahn7yxX9m4McwKd5kbu0heDBieG3fKBghz+aooH/OzVYFlnpvyU2bc7YYJR2FWlGcQnOcyObA0V3u2NclpWH5WcZ6z2c19vIX2hKZnZty3SVfDysld8kR0ae3Lwj3huYoD8Vp3yYdZ+yCb8ZzFAOP5oWkyggh+2Sf65plYCaLboXZ4qe35Llor5hjrYyw/IMDT2yoOflfnHzY8bwsRHXYOkZlxH3ngrUxIADAr3Be2qTd8IjP0dJeYAODoozOOtzxYJxMUAPkD29YJZF6D3z6YpgBVfuKes41bEe+Y+7M6d2xcVVXff21oK0c4RLYxCLx9HkCOOgJ49l0FvvjAncWoJ0DviIDlwp3lqCiAcTed54JVVafVWY56AoDZ/PoZ29xAPrfvuZMsdQVAAYVtbkCqkBmmtgDWss0NxM/4tAVwFZI7ttsDDoIby1NfgGB5Pb4DBPUFQGGHbfeYpuKTgACFfc22e6CUxI3lqS8AIh3b7rG6w2t3EGUCAhi7YNs9sDTajaWZsgD/vQ+ImgWuqS9A8DiMAwI3lqe+AFtLZKOqQIOoLwBqnWz3X8Sv/+sKgBfMNjcwuAw+mMoCFOUZ29xA/EigK0AwAtxF34+ZxlFXgGAmeBcoGnBHOaoK0O9+IO42UBQglANsQtzC6PREoWdr+GOghs6D7DPx3SLb2Il/ZxVY21kO3wQcGduD7R97hb4Q4t0RTsNBd4Kb4L8CLdwVD7wP9BXuUAF0CLAV4idJ8gymvWOwb1FhlNfvQvxrMyl2XH/tgrhX5xKMaPwa2A4p+oQoyz4Ef0ZIJDrA24s6vL5Y1RCXPKEp6StYEqFuF/gtEb2UxrQWFzk8F1XUN0sxiynrf5TZxaiz/RTAxOp/jMlujToxs4tBKa024COQiODrrJFR4wKrSuyrb23AWa3/JVqfJ5qEJ8dvWy8uDjhAFH8AwOcOrNkF73MAAAAASUVORK5CYII=');
    file_put_contents($targetDir . '/assets/icon.png', $defaultIcon);

    // build.bat —— 编译 + 部署运行时 DLL
    $vcvars = findVcvars() ?? 'D:\\Program Files (x86)\\Microsoft Visual Studio\\2022\\BuildTools\\VC\\Auxiliary\\Build\\vcvars64.bat';
    $buildBat = <<<BAT
@echo off
setlocal
call "{$vcvars}" >nul 2>&1
cd /d %~dp0

php "{$pkgDir}/bin/qtphp" build .

endlocal & exit /b %errorlevel%
BAT;
    file_put_contents($targetDir . '/build.bat', $buildBat);

    // run.bat
    $runBat = <<<BAT
@echo off
setlocal
cd /d %~dp0

if not exist "build\\{$name}.exe" (
    echo [ERROR] 未找到 build\\{$name}.exe，请先运行 build.bat
    endlocal & exit /b 1
)

build\\{$name}.exe %*

endlocal & exit /b %errorlevel%
BAT;
    file_put_contents($targetDir . '/run.bat', $runBat);

    // package.bat
    $packageBat = <<<BAT
@echo off
setlocal
cd /d %~dp0

php "{$pkgDir}/bin/qtphp" package .

endlocal & exit /b %errorlevel%
BAT;
    file_put_contents($targetDir . '/package.bat', $packageBat);

    // README
    $readme = <<<MD
# {$name}

基于 TypePHP (AOT) + Qt 6 的原生桌面应用。

编译入口按平台挑选：`project.yml` 是 Windows 段，`project.macos.yml` 通过 `include` 继承它
再整体替换 Qt 相关段（brew 的 framework 布局 + 私有 embed 运行时）。`qtphp build` 会自动选对。

## 构建

Windows：

```bat
build.bat
```

macOS（或任意平台，走 CLI）：

```bash
qtphp build .
```

Windows 编译后会自动把 Qt / PHP 运行时 DLL 部署到 `build/`；macOS 的依赖来自 brew，
PHP 侧是静态链入，无需部署。

## 运行

```bat
run.bat
```

```bash
qtphp run .                # 图形界面
qtphp run . --selftest     # 无头自检：逐个触发所有事件
qtphp run . --shot out.png # 渲染几帧后存 PNG 退出
```

## 打包

```bat
package.bat
```

```bash
qtphp package .
```

Windows 组装自包含的 `dist/` 目录；macOS 产出 `dist/{$display}.app`
（macdeployqt 搬 Qt framework 与链接库 + ad-hoc 签名 + 自检）。两者都可直接分发。

## 写代码

编辑 `src/main.php`：

- `\$state` 保存应用状态
- `\$app->view(fn)` 每帧按状态描述界面
- `\$app->on(id, type, fn)` 处理事件，**只改状态**

控件状态（输入光标、表格选中、滚动位置）在重渲染后自动保留。
MD;
    file_put_contents($targetDir . '/README.md', $readme);

    success("项目已创建: $targetDir");
    info(PHP_OS_FAMILY === 'Windows'
        ? "下一步: cd $name && build.bat"
        : "下一步: cd $name && qtphp build . && qtphp run . --selftest");

    return 0;
}

