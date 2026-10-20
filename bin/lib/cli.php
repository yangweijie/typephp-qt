<?php

declare(strict_types=1);

// 由 bin/qtphp 拆分而来（函数体逐字未改）。

function main(array $argv): int
{
    $GLOBALS['rootDir'] = dirname(__DIR__, 2);

    if (count($argv) < 2) {
        echo color('TypePHP\\Qt 工具', 'bold') . PHP_EOL . PHP_EOL;
        echo '用法:' . PHP_EOL;
        echo '  qtphp doctor            检查工具链' . PHP_EOL;
        echo '  qtphp new <name>        创建新项目' . PHP_EOL;
        echo '  qtphp build <path> [--nano]  编译项目（--nano：源码内联 PHP，不链运行时，仅 macOS 已验证）' . PHP_EOL;
        echo '  qtphp run <path> [app参数…]  运行项目，其后的参数原样透传给应用' . PHP_EOL;
        echo '  qtphp package <path>    打包项目' . PHP_EOL;
        echo '  qtphp test              运行测试' . PHP_EOL;
        echo '  qtphp lint              校验桥接契约' . PHP_EOL;
        return 0;
    }

    $command = $argv[1];

    switch ($command) {
        case 'doctor':
            return cmdDoctor();
        case 'new':
            if (!isset($argv[2])) {
                error('请指定项目名称');
                return 1;
            }
            return cmdNew($argv[2]);
        case 'build':
            if (!isset($argv[2])) {
                error('请指定项目路径');
                return 1;
            }
            // 第 3 个参数起是编译选项（目前只有 --nano），未知项在 cmdBuild 里报错而不是静默忽略
            return cmdBuild($argv[2], array_slice($argv, 3));
        case 'run':
            if (!isset($argv[2])) {
                error('请指定项目路径');
                return 1;
            }
            // 第 3 个参数起全部透传给应用（--selftest / --shot 等）
            return cmdRun($argv[2], array_slice($argv, 3));
        case 'package':
            if (!isset($argv[2])) {
                error('请指定项目路径');
                return 1;
            }
            return cmdPackage($argv[2]);
        case 'test':
            return cmdTest();
        case 'lint':
            return cmdLint();
        default:
            error("未知命令: $command");
            return 1;
    }
}
