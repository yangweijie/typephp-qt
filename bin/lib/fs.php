<?php

declare(strict_types=1);

// 由 bin/qtphp 拆分而来（函数体逐字未改）。

/** 递归删目录树（只用于重建自己生成的 bundle）。 */
function deleteTree(string $dir): void
{
    foreach (scandir($dir) ?: [] as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        if (is_dir($path) && !is_link($path)) {
            deleteTree($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

/** 自检打包产物：关键 DLL、平台插件、可执行文件是否齐全。 */
function verifyPackage(string $distDir, string $name): int
{
    echo PHP_EOL;
    info('自检打包产物...');

    $required = [
        $name . '.exe',
        'phpx.dll',
        'php8ts.dll',
        'Qt6Core.dll',
        'Qt6Gui.dll',
        'Qt6Widgets.dll',
        'Qt6Network.dll',
        'platforms/qwindows.dll',
    ];

    $missing = [];
    foreach ($required as $file) {
        if (!file_exists($distDir . '/' . $file)) {
            $missing[] = $file;
        }
    }

    if ($missing) {
        error('缺少关键文件：');
        foreach ($missing as $file) {
            echo "  - $file" . PHP_EOL;
        }
        return 1;
    }

    // 统计产物大小
    $size = dirSize($distDir);
    success(sprintf('打包完成: %s (%.1f MB)', $distDir, $size / 1048576));
    info('关键文件齐全，可脱离开发环境运行。');
    return 0;
}

function copyDir(string $src, string $dst): void
{
    if (!is_dir($dst)) {
        mkdir($dst, 0755, true);
    }
    foreach (scandir($src) ?: [] as $item) {
        if ($item === '.' || $item === '..') continue;
        $s = $src . '/' . $item;
        $d = $dst . '/' . $item;
        if (is_dir($s)) {
            copyDir($s, $d);
        } else {
            copy($s, $d);
        }
    }
}

function dirSize(string $dir): int
{
    $size = 0;
    $iterator = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        // 符号链接必须跳过：SplFileInfo::isFile()/getSize() 会跟随到目标，
        // 于是 macOS framework 里那些 alias 条目（`QtCore` → `Versions/A/QtCore`）
        // 会把同一份文件重复计一遍，.app 体积虚高约 19%。
        if ($file->isLink() || !$file->isFile()) {
            continue;
        }
        $size += $file->getSize();
    }
    return $size;
}

