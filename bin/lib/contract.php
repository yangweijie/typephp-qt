<?php

declare(strict_types=1);

// 由 bin/qtphp 拆分而来（函数体逐字未改）。

function cmdTest(): int
{
    // phpunit 是**项目**的 dev 依赖，先看项目根；包仓库里开发时再退回包根。
    $phpunit = projectRoot() . '/vendor/bin/phpunit';
    if (!is_file($phpunit)) $phpunit = $GLOBALS['rootDir'] . '/vendor/bin/phpunit';
    if (!file_exists($phpunit)) {
        error('未找到 PHPUnit，请运行: composer install');
        return 1;
    }

    info('运行测试...');
    // phpunit.xml 里已配置 bootstrap，无需再传
    passthru('"' . $phpunit . '" --colors=always 2>&1', $exitCode);
    return $exitCode;
}

function cmdLint(): int
{
    echo color('=== 桥接契约校验 ===', 'bold') . PHP_EOL . PHP_EOL;

    $stubFile = $GLOBALS['rootDir'] . '/php-src/qt.stub.php';
    if (!file_exists($stubFile)) {
        error("未找到 stub 文件: $stubFile");
        return 1;
    }

    $content = file_get_contents($stubFile);

    // 提取 stub 函数
    preg_match_all('/function\s+(qt_\w+)\s*\(([^)]*)\)/', $content, $matches, PREG_SET_ORDER);
    $stubFunctions = [];
    foreach ($matches as $match) {
        $stubFunctions[] = 'php_' . $match[1];
    }

    info('Stub 函数 (' . count($stubFunctions) . ' 个):');
    foreach ($stubFunctions as $fn) {
        echo "  - $fn" . PHP_EOL;
    }

    // 检查 C++ 实现
    $cppFiles = glob($GLOBALS['rootDir'] . '/cpp-src/*.cc');
    $definedFunctions = [];
    foreach ($cppFiles as $file) {
        $cpp = file_get_contents($file);
        preg_match_all('/^(?:Variant|void|Bool|String|Int|Array)\s+(php_\w+)\s*\(/m', $cpp, $cppMatches);
        foreach ($cppMatches[1] as $fn) {
            $definedFunctions[] = $fn;
        }
    }

    echo PHP_EOL;
    info('C++ 实现 (' . count($definedFunctions) . ' 个):');
    foreach ($definedFunctions as $fn) {
        echo "  - $fn" . PHP_EOL;
    }

    // 比对
    echo PHP_EOL;
    $missing = array_diff($stubFunctions, $definedFunctions);
    $extra = array_diff($definedFunctions, $stubFunctions);

    if (empty($missing) && empty($extra)) {
        success('契约一致！');
        return 0;
    }

    if (!empty($missing)) {
        error('C++ 中缺失的实现:');
        foreach ($missing as $fn) {
            echo "  - $fn" . PHP_EOL;
        }
    }

    if (!empty($extra)) {
        warning('C++ 中多余的实现:');
        foreach ($extra as $fn) {
            echo "  - $fn" . PHP_EOL;
        }
    }

    return 1;
}

// ── 主入口 ──

