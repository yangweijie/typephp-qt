<?php

declare(strict_types=1);

// 由 bin/qtphp 拆分而来（函数体逐字未改）。

/** 定位 VC 运行时 DLL 目录（msvcp140.dll 等）。 */
function findVcRedistDir(): ?string
{
    $roots = [
        'C:/Windows/System32',
        'C:/Program Files/Microsoft Visual Studio/2022/BuildTools/VC/Redist/MSVC',
        'C:/Program Files (x86)/Microsoft Visual Studio/2022/BuildTools/VC/Redist/MSVC',
    ];
    foreach ($roots as $root) {
        if (!is_dir($root)) continue;
        if (file_exists($root . '/msvcp140.dll')) {
            return $root;
        }
        // Redist 目录下有多层版本号，找最新一个
        $dirs = glob($root . '/*/x64/Microsoft.VC143.CRT', GLOB_ONLYDIR) ?: [];
        rsort($dirs);
        foreach ($dirs as $dir) {
            if (file_exists($dir . '/msvcp140.dll')) {
                return $dir;
            }
        }
    }
    return null;
}

/**
 * 规范化项目目录。
 *
 * `qtphp run .` / `qtphp package .` 是 README 和生成的 package.bat 里的写法，而 PHP 的
 * `basename('.')` 返回 `.`，产物会被算成 `./build/.` —— 那是个目录，`file_exists()` 还为真，
 * 于是错误被推迟到更看不懂的地方。路径不存在时原样返回，让下游报「未找到」。
 */
/**
 * 从「公共段 + 平台段」的入口 yml 里取一个顶层标量字段（平台段优先）。
 *
 * 只认**顶格**的 `key:` —— 缩进的一律跳过，免得命中 `bridge:` 之类的嵌套同名键。
 * 平台段（project.macos.yml）通常只写 `include: [project.yml]` 再覆盖平台相关字段，
 * `name:` / `output:` 往往只在公共段里，所以两个文件都要看。
 */
function projectYamlField(string $dir, string $key): ?string
{
    $dir = rtrim($dir, '/\\');
    $value = null;
    foreach ([$dir . '/project.yml', resolveProjectYaml($dir)] as $yml) {
        if (!is_file($yml)) {
            continue;
        }
        foreach (preg_split('/\r?\n/', (string) file_get_contents($yml)) ?: [] as $line) {
            if (preg_match('/^' . preg_quote($key, '/') . '\s*:\s*(\S+)/', $line, $m)) {
                $value = trim($m[1], "\"'");
            }
        }
    }
    return $value;
}

/** 入口 yml 里的 `name:`；没写就回落到目录名。 */
function projectName(string $dir): string
{
    $name = projectYamlField($dir, 'name');
    return $name !== null && $name !== '' ? $name : basename(rtrim($dir, '/\\'));
}

/**
 * 编译产物路径 —— 由入口 yml 的 `output:` / `name:` 决定，**不是**目录名。
 *
 * 早先 cmdRun / cmdPackage 都是 `basename($dir)` 拼 `build/<目录名>`，而 `qtphp build`
 * 是让 tpc 按 yml 里的 `name:` / `output:` 决定输出位置。两者只要不一致，
 * `build` 明明成功、紧接着 `run` 就报「未找到可执行文件」—— 例如项目目录叫
 * `typephp-smartgit` 而 yml 里 `name: typephp_smartgit`（下划线）。
 * 这个报错完全指不到真正的原因（用户会以为没编译）。
 */
function builtBinary(string $dir): ?string
{
    $dir = rtrim($dir, '/\\');
    $suffix = PHP_OS_FAMILY === 'Windows' ? '.exe' : '';

    $candidates = [];
    $output = projectYamlField($dir, 'output');
    if ($output !== null && $output !== '') {
        $candidates[] = $dir . '/' . rtrim($output, '/') . $suffix;
    }
    $candidates[] = $dir . '/build/' . projectName($dir) . $suffix;
    $candidates[] = $dir . '/build/' . basename($dir) . $suffix;   // 目录名兜底（老行为）

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }
    return null;
}

function projectDir(string $path): string
{
    $dir = rtrim($path, '/\\');
    $real = realpath($dir);
    return $real !== false ? $real : $dir;
}

