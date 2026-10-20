<?php

declare(strict_types=1);

// 由 bin/qtphp 拆分而来（函数体逐字未改）。

function cmdDoctor(): int
{
    echo color('=== TypePHP\\Qt 工具链检查 ===', 'bold') . PHP_EOL . PHP_EOL;

    $allOk = true;

    // PHP
    $phpVersion = PHP_VERSION;
    if (version_compare($phpVersion, '8.1.0', '>=')) {
        success("PHP $phpVersion");
    } else {
        error("PHP $phpVersion (需要 >= 8.1)");
        $allOk = false;
    }

    // tpc
    $tpcPath = findTpc();
    if ($tpcPath) {
        success("tpc: $tpcPath");
    } else {
        error('tpc: 未找到（需要 swoole/typephp 或手动安装）');
        echo '     项目根: ' . projectRoot() . PHP_EOL;
        echo '     已查找: ' . projectRoot() . '/vendor/bin/tpc.php' . PHP_EOL;
        echo '     提示: composer require --dev swoole/typephp，或用 TPC=/path/to/tpc 指定' . PHP_EOL;
        $allOk = false;
    }

    // AOT 链接用的 PHP 运行时库（tpc 原生发行包附带，或由 php-builder 自建）
    $runtimeDir = findRuntimeLibDir();
    if ($runtimeDir) {
        success("PHP 运行时库: $runtimeDir");
    } else {
        warning('PHP 运行时库: 未找到（首次 build 由 tpc 的 php-builder 自动编译，耗时较长）');
    }

    // Qt
    $qtDir = findQt();
    if ($qtDir) {
        success("Qt: $qtDir");
    } else {
        warning('Qt: 未找到（仅影响真实编译/运行，不影响测试）');
    }

    // C++ 工具链
    if (PHP_OS_FAMILY === 'Windows') {
        $vcvars = findVcvars();
        if ($vcvars) {
            success("MSVC: $vcvars");
        } else {
            warning('MSVC: 未找到（仅影响真实编译）');
        }
    } else {
        // macOS 上是 clang++，Debian/Ubuntu 的 Qt 包配套的是 g++，两个都认。
        $cxx = '';
        foreach (['clang++', 'g++'] as $probe) {
            $found = trim((string) shell_exec('command -v ' . $probe . ' 2>/dev/null'));
            if ($found !== '') {
                $cxx = $found;
                break;
            }
        }
        if ($cxx !== '') {
            success("C++: $cxx");
        } else {
            warning('C++: 未找到 clang++/g++（macOS 需先 xcode-select --install，Linux 需 apt install g++）');
        }
    }

    // Linux 的前置不止 Qt：tpc 的 php-builder 要现编 PHP 源码，缺一个包就是一次失败编译（F21/F22）。
    if (PHP_OS_FAMILY === 'Linux') {
        $missing = linuxMissingPackages();
        if (!$missing) {
            success('Linux 构建前置: 齐全');
        } else {
            warning('Linux 构建前置缺失: ' . implode('、', $missing));
            info('  apt install -y ' . implode(' ', $missing));
        }
    }

    // PHPUnit
    // phpunit 是**项目**的 dev 依赖，先看项目根；包仓库里开发时再退回包根。
    $phpunit = projectRoot() . '/vendor/bin/phpunit';
    if (!is_file($phpunit)) $phpunit = $GLOBALS['rootDir'] . '/vendor/bin/phpunit';
    if (file_exists($phpunit)) {
        success("PHPUnit: $phpunit");
    } else {
        warning('PHPUnit: 未找到（运行 composer install 安装）');
    }

    echo PHP_EOL;
    if ($allOk) {
        success('核心工具链就绪！');
    } else {
        error('部分工具链缺失，请安装后重试。');
    }

    return $allOk ? 0 : 1;
}

