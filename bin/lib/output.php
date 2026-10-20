<?php

declare(strict_types=1);

// 由 bin/qtphp 拆分而来（color() 之后加了 TTY/NO_COLOR 降级）。

/**
 * 当前输出流是否该带 ANSI 颜色。
 *
 * 重定向到文件/管道时，转义序列会变成乱码塞进日志，所以非 TTY 一律降级为纯文本。
 * 不缓存结果：CLI 一次进程只算几次，而测试需要在同一进程里切换环境变量验证两种路径。
 */
function colorEnabled(): bool
{
    // https://no-color.org —— 只要变量存在且非空就视为要求纯文本。
    // 优先于 CLICOLOR_FORCE：用户显式说「别上色」时，工具再强行染色是意外行为。
    $noColor = getenv('NO_COLOR');
    if ($noColor !== false && $noColor !== '') {
        return false;
    }

    // 强制开只用来对抗「非 TTY」这一条，方便本地/CI 断言彩色分支
    $force = getenv('CLICOLOR_FORCE');
    if ($force !== false && $force !== '' && $force !== '0') {
        return true;
    }

    if (defined('STDOUT') && function_exists('stream_isatty')) {
        return @stream_isatty(STDOUT);
    }

    return false;
}

function color(string $text, string $color): string
{
    if (!colorEnabled()) {
        return $text;
    }

    $codes = [
        'red' => "\033[31m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'blue' => "\033[34m",
        'cyan' => "\033[36m",
        'bold' => "\033[1m",
        'reset' => "\033[0m",
    ];
    return ($codes[$color] ?? '') . $text . $codes['reset'];
}

function info(string $msg): void
{
    echo color('[INFO] ', 'cyan') . $msg . PHP_EOL;
}

function success(string $msg): void
{
    echo color('[OK] ', 'green') . $msg . PHP_EOL;
}

function warning(string $msg): void
{
    echo color('[WARN] ', 'yellow') . $msg . PHP_EOL;
}

function error(string $msg): void
{
    echo color('[ERROR] ', 'red') . $msg . PHP_EOL;
}

// ── 命令实现 ──

