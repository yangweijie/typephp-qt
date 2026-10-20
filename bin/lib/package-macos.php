<?php

declare(strict_types=1);

// 由 bin/qtphp 拆分而来（函数体逐字未改）。

/** 读 Mach-O 的依赖清单（otool -L 的路径列）；@rpath 等形式原样返回。 */
function machODeps(string $binary): array
{
    $output = shell_exec('otool -L ' . escapeshellarg($binary) . ' 2>/dev/null');
    if (!$output) {
        return [];
    }

    $deps = [];
    foreach (explode("\n", $output) as $line) {
        if (preg_match('/^\s*(\S+)\s+\(compatibility/', $line, $m)) {
            $deps[] = $m[1];
        }
    }
    return $deps;
}

/**
 * 系统库要跳过：macOS 11 起 /usr/lib 下的 dylib 只存在于 dyld shared cache，
 * 磁盘上没有文件却加载正常，按 file_exists 判会全部误报。
 */
function isSystemDepPath(string $path): bool
{
    return str_starts_with($path, '/usr/lib/') || str_starts_with($path, '/System/Library/');
}

/**
 * 列 bundle 里全部 Mach-O（读文件头魔数判；realpath 去重 framework 的符号链接）。
 *
 * 只扫主二进制会漏：实测 QtCore 自己对 brew 的 ICU 就带着绝对引用，而 macdeployqt
 * 单漏了这三条 —— 产物在没装 brew 的机器上 dyld 直接起不来（F39）。
 */
function bundleMachOFiles(string $contents): array
{
    $files = [];
    $seen = [];
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($contents, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $entry) {
        if (!$entry->isFile()) {
            continue;
        }
        $real = realpath($entry->getPathname());
        if ($real === false || isset($seen[$real])) {
            continue;
        }
        $seen[$real] = true;
        $fh = @fopen($real, 'rb');
        if (!$fh) {
            continue;
        }
        $magic = (string) fread($fh, 4);
        fclose($fh);
        if (in_array($magic, ["\xCF\xFA\xED\xFE", "\xCE\xFA\xED\xFE", "\xCA\xFE\xBA\xBE", "\xCA\xFE\xBA\xBF"], true)) {
            $files[] = $real;
        }
    }
    return $files;
}

/** 读 Mach-O 的加载引用：otool -L 去掉自身 install name（对它来说 otool -L 第一条是 ID 而非依赖）。 */
function machOLoadRefs(string $binary): array
{
    $deps = machODeps($binary);

    $idOut = shell_exec('otool -D ' . escapeshellarg($binary) . ' 2>/dev/null');
    if ($idOut) {
        foreach (explode("\n", $idOut) as $line) {
            $line = trim($line);
            if ($line === '' || str_ends_with($line, ':')) {
                continue;
            }
            if (($i = array_search($line, $deps, true)) !== false) {
                unset($deps[$i]);
                $deps = array_values($deps);
            }
            break;
        }
    }

    return $deps;
}

/**
 * 把 offscreen 平台插件拷进 bundle，并返回其路径（找不到就返回空串）。
 *
 * macdeployqt 只按目标平台拷 cocoa，产物因此**无法无头验收**：设了 QT_QPA_PLATFORM=offscreen
 * 又找不到插件时 Qt 直接 abort（rc=134），CI 里没有 GUI 会话就测不了打包产物。
 * 拷进来的 Qt 引用要改写成 @executable_path —— 原样的 @rpath 指向 `@loader_path/../../../../lib`，
 * 在 bundle 里会算到 dist/lib 这种不存在的位置。
 */
function vendorHeadlessPlugin(string $qtDir, string $contents): string
{
    $src = '';
    foreach (['/share/qt/plugins/platforms', '/plugins/platforms', '/lib/qt6/plugins/platforms'] as $rel) {
        if (is_file($qtDir . $rel . '/libqoffscreen.dylib')) {
            $src = $qtDir . $rel . '/libqoffscreen.dylib';
            break;
        }
    }
    if ($src === '') {
        warning('未找到 libqoffscreen.dylib，产物只能在有 GUI 会话的机器上验收');
        return '';
    }

    $dir = $contents . '/PlugIns/platforms';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $dst = $dir . '/libqoffscreen.dylib';
    copy($src, $dst);
    chmod($dst, 0644);

    foreach (['QtCore', 'QtGui', 'QtWidgets'] as $fw) {
        exec('install_name_tool -change ' . escapeshellarg('@rpath/' . $fw . '.framework/Versions/A/' . $fw)
            . ' ' . escapeshellarg('@executable_path/../Frameworks/' . $fw . '.framework/Versions/A/' . $fw)
            . ' ' . escapeshellarg($dst) . ' 2>&1', $out, $rc);
        if ($rc !== 0) {
            warning("改写插件对 $fw 的引用失败：" . implode(' ', $out));
        }
    }

    return $dst;
}

/**
 * Windows 侧对齐 macOS 的 `vendorHeadlessPlugin()`：保证 `platforms/qoffscreen.dll` 在产物里。
 *
 * windeployqt 的默认插件清单没有权威结论，所以这里不依赖它 —— 已部署就跳过，没部署才补。
 * 补拷是安全的：`qwindows.dll` 今天就是这样从 `platforms/` 加载、并解析到 exe 同目录的 Qt6*.dll 的。
 * 本机没有 Windows 环境，这条路径**未实测**。
 */
function vendorWindowsOffscreenPlugin(string $qtDir, string $distDir): void
{
    $dst = $distDir . '/platforms/qoffscreen.dll';
    if (file_exists($dst)) {
        return;
    }
    $src = $qtDir . '/plugins/platforms/qoffscreen.dll';
    if (!is_file($src)) {
        warning("未找到 $src，产物只能在有桌面会话的机器上验收");
        return;
    }
    if (!is_dir($distDir . '/platforms')) {
        mkdir($distDir . '/platforms', 0755, true);
    }
    copy($src, $dst);
    info('已补无头验收插件: qoffscreen.dll');
}

/**
 * 扫 bundle 内全部 Mach-O，把仍指向 brew / 私有运行时的绝对路径依赖复制进 Frameworks 并改写引用。
 *
 * macdeployqt 会处理好主二进制的引用，但 framework 内部会漏：实测 QtCore 指着 brew ICU 的
 * 三条绝对引用原样留下，产物在没装 brew 的机器上 dyld 直接起不来（F39）。这里不赌它漏不漏 ——
 * 队列从 Contents 下**全部** Mach-O 出发。目标已在 Frameworks 内的直接改写引用；
 * 不在的补拷（并改写其 install name）再改写引用。
 */
function vendorBundleDeps(string $contents, string $frameworks): array
{
    $vendored = [];
    $queue = bundleMachOFiles($contents);
    $done = [];

    while ($queue) {
        $target = array_shift($queue);
        if (isset($done[$target])) {
            continue;
        }
        $done[$target] = true;

        foreach (machOLoadRefs($target) as $dep) {
            if (!str_starts_with($dep, '/') || isSystemDepPath($dep)) {
                continue;
            }
            $base = basename($dep);
            $dst = $frameworks . '/' . $base;
            $newName = '@executable_path/../Frameworks/' . $base;

            if (!file_exists($dst)) {
                if (!file_exists($dep)) {
                    warning("依赖不在本机也不在 bundle 内，无法自包含：$dep");
                    continue;
                }
                copy($dep, $dst);
                chmod($dst, 0644);
                exec('install_name_tool -id ' . escapeshellarg($newName) . ' ' . escapeshellarg($dst) . ' 2>&1', $out, $rc);
                if ($rc !== 0) {
                    warning("改写 $base 的 install name 失败：" . implode(' ', $out));
                    continue;
                }
                $queue[] = $dst;
                $vendored[] = $base;
            }

            exec('install_name_tool -change ' . escapeshellarg($dep) . ' ' . escapeshellarg($newName)
                . ' ' . escapeshellarg($target) . ' 2>&1', $out2, $rc2);
            if ($rc2 !== 0) {
                warning('改写 ' . basename($target) . " 对 $base 的引用失败：" . implode(' ', $out2));
            }
        }
    }

    return array_values(array_unique($vendored));
}

/** 自检 .app：全 bundle 依赖是否自包含、plist 是否合法、签名是否可验。 */
function verifyAppBundle(string $bundle, string $appBinary): int
{
    echo PHP_EOL;
    info('自检打包产物...');
    $ok = true;

    // 只扫主二进制会漏 framework 内部的引用（F39：QtCore → brew ICU 的绝对引用就是这样漏过的）
    $external = [];
    foreach (bundleMachOFiles($bundle . '/Contents') as $file) {
        foreach (machOLoadRefs($file) as $dep) {
            if (str_starts_with($dep, '/') && !isSystemDepPath($dep)) {
                $external[] = substr($file, strlen($bundle) + 1) . ' → ' . $dep;
            }
        }
    }
    if ($external) {
        error('仍指向 bundle 之外：');
        foreach ($external as $dep) {
            echo "  - $dep" . PHP_EOL;
        }
        $ok = false;
    }

    exec('plutil -lint ' . escapeshellarg($bundle . '/Contents/Info.plist') . ' 2>&1', $lint, $lintRc);
    if ($lintRc !== 0) {
        error('Info.plist 不合法：' . implode(' ', $lint));
        $ok = false;
    }

    exec('codesign --verify --deep --strict ' . escapeshellarg($bundle) . ' 2>&1', $verify, $verifyRc);
    if ($verifyRc !== 0) {
        warning('codesign 校验未通过：' . implode(' ', $verify));
    }

    // 在无 GUI 会话下真跑一遍产物，并检查它的告警。
    //
    // 为什么单独查告警：`--shot` 出图的 sha256 只覆盖**窗口像素**，托盘不在 `grab()` 范围内，
    // 于是「打包后 assets 路径解析不到、托盘图标静默丢失」这类问题出图哈希照旧相同，
    // 只能靠人眼扫日志。stderr 才是这类缺陷的唯一信号。
    //
    // QT_MESSAGE_PATTERN 给每条 Qt 日志加上 `type|category|` 前缀，用来把真正要拦的
    // qWarning 与 Qt 平台插件自己那些无害告知区分开（不区分的话会有害地误报）。
    info('在无 GUI 会话下真跑产物（offscreen --selftest），并检查其告警...');
    exec(sprintf(
        'QT_QPA_PLATFORM=offscreen QT_MESSAGE_PATTERN=%s %s --selftest 2>&1',
        escapeshellarg('%{type}|%{category}|%{message}'),
        escapeshellarg($appBinary)
    ), $runOut, $runRc);

    if ($runRc !== 0 || !in_array('selftest passed', $runOut, true)) {
        // rc 是主判据（示例/脚手架的 --selftest 失败即 exit 1）；仍比对文本，
        // 是为了接住那些自己写了自检但失败时不改退出码的应用。
        error('产物内无头自检未通过（rc=' . $runRc . '）：' . implode(' | ', array_slice($runOut, -5)));
        $ok = false;
    }
    $warned = array_values(array_filter($runOut, 'bundleIsWarningLine'));
    if ($warned) {
        error('产物启动时告警，说明有资源或能力没就绪：');
        foreach ($warned as $line) {
            echo "  - $line" . PHP_EOL;
        }
        $ok = false;
    }

    if (!$ok) {
        return 1;
    }

    success(sprintf('打包完成: %s (%.1f MB)', $bundle, dirSize($bundle) / 1048576));
    info('启动: open ' . escapeshellarg($bundle));
    return 0;
}

/**
 * 判定一条 `QT_MESSAGE_PATTERN` 格式化后的产物输出是否是「要拦的告警」。
 *
 * 只按 type 拦会误报：Qt 平台插件在 offscreen 下恒常打两条无害 warning ——
 * 字体别名表填充耗时（跟开发机速度有关，与产物自包含性无关）、
 * 以及「本插件不支持某能力」的通用告知。这两条白名单掉，剩下的 warning
 * 才是应用/桥接层自己 `qWarning` 出来的、指向真实缺陷的信号。
 */
function bundleIsWarningLine(string $line): bool
{
    if (preg_match('/^(crash|fatal)\|/', $line) === 1) {
        return true;
    }
    if (preg_match('/^(warning|critical)\|/', $line) !== 1) {
        return false;
    }
    if (str_contains($line, '|qt.qpa.fonts|')) {
        return false;
    }
    return !str_contains($line, 'This plugin does not support ');
}

