<?php

declare(strict_types=1);

// 由 bin/qtphp 拆分而来（函数体逐字未改）。

function cmdRun(string $path, array $args = []): int
{
    $dir = projectDir($path);
    $exe = builtBinary($dir);
    if ($exe === null) {
        error('未找到可执行文件（已按入口 yml 的 output: / name: 查找）');
        info("请先运行: qtphp build $path");
        return 1;
    }

    // 缺依赖时给出可操作的提示，而不是让系统弹一个看不懂的错误框
    $missing = PHP_OS_FAMILY === 'Windows'
        ? checkRuntimeDlls(dirname($exe))
        : checkSharedLibraryDeps($exe);
    if ($missing) {
        warning('以下运行时依赖缺失，可能无法启动：' . implode(', ', $missing));
        if (PHP_OS_FAMILY === 'Windows') {
            info("运行 'qtphp build $path' 可自动部署；或执行 'qtphp package $path' 生成自包含目录。");
        } elseif (PHP_OS_FAMILY === 'Linux') {
            info('Linux 的 Qt 依赖来自系统包：apt install qt6-base-dev（headless 验收还需 libqt6widgets6 的 platforms 插件）。');
        } else {
            info('macOS 的依赖来自 brew（qtbase/libiconv/gmp/mpfr）与 ~/.typephp 私有运行时，重新 build 会重建链接。');
        }
    }

    // 其余参数原样透传给应用（--selftest / --shot 这些无头验收开关靠这一步才可用）
    $cmd = escapeshellarg($exe);
    foreach ($args as $arg) {
        $cmd .= ' ' . escapeshellarg($arg);
    }
    info('运行: ' . $exe . ($args ? ' ' . implode(' ', $args) : ''));
    passthru($cmd . ' 2>&1', $exitCode);
    return $exitCode;
}

/**
 * 非 Windows：问动态链接器要依赖清单，逐条确认能不能加载。
 *
 * 比"照着名单复制 DLL"可靠 —— brew 前缀、keg-only 路径、私有运行时的绝对路径
 * 都已经写在二进制的加载命令里了。@rpath 形式交给 dyld 解析，静态检查无意义。
 *
 * 系统库必须跳过：macOS 11 起 /usr/lib 下的 dylib 只存在于 dyld shared cache，
 * 磁盘上没有文件但加载正常，按 file_exists 判会全部误报。
 */
function checkSharedLibraryDeps(string $exe): array
{
    if (PHP_OS_FAMILY === 'Linux') {
        return checkLinuxLibraryDeps($exe);
    }

    $output = shell_exec('otool -L ' . escapeshellarg($exe) . ' 2>/dev/null');
    if (!$output) {
        return [];
    }

    $missing = [];
    foreach (explode("\n", $output) as $line) {
        if (!preg_match('/^\s*(\/\S+\.dylib)\s+\(compatibility/', $line, $m)) {
            continue;
        }
        $path = $m[1];
        if (str_starts_with($path, '/usr/lib/') || str_starts_with($path, '/System/Library/')) {
            continue;
        }
        if (!file_exists($path)) {
            $missing[] = $path;
        }
    }
    return $missing;
}

/**
 * Linux：`ldd` 的清单里只有两种真问题 —— `not found`（库里没有这个 .so），
 * 以及指向构建树/私有目录又确实不存在的绝对路径。
 * 系统前缀（/usr/lib、/lib、/usr/local/lib）直接放行：那是发行版该负责的部分。
 */
function checkLinuxLibraryDeps(string $exe): array
{
    $output = shell_exec('ldd ' . escapeshellarg($exe) . ' 2>/dev/null');
    if ($output === null || $output === '') {
        return [];
    }

    $missing = [];
    foreach (explode("\n", $output) as $line) {
        if (preg_match('/^\s*(\S+)\s+=>\s+not found/', $line, $m)) {
            $missing[] = $m[1] . ' (not found)';
            continue;
        }
        if (preg_match('/^\s*\S+\s+=>\s+(\/\S+)/', $line, $m)) {
            $path = $m[1];
            foreach (['/usr/lib', '/usr/local/lib', '/lib'] as $systemPrefix) {
                if (str_starts_with($path, $systemPrefix . '/')) {
                    continue 2;
                }
            }
            if (!file_exists($path)) {
                $missing[] = $path;
            }
        }
    }
    return array_values(array_unique($missing));
}

/** 检查 exe 同目录下缺失的运行时 DLL。 */
function checkRuntimeDlls(string $dir): array
{
    $required = [
        'phpx.dll',
        'php8ts.dll',
        'libmpdec++-4.0.1.dll',
        'Qt6Core.dll',
        'Qt6Gui.dll',
        'Qt6Widgets.dll',
        'Qt6Network.dll',
        'platforms/qwindows.dll',
        // webview 控件需要；缺了 exe 直接 0xC0000135（找不到 DLL），
        // 而系统报错不会说缺的是哪一个 —— 这里点名。
        'WebView2Loader.dll',
    ];
    $missing = [];
    foreach ($required as $dll) {
        if (!file_exists($dir . '/' . $dll)) {
            $missing[] = $dll;
        }
    }
    return $missing;
}

