<?php

declare(strict_types=1);

// 由 bin/qtphp 拆分而来（函数体逐字未改）。

/**
 * 消费方**项目**根目录 —— 与「包目录」(`$GLOBALS['rootDir']`) 是两回事。
 *
 * 这是一个真实踩过的坑：包的 bin/qtphp 经 composer 链接后会出现在
 * `<project>/vendor/bin/qtphp`，但脚本里 `$rootDir = dirname(__DIR__)`（入口在 `bin/`）拿到的是
 * **包自身**目录（`<project>/vendor/yangweijie/typephp-qt`）。于是 findTpc() 去找
 * `<包>/vendor/bin/tpc.php` —— 消费方项目里那个路径根本不存在，doctor 直接报
 * 「tpc: 未找到」，build / run / package 全部卡在工具链探测上，而且报错完全指不到
 * 真正的原因（用户会以为是没装 tpc）。
 *
 * `vendor/bin/tpc.php` 与 `vendor/bin/phpunit` 属于**项目**，必须从项目根找；
 * 而 `php-src/`、`cpp-src/`、`third_party/` 属于**包**，仍走 `$rootDir`。
 * 解析顺序：
 *   1. composer 的 bin 代理会设 `$GLOBALS['_composer_bin_dir'] = <project>/vendor/bin`
 *   2. 从 cwd 向上找同时有 `composer.json` 与 `vendor/` 的目录
 *   3. 都找不到就退回包目录（在包仓库里直接开发时的情形）
 */
function projectRoot(): string
{
    $binDir = $GLOBALS['_composer_bin_dir'] ?? '';
    if (is_string($binDir) && $binDir !== '') {
        $candidate = dirname(dirname($binDir));   // <project>/vendor/bin → <project>
        if ($candidate !== '' && $candidate !== '.' && is_dir($candidate . '/vendor')) {
            return $candidate;
        }
    }

    $dir = getcwd();
    for ($depth = 0; $depth < 8; $depth++) {
        if (!is_string($dir) || $dir === '' || $dir === '.' || $dir === DIRECTORY_SEPARATOR) {
            break;
        }
        if (is_dir($dir . '/vendor') && is_file($dir . '/composer.json')) {
            return $dir;
        }
        $parent = dirname($dir);
        if ($parent === $dir) {
            break;
        }
        $dir = $parent;
    }

    return $GLOBALS['rootDir'];
}

/**
 * 定位 tpc 编译器。
 *
 * 优先级沿用 composer 驱动优先（macOS/Linux 的完整链路都建立在 tpc 自建私有运行时之上，
 * 见 F11.3），但加两个 Windows 专属的逃生口 —— 那里 composer 驱动可能**装得出却跑不通**：
 *
 * composer 只发 `vendor/bin/tpc.php` 这个驱动，它依赖同仓库的 `vendor/swoole/phpx`
 * **源码树**，而该源码树不含编译产物（`build/phpx.dll`、`lib/phpx.lib` 要 phpx 工具链自建）。
 * 原生 tpc.exe 发行包则是自包含的，运行时就在它旁边。两路线混用会直接死在
 * `The PHPX runtime library was not found at: ...\phpx\build\phpx.dll`。
 *
 * 所以：选中的候选若**缺运行时**而候选表里另有**带运行时**的，就改用后者。
 * `TPC` / `TPC_DIR` 可显式指定，用于 CI 与多版本共存。
 */
function findTpc(): ?string
{
    // 显式指定的一律照用，不做运行时体检（用户知道自己在做什么）
    $envTpc = getenv('TPC');
    if ($envTpc && is_file($envTpc)) {
        return $envTpc;
    }

    // composer 装的包只带 bin/tpc.php（bin/tpc 是各平台的预编译二进制，不会随包发布）。
    // vendor/ 属于**项目**，所以先在项目根下找；再退回包自身（在包仓库里开发的情形）。
    $project = projectRoot();
    $candidates = [
        $project . '/vendor/bin/tpc',
        $project . '/vendor/bin/tpc.php',
        $project . '/vendor/swoole/typephp/bin/tpc',
        $project . '/vendor/swoole/typephp/bin/tpc.php',
    ];
    if ($project !== $GLOBALS['rootDir']) {
        $candidates[] = $GLOBALS['rootDir'] . '/vendor/bin/tpc.php';
        $candidates[] = $GLOBALS['rootDir'] . '/vendor/swoole/typephp/bin/tpc.php';
    }

    $envDir = getenv('TPC_DIR');
    if ($envDir) {
        $candidates[] = rtrim($envDir, '/\\') . '/tpc' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
    }

    // PATH 与发行包惯例位置排在 composer 之后，仅在需要时兜底
    $exe = PHP_OS_FAMILY === 'Windows' ? 'tpc.exe' : 'tpc';
    foreach (array_merge(nativeTpcSearchDirs(), whichAll($exe)) as $path) {
        $candidates[] = $path;
    }

    $existing = array_values(array_filter($candidates, 'is_file'));
    if (!$existing) {
        return null;
    }

    // 优先选带运行时的；都没有则退回原来的「第一个存在的」
    foreach ($existing as $path) {
        if (tpcHasRuntime($path)) {
            return $path;
        }
    }
    return $existing[0];
}

/** 原生 tpc 发行包的候选安装目录（未设 TPC_DIR 时按惯例猜测）。 */
function nativeTpcSearchDirs(): array
{
    $dirs = [];
    $envDir = getenv('TPC_DIR');
    if ($envDir) {
        $dirs[] = $envDir;
    }
    $home = getenv('HOME') ?: getenv('USERPROFILE');
    if ($home) {
        foreach (glob(rtrim($home, '/\\') . '/tpc*', GLOB_ONLYDIR) ?: [] as $dir) {
            $dirs[] = $dir;
        }
        $dirs[] = rtrim($home, '/\\') . '/.typephp/tpc';
    }
    foreach (['/opt/tpc', '/usr/local/tpc', 'C:/tpc', 'D:/tpc'] as $dir) {
        $dirs[] = $dir;
    }
    $exe = PHP_OS_FAMILY === 'Windows' ? '/tpc.exe' : '/tpc';
    return array_map(fn(string $d): string => rtrim($d, '/\\') . $exe, $dirs);
}

/**
 * 这个 tpc 旁边有没有它需要的运行时？
 *
 * 判据与 `findRuntimeLibDir()` 的标记集保持一致：原生发行包的 SDK/phpx 目录、
 * 或 php 运行时 DLL/静态库。composer 驱动（`.php`）永远返回 false ——
 * 它的运行时在 `~/.typephp/php-builder`，可能**尚未构建**，正是本次故障的来源。
 */
function tpcHasRuntime(string $tpcPath): bool
{
    if (strtolower(pathinfo($tpcPath, PATHINFO_EXTENSION)) === 'php') {
        return false;
    }
    $dir = dirname($tpcPath);
    if (is_dir($dir . '/phpx') || is_dir($dir . '/SDK')) {
        return true;
    }
    foreach (['phpx.dll', 'php8ts.dll', 'libphp.a', 'libphp.dylib', 'libphp.so'] as $marker) {
        if (is_file($dir . '/' . $marker)) {
            return true;
        }
    }
    return false;
}

/** PATH 上所有同名可执行文件（Windows 的 `where` 可返回多个）。 */
function whichAll(string $name): array
{
    $probe = PHP_OS_FAMILY === 'Windows'
        ? 'where ' . escapeshellarg($name) . ' 2>nul'
        : 'command -v ' . escapeshellarg($name) . ' 2>/dev/null';
    $output = shell_exec($probe);
    if (!$output || !trim($output)) {
        return [];
    }
    $lines = preg_split('/\r?\n/', trim($output)) ?: [];
    return array_values(array_filter(array_map('trim', $lines), 'is_file'));
}

/** tpc 的 shell 命令词：PHP 脚本入口必须交给解释器，不能直接执行。 */
function tpcCommand(string $tpc): string
{
    if (strtolower(pathinfo($tpc, PATHINFO_EXTENSION)) === 'php') {
        return escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($tpc);
    }
    return escapeshellarg($tpc);
}

/**
 * composer 装的 tpc.php（能看见 vendor 里的 swoole/php-nano）。
 *
 * 原生 tpc.exe 的 TYPEPHP_ROOT_PATH 是编译时写死的（CI 上常是 D:\a\typephp\typephp），
 * InstalledVersions 找不到本项目 vendor 里的 php-nano。`--nano` 必须走这条。
 */
function findComposerTpc(): ?string
{
    foreach ([
        $GLOBALS['rootDir'] . '/vendor/bin/tpc.php',
        $GLOBALS['rootDir'] . '/vendor/swoole/typephp/bin/tpc.php',
    ] as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

/**
 * Debian 系的多架构三元组（x86_64-linux-gnu / aarch64-linux-gnu），非这种布局返回 null。
 * 判定依据是 Qt6 的 CMake 配置目录存在，不是只猜 uname —— 发行版把 Qt 装在哪都能否决。
 */
function linuxMultiarchTriple(): ?string
{
    $triple = ['x86_64' => 'x86_64-linux-gnu', 'aarch64' => 'aarch64-linux-gnu',
               'arm64' => 'aarch64-linux-gnu'][php_uname('m')] ?? null;
    if ($triple === null || !is_dir('/usr/lib/' . $triple . '/cmake/Qt6')) {
        return null;
    }
    return $triple;
}

/**
 * Linux 编译/打包的硬前置缺了哪些 apt 包（返回值直接能拼成安装命令）。
 *
 * mac 上这些都是 brew 顺带装好的，Linux 得显式列：php-src 要 bison/re2c/autoconf 和
 * libxml2/sqlite3/onig/zlib 头，phpx 要 gmp/mpfr 头（F21），`package` 要 patchelf（F22）。
 * 头文件按 /usr/include 与 /usr/include/<三元组> 两处找 —— 实测 gmp.h 只在多架构目录下，
 * 而 onigmo.h 在 Debian 上根本不存在（libonig-dev 给的是 oniguruma.h）。
 */
function linuxMissingPackages(): array
{
    $incDirs = ['/usr/include'];
    $triple = linuxMultiarchTriple();
    if ($triple !== null) {
        $incDirs[] = '/usr/include/' . $triple;
    }

    $need = [
        'bison' => ['cmd' => 'bison'],
        're2c' => ['cmd' => 're2c'],
        'autoconf' => ['cmd' => 'autoconf'],
        'pkg-config' => ['cmd' => 'pkg-config'],
        'xz-utils' => ['cmd' => 'xz'],
        'patchelf' => ['cmd' => 'patchelf'],
        'libgmp-dev' => ['headers' => ['gmp.h', 'gmpxx.h']],
        'libmpfr-dev' => ['headers' => ['mpfr.h']],
        'libonig-dev' => ['headers' => ['oniguruma.h']],
        'libxml2-dev' => ['headers' => ['libxml2/libxml/parser.h']],
        'libsqlite3-dev' => ['headers' => ['sqlite3.h']],
        'zlib1g-dev' => ['headers' => ['zlib.h']],
    ];

    $missing = [];
    foreach ($need as $pkg => $probe) {
        if (isset($probe['cmd']) && trim((string) shell_exec('command -v ' . $probe['cmd'] . ' 2>/dev/null')) === '') {
            $missing[] = $pkg;
            continue;
        }
        foreach ($probe['headers'] ?? [] as $header) {
            $hit = false;
            foreach ($incDirs as $dir) {
                if (file_exists($dir . '/' . $header)) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                $missing[] = $pkg;
                break;
            }
        }
    }
    return $missing;
}

function findQt(): ?string
{
    // 显式指定的永远优先。否则在「机器上恰好有一份候选路径里的 Qt」时，
    // 环境变量会被无视 —— 那正是最需要覆盖它的场景（想用 6.10、或想切到另一份 Qt）。
    //
    // QT_ROOT 排在 QT_DIR 前面：CI 里 QT_ROOT 是 GitHub Actions 生态的通行名字
    // （jurplel/install-qt-action 等默认导出它），而 QT_DIR 是本项目历史名字。
    // 两个都认，避免老脚本一升级就静默回落到自动探测。
    foreach (['QT_ROOT', 'QT_DIR'] as $name) {
        $env = getenv($name);
        if (!$env) continue;
        if (is_dir($env)) return $env;
        warning("$name 指向的目录不存在: $env（忽略，继续自动探测）");
    }

    // Debian/Ubuntu 的 qt6-base-dev 是「前缀 /usr + 多架构子目录」布局，
    // 与 brew/官方安装器的 include+lib+bin 同构，所以这里返回前缀 /usr。
    if (PHP_OS_FAMILY === 'Linux') {
        $triple = linuxMultiarchTriple();
        if ($triple !== null && is_dir('/usr/include/' . $triple . '/qt6/QtCore')) {
            return '/usr';
        }
    }
    $candidates = [
        'D:/tools/Qt/6.9.3/msvc2022_64',
        'C:/Qt/6.9.3/msvc2022_64',
        'D:/Qt/6.9.3/msvc2022_64',
        // Homebrew keg-only 前缀（qtbase 提供 QtCore/QtGui/QtWidgets）
        '/opt/homebrew/opt/qtbase',
        '/usr/local/opt/qtbase',
        '/opt/homebrew/opt/qt',
        '/usr/local/opt/qt',
    ];
    foreach ($candidates as $path) {
        if (is_dir($path)) return $path;
    }
    return null;
}

/**
 * 选择编译入口：平台文件 `project.macos.yml` / `project.windows.yml` / `project.linux.yml` 优先，
 * 它 `include` 公共的 project.yml 再覆盖平台段（Qt 位置、链接法、编译开关）。
 * 后缀用上游 typephp-compiler 示例的约定，不是 PHP_OS_FAMILY 的原值（Darwin ≠ macos）。
 * 没有平台文件时回落到 project.yml —— Windows 的行为与 build.bat 都不变。
 */
function resolveProjectYaml(string $path): string
{
    $suffix = ['Darwin' => 'macos', 'Windows' => 'windows', 'Linux' => 'linux'][PHP_OS_FAMILY] ?? 'linux';
    $platform = rtrim($path, '/\\') . '/project.' . $suffix . '.yml';
    return is_file($platform) ? $platform : rtrim($path, '/\\') . '/project.yml';
}

function findVcvars(): ?string
{
    // 先用 vswhere 问安装器：硬编码路径只能覆盖「本地装了 BuildTools」这一种情况，
    // CI（windows-latest 是 Enterprise）和只用 Community 的机器都会漏，
    // 后果是 tpc 在没有 INCLUDE/LIB 的裸 shell 里编 C++ 直接失败。
    $vswhere = [];
    $pf86 = getenv('ProgramFiles(x86)');
    if (is_string($pf86) && $pf86 !== '') {
        $vswhere[] = rtrim(str_replace('\\', '/', $pf86), '/') . '/Microsoft Visual Studio/Installer/vswhere.exe';
    }
    $vswhere[] = 'C:/Program Files (x86)/Microsoft Visual Studio/Installer/vswhere.exe';

    foreach ($vswhere as $exe) {
        if (!is_file($exe)) continue;
        $output = [];
        $exitCode = 1;
        // -products * 才能看到 BuildTools（默认只返回 IDE 产品）；-requires 过滤掉只装了编译前端的情况
        exec(
            '"' . $exe . '" -latest -products *'
            . ' -requires Microsoft.VisualStudio.Component.VC.Tools.x86.x64'
            . ' -property installationPath 2>nul',
            $output,
            $exitCode
        );
        if ($exitCode !== 0) continue;
        foreach ($output as $line) {
            $line = trim($line);
            if ($line === '') continue;
            $path = rtrim(str_replace('\\', '/', $line), '/') . '/VC/Auxiliary/Build/vcvars64.bat';
            if (is_file($path)) return $path;
        }
    }

    // vswhere 不可用时的兜底：按常见安装位置逐个试
    $candidates = [];
    foreach (['2022', '2019'] as $version) {
        foreach (['Enterprise', 'Professional', 'Community', 'BuildTools'] as $edition) {
            $candidates[] = "C:/Program Files/Microsoft Visual Studio/$version/$edition/VC/Auxiliary/Build/vcvars64.bat";
            $candidates[] = "C:/Program Files (x86)/Microsoft Visual Studio/$version/$edition/VC/Auxiliary/Build/vcvars64.bat";
            $candidates[] = "D:/Program Files (x86)/Microsoft Visual Studio/$version/$edition/VC/Auxiliary/Build/vcvars64.bat";
        }
    }
    foreach ($candidates as $path) {
        if (is_file($path)) return $path;
    }
    return null;
}

