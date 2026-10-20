<?php

declare(strict_types=1);

// 由 bin/qtphp 拆分而来（新增：从 vendor 的 extra.bridge 注入桥接源）。

/**
 * 定位「PHP/PHPX 运行时库目录」—— 跟「tpc 可执行文件在哪」不是同一回事。
 *
 * composer 只发布纯 PHP 驱动（vendor/bin/tpc.php），它旁边一个运行时库都没有，
 * 拿它的 dirname 去找 phpx.dll 必然空手而归。库的真正来源只有两个：解发型发行包
 * （native tpc.exe 同目录），以及 tpc 用 php-builder 自建的私有运行时
 * ~/.typephp/php-builder/php-<版本>-<指纹>/install/lib。
 */
function findRuntimeLibDir(): ?string
{
    $markers = ['phpx.dll', 'php8ts.dll', 'libphp.a', 'libphp.dylib'];

    $candidates = [];
    $tpc = findTpc();
    // 只有 native 发行包才把运行时放在可执行文件旁边
    if ($tpc && strtolower(pathinfo($tpc, PATHINFO_EXTENSION)) !== 'php') {
        $candidates[] = dirname($tpc);
    }
    $env = getenv('TPC_RUNTIME_DIR');
    if ($env && is_dir($env)) {
        $candidates[] = $env;
    }

    // 私有运行时可能有多份（换过 PHP 版本就换一个指纹目录），取最近构建的
    $home = getenv('HOME') ?: getenv('USERPROFILE');
    if ($home) {
        $built = glob(rtrim($home, '/\\') . '/.typephp/php-builder/php-*/install/lib') ?: [];
        usort($built, fn(string $a, string $b): int => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
        $candidates = array_merge($candidates, $built);
    }

    foreach ($candidates as $dir) {
        foreach ($markers as $marker) {
            if (is_file($dir . '/' . $marker)) {
                return $dir;
            }
        }
    }
    return null;
}

/**
 * 从项目的 vendor/composer/installed.json 里收集本库的桥接源。
 *
 * TypePHP\Qt 的 composer.json 声明了 `extra.bridge = {stub, sources}`，但 tpc 没有
 * `--add-source` 之类的选项，也不会读 composer 元数据 —— 装了库的项目如果不同时把
 * `php-src/qt.stub.php` 和 `cpp-src/` 抄进自己的 project.yml，编译期就会缺桥接符号。
 * 这里把这份约定读出来，交给 injectSourcesIntoProjectYaml() 并进 yml。
 *
 * 返回绝对路径（去重，stub 排在最前），没装本库时返回空数组。
 */
function bridgeSourcesFromVendor(string $projectDir): array
{
    $dir = rtrim($projectDir, '/\\');
    $installed = $dir . '/vendor/composer/installed.json';
    if (!is_file($installed)) {
        return [];
    }

    $json = json_decode((string) file_get_contents($installed), true);
    if (!is_array($json)) {
        warning('vendor/composer/installed.json 不是合法 JSON，跳过桥接源注入');
        return [];
    }

    // composer 2 把包列表包在 packages 里；composer 1 直接就是数组
    $packages = isset($json['packages']) && is_array($json['packages']) ? $json['packages'] : $json;
    $vendorDir = $dir . '/vendor';

    $resolved = [];
    foreach ($packages as $package) {
        if (!is_array($package)) {
            continue;
        }
        $bridge = $package['extra']['bridge'] ?? null;
        if (!is_array($bridge)) {
            continue;
        }

        // composer 2 的 install-path 是相对 vendor/composer 的（如 ../../typephp/qt）
        $installPath = $package['install-path'] ?? null;
        $packageDir = null;
        if (is_string($installPath) && $installPath !== '') {
            $packageDir = realpath($vendorDir . '/composer/' . $installPath)
                ?: realpath($vendorDir . '/' . $installPath);
        }
        // composer 1 的 installed.json 没有 install-path，包名本身就是 vendor 下的相对路径
        if (!is_string($packageDir) && isset($package['name']) && is_string($package['name'])) {
            $packageDir = realpath($vendorDir . '/' . $package['name']);
        }
        if (!is_string($packageDir)) {
            continue;
        }

        $entries = [];
        if (isset($bridge['stub']) && is_string($bridge['stub'])) {
            $entries[] = $bridge['stub'];
        }
        foreach ((array) ($bridge['sources'] ?? []) as $source) {
            if (is_string($source)) {
                $entries[] = $source;
            }
        }

        foreach ($entries as $entry) {
            $abs = realpath($packageDir . '/' . $entry);
            if ($abs === false || !file_exists($abs)) {
                warning("桥接源不存在，已跳过: $packageDir/$entry");
                continue;
            }
            $resolved[$abs] = true;   // 用键去重：stub 和 sources 指到同一文件时只编一次
        }
    }

    return array_keys($resolved);
}

/**
 * 把绝对路径条目并进 yml 的 `sources:` 列表；无法安全注入时返回 null。
 *
 * 纯字符串操作（不落盘），方便单测直接喂文本。返回 null 的两种情况都必须让调用方
 * 退回原 yml —— 生成一个 tpc 解析不了的临时文件比「没注入」糟糕得多。
 */
function injectSourcesIntoProjectYaml(string $yaml, array $paths): ?string
{
    $items = [];
    foreach ($paths as $path) {
        // Windows 绝对路径带反斜杠和冒号，只有单引号形式能让 YAML 原样读回
        $items[] = "- '" . str_replace("'", "''", str_replace('\\', '/', $path)) . "'";
    }
    if ($items === []) {
        return $yaml;
    }

    // 内联写法 sources: [a, b] 后面插条目会变成语法错误，只能人工改成逐行列表
    if (preg_match('/^sources:[ \t]*\[/m', $yaml)) {
        return null;
    }

    if (preg_match('/^sources:[ \t]*(?:#.*)?$/m', $yaml, $m, PREG_OFFSET_CAPTURE)) {
        $offset = $m[0][1] + strlen($m[0][0]);

        // 沿用现有条目的缩进，否则列表项会被当成顶层键
        $indent = '  ';
        if (preg_match('/\n([ \t]*)- /', substr($yaml, $offset), $existing)) {
            $indent = $existing[1];
        }

        $block = '';
        foreach ($items as $item) {
            $block .= "\n" . $indent . $item;
        }
        return substr($yaml, 0, $offset) . $block . substr($yaml, $offset);
    }

    // 没有 sources 段（平台 yml 常常只写 include + 覆盖项）：整段追加
    $block = 'sources:';
    foreach ($items as $item) {
        $block .= "\n  " . $item;
    }
    $head = ($yaml !== '' && !str_ends_with($yaml, "\n")) ? $yaml . "\n" : $yaml;
    return $head . ($head === '' ? '' : "\n") . $block . "\n";
}

/**
 * 生成「原 yml + 桥接源」的临时文件，返回路径；不需要或失败时返回 null。
 *
 * 临时文件必须落在项目目录内：yml 里的 sources / cxx-flags / link-paths 全是相对
 * yml 所在目录解析的，放系统临时目录会让它们集体失效。tpc 接受任意文件名的 yml
 * （macOS 走 project.macos.yml 已验证），所以不会因为文件名不是 project.yml 出问题。
 */
function buildMergedProjectYaml(string $projectDir, string $projectYml): ?string
{
    $sources = bridgeSourcesFromVendor($projectDir);
    if ($sources === []) {
        return null;
    }

    $merged = injectSourcesIntoProjectYaml((string) @file_get_contents($projectYml), $sources);
    if ($merged === null) {
        warning('project.yml 的 sources 写成了内联数组，无法自动注入桥接源；请改成逐行列表');
        return null;
    }

    $tmp = rtrim($projectDir, '/\\') . '/.qtphp-bridge-' . getmypid() . '.yml';
    if (@file_put_contents($tmp, $merged) === false) {
        warning('无法写入临时 project.yml，跳过桥接源注入');
        return null;
    }

    info('已注入 ' . count($sources) . ' 个桥接源（临时 ' . basename($tmp) . '）');
    return $tmp;
}

function cmdBuild(string $path, array $flags = []): int
{
    $unknown = array_values(array_diff($flags, ['--nano']));
    if ($unknown !== []) {
        error('未知编译选项: ' . implode(' ', $unknown) . '（build 目前只认 --nano）');
        return 1;
    }
    // nano：php-nano + PHPX 的源码直接编进产物，不链任何 PHP 运行时 ⇒ 产物小一个量级。
    // 只在 macOS arm64 真机验证过（见 docs/src/reference/cli.md#nano）；Windows/Linux 是
    // 原样透传给 tpc，未实测。
    $nano = in_array('--nano', $flags, true);

    $projectYml = resolveProjectYaml($path);
    if (!file_exists($projectYml)) {
        error("未找到 project.yml: " . rtrim($path, '/\\') . '/project.yml');
        return 1;
    }

    info('编译项目: ' . $path . '（入口 ' . basename($projectYml) . '）');

    // nano 必须用 composer tpc.php：原生 tpc.exe 看不到本项目 vendor 的 php-nano。
    $tpc = $nano ? (findComposerTpc() ?? findTpc()) : findTpc();
    if (!$tpc) {
        error('未找到 tpc');
        return 1;
    }

    // tpc 需要 MSVC 环境（cl.exe / link.exe 在 PATH 上）。
    // 先写一个临时批处理，在里面 call vcvars64.bat 再调 tpc，
    // 避免依赖用户事先开好 "Developer Command Prompt"。
    $vcvars = findVcvars();
    $projectAbs = realpath($projectYml) ?: $projectYml;

    // 装了 TypePHP\Qt 的项目：把 extra.bridge 声明的桥接源并进一份临时 yml 再交给 tpc。
    // 没装库（或没声明 bridge）时返回 null，命令行与原行为逐字一致。
    $mergedYml = buildMergedProjectYaml(dirname($projectAbs), $projectAbs);

    $tpcCmd = tpcCommand($tpc) . ' ' . escapeshellarg($mergedYml ?? $projectAbs) . ' -O2';
    if ($nano) {
        // `--nano requires the C++17 language standard`（tpc 是严格相等校验）。示例/模板的
        // yml 已固化 c++17，这里保留 CLI 覆盖作兜底：yml 仍写 c++20 的老项目走 nano 不至于直接死。
        // `php-builder:` 段在 nano 下由 tpc 自己忽略（CompilerBase::isPhpBuilderBuild()）。
        $tpcCmd .= ' --nano --cxx-std c++17';
        if (PHP_OS_FAMILY === 'Windows') {
            // php-nano 的 php_compat.h 在 Windows 走 config.w32.h，而 HAVE_SLOW_HASH3
            // 只写在 php_config.h。不设这个宏，hash_sha3.c 会去 include KeccakHash.h，
            // 但 typephp-native.include-dirs 没有 sha3/generic*lc，编译直接死。
            $tpcCmd .= ' -DHAVE_SLOW_HASH3=1';
            // 不能给 tpc 传 -I：applyCommandLineArguments 会整表覆盖 userIncludePaths，
            // YAML 里的 Qt include 会被冲掉。MSVC 用 INCLUDE 找 <stdalign.h> 即可。
        }
        info('nano 模式：不链接 PHP 运行时（产物内不链 libphp/phpx）');
        info("tpc: $tpc");
    }

    // Homebrew 的 include/lib 得靠环境变量递给 clang：phpx 的 sapi-static/CMakeLists.txt
    // 没有根 CMakeLists 那段 brew 前缀探测，而 tpc 调 cmake 的参数是写死的、注入不了 flag。
    if (PHP_OS_FAMILY !== 'Windows') {
        $brewPrefix = trim((string) shell_exec('brew --prefix 2>/dev/null'));
        if ($brewPrefix !== '' && is_dir($brewPrefix)) {
            foreach (['CPATH' => '/include', 'LIBRARY_PATH' => '/lib'] as $var => $suffix) {
                $current = getenv($var);
                putenv($var . '=' . $brewPrefix . $suffix . ($current ? ':' . $current : ''));
            }
        }
    }

    $msvcStdalign = PHP_OS_FAMILY === 'Windows' && $nano
        ? $GLOBALS['rootDir'] . DIRECTORY_SEPARATOR . 'compat' . DIRECTORY_SEPARATOR . 'msvc'
        : null;

    if ($vcvars && !msvcEnvironmentReady()) {
        $bat = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qtphp-build-' . getmypid() . '.bat';
        $lines = [
            '@echo off',
            'call "' . $vcvars . '" >nul 2>&1',
        ];
        if ($msvcStdalign) {
            // vcvars 会重写 INCLUDE，必须接在 call 后面。
            $lines[] = 'set "INCLUDE=' . $msvcStdalign . ';%INCLUDE%"';
        }
        $lines[] = 'cd /d "' . (realpath($path) ?: $path) . '"';
        $lines[] = $tpcCmd;
        file_put_contents($bat, implode("\r\n", $lines) . "\r\n");
        // 临时 yml 必须在编译期间存活、编译结束立刻消失：中途失败也不能把它留在
        // 用户项目里（下一帧 `build` 会把 *.yml 当项目文件扫到）。
        try {
            passthru('cmd /c "' . $bat . '" 2>&1', $exitCode);
        } finally {
            @unlink($bat);
            if ($mergedYml !== null) {
                @unlink($mergedYml);
            }
        }
    } else {
        if ($msvcStdalign) {
            $cur = getenv('INCLUDE') ?: '';
            putenv('INCLUDE=' . $msvcStdalign . ($cur !== '' ? ';' . $cur : ''));
        }
        try {
            passthru($tpcCmd . ' 2>&1', $exitCode);
        } finally {
            if ($mergedYml !== null) {
                @unlink($mergedYml);
            }
        }
    }

    if ($exitCode !== 0) {
        error("编译失败 (exit $exitCode)");
        return $exitCode;
    }

    if (PHP_OS_FAMILY === 'Windows') {
        // 编译成功后自动部署运行时 DLL，否则双击 exe 会报"找不到 DLL"。
        // nano 产物不链 PHP/PHPX DLL —— 这一点由 tpc 自己的审计保证
        // （NativeDependencyAuditor::assertWindowsImports() 见到 php*.dll 直接把构建判失败），
        // 所以这里不用再搬。
        if (!$nano) {
            deployRuntimeDlls($path);
        }
    } else {
        // macOS/Linux 的 PHP 运行时是静态链接/私有运行时，没有 DLL 要搬，
        // 但 assets/ 同样要拷 —— 否则开发期相对路径失效、打包后才正常。
        deployAssets($path);
    }

    return 0;
}

/**
 * 当前进程是否已有可用的 MSVC 环境。
 *
 * 只查 cl.exe 不够 —— cl.exe 常在系统 PATH 上，但 INCLUDE/LIB 没设置，
 * 结果编译时报 "cstring: No such file or directory"。所以必须查 INCLUDE。
 */
function msvcEnvironmentReady(): bool
{
    $include = getenv('INCLUDE');
    return $include !== false && $include !== '';
}

/**
 * 把运行时依赖 DLL 复制到可执行文件旁。
 *
 * 覆盖三类：PHP/PHPX 运行时、Qt 运行库、MSVC 运行时。
 * Qt 的平台插件（platforms/qwindows.dll）不在这一步，由 package 阶段的
 * windeployqt 负责；开发期直接跑 exe 需要它，所以这里也一并部署。
 */
function deployRuntimeDlls(string $projectPath): void
{
    $buildDir = rtrim($projectPath, '/\\') . '/build';
    if (!is_dir($buildDir)) {
        return;
    }

    $runtimeDir = findRuntimeLibDir();
    $qtDir = findQt();

    $copied = 0;

    // PHP/PHPX 运行时 DLL 闭包（实测依赖）
    $runtimeDlls = [
        'phpx.dll',
        'php8ts.dll',
        'libmpdec++-4.0.1.dll',
        'libmpdec-4.0.1.dll',
        'gmp-10.dll',
        'mpfr-6.dll',
    ];
    if ($runtimeDir === null) {
        warning('未找到 PHP 运行时库目录，跳过 phpx/php8ts 等 DLL 的部署');
        info('请安装 tpc 原生发行包，或设置 TPC_RUNTIME_DIR 指向含 phpx.dll 的目录');
    } else {
        foreach ($runtimeDlls as $dll) {
            $src = $runtimeDir . '/' . $dll;
            if (file_exists($src)) {
                copy($src, $buildDir . '/' . $dll);
                $copied++;
            }
        }
    }

    // Qt 运行库（源码内联方案下 exe 直接依赖这些）
    if ($qtDir) {
        // Qt6Network 必须在这里：桥接里 qt_http_request 用它，链接期有 .lib、
        // 运行期少一个 .dll 就是 0xC0000135，而 Windows 不会告诉你是哪一个。
        foreach (['Qt6Core.dll', 'Qt6Gui.dll', 'Qt6Widgets.dll', 'Qt6Network.dll'] as $dll) {
            $src = $qtDir . '/bin/' . $dll;
            if (file_exists($src)) {
                copy($src, $buildDir . '/' . $dll);
                $copied++;
            }
        }

        // 平台插件：缺了它 Qt 会报
        // "could not find or load the Qt platform plugin windows"
        //
        // **qoffscreen 必须一起带上**：`--selftest` / `--shot` / `--difftest`
        // 这些无头验收开关在 CI 里要配 QT_QPA_PLATFORM=offscreen 跑，
        // 而 Qt 找不到 offscreen 插件时会直接 abort（0xC0000409），
        // 表现为「一开无头验收就崩」—— 与 macOS bundle 缺
        // libqoffscreen.dylib 是同一个坑（F16→F17），只是 Windows 侧没实测过。
        $pluginDir = $buildDir . '/platforms';
        foreach (['qwindows.dll', 'qoffscreen.dll', 'qminimal.dll'] as $plugin) {
            $src = $qtDir . '/plugins/platforms/' . $plugin;
            if (!file_exists($src)) {
                continue;
            }
            if (!is_dir($pluginDir)) {
                mkdir($pluginDir, 0755, true);
            }
            copy($src, $pluginDir . '/' . $plugin);
            $copied++;
        }

        // 样式插件（Fusion 之外的原生样式）
        $stylesDir = $buildDir . '/styles';
        $stylePlugin = $qtDir . '/plugins/styles/qmodernwindowsstyle.dll';
        if (file_exists($stylePlugin)) {
            if (!is_dir($stylesDir)) {
                mkdir($stylesDir, 0755, true);
            }
            copy($stylePlugin, $stylesDir . '/qmodernwindowsstyle.dll');
            $copied++;
        }
    }

    // MSVC 运行时
    $vcRedist = findVcRedistDir();
    if ($vcRedist) {
        foreach (['msvcp140.dll', 'vcruntime140.dll', 'vcruntime140_1.dll'] as $dll) {
            $src = $vcRedist . '/' . $dll;
            if (file_exists($src) && !file_exists($buildDir . '/' . $dll)) {
                copy($src, $buildDir . '/' . $dll);
                $copied++;
            }
        }
    }

    // WebView2 加载器：用了 webview 控件就必须带上，否则 exe 起不来
    // （0xC0000135 = 找不到 DLL，且报错只说缺 DLL、不说是哪个）。
    // 注意 WebView2 **运行时**由系统提供（Win10/11 随 Edge 自带），
    // 这里搬的只是那 195KB 的 loader。
    $wv2Loader = $GLOBALS['rootDir'] . '/third_party/webview2/x64/WebView2Loader.dll';
    if (file_exists($wv2Loader) && !file_exists($buildDir . '/WebView2Loader.dll')) {
        copy($wv2Loader, $buildDir . '/WebView2Loader.dll');
        $copied++;
    }

    if ($copied > 0) {
        success("已部署 $copied 个运行时文件到 build/");
    } else {
        warning('未能找到运行时 DLL，exe 可能无法直接运行');
    }

    deployAssets($projectPath);
}

/**
 * 把项目的 assets/ 拷进 build/。
 *
 * `package` 阶段本来就会拷，但开发期直接跑 build/<name>.exe 时不会 ——
 * 于是 'assets/icon.png' 这类相对路径在开发期失效、打包后又能用，
 * 属于「打包才暴露」的坑。这里让两边行为一致。
 */
function deployAssets(string $projectPath): void
{
    $src = rtrim($projectPath, '/\\') . '/assets';
    $dst = rtrim($projectPath, '/\\') . '/build/assets';
    if (!is_dir($src)) {
        return;
    }
    copyDir($src, $dst);
}

