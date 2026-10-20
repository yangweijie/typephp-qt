<?php

declare(strict_types=1);

// 由 bin/qtphp 拆分而来（函数体逐字未改）。

function cmdPackage(string $path): int
{
    $path = projectDir($path);
    $name = projectName($path);
    $exe = builtBinary($path);
    if ($exe === null) {
        error('未找到可执行文件（已按入口 yml 的 output: / name: 查找）');
        info("请先运行: qtphp build $path");
        return 1;
    }

    $distDir = $path . '/dist';
    if (!is_dir($distDir)) {
        mkdir($distDir, 0755, true);
    }

    if (PHP_OS_FAMILY === 'Darwin') {
        return packageAppBundle($path, $name, $exe, $distDir);
    }
    if (PHP_OS_FAMILY === 'Linux') {
        return packageLinuxDir($path, $name, $exe, $distDir);
    }
    if (PHP_OS_FAMILY !== 'Windows') {
        // 显式报错，别让 BSD 之类跑到 Windows 分支上去找 windeployqt
        error('未实现 ' . PHP_OS_FAMILY . ' 平台的打包：目前只有 Windows 的 dist/ 目录、macOS 的 .app 与 Linux 的 dist/<name>/ 三条路线');
        return 1;
    }

    info("打包到: $distDir");

    // 先补齐运行时 DLL（幂等）
    deployRuntimeDlls($path);

    // 复制 exe 与 build/ 下全部 DLL
    $buildDir = $path . '/build';
    foreach (glob($buildDir . '/*.exe') as $file) {
        copy($file, $distDir . '/' . basename($file));
    }
    foreach (glob($buildDir . '/*.dll') as $file) {
        copy($file, $distDir . '/' . basename($file));
    }

    // assets 目录（如果项目有）
    if (is_dir($path . '/assets')) {
        copyDir($path . '/assets', $distDir . '/assets');
    }

    // Qt 依赖 + 平台插件：缺了这一步会在启动时报
    // "could not find or load the Qt platform plugin windows"
    $qtDir = findQt();
    if (!$qtDir) {
        error('未找到 Qt，无法部署 Qt 依赖');
        return 1;
    }
    $windeployqt = $qtDir . '/bin/windeployqt.exe';
    if (!file_exists($windeployqt)) {
        error("未找到 windeployqt: $windeployqt");
        return 1;
    }
    info('运行 windeployqt（部署 Qt DLL 与平台插件）...');
    // --no-opengl-sw：默认会塞进 20MB 的 opengl32sw.dll（软件渲染回退），桌面程序用不上。
    // tls / networkinformation 插件不能跳：qt_http_request 走 QNetworkAccessManager，
    // 跳过 tls 就只剩明文 HTTP，https 会失败。
    passthru(
        '"' . $windeployqt . '"'
        . ' --no-translations --no-compiler-runtime'
        . ' --no-opengl-sw'
        . ' "' . $distDir . '/' . $name . '.exe" 2>&1',
        $qtExit
    );
    if ($qtExit !== 0) {
        warning("windeployqt 返回 $qtExit，继续检查产物");
    }

    vendorWindowsOffscreenPlugin($qtDir, $distDir);

    // 自检：验证关键文件齐全
    return verifyPackage($distDir, $name);
}

/**
 * macOS 打包：产出 dist/<Name>.app，把 Qt framework、cocoa 插件和 brew 链接库都塞进 bundle。
 *
 * 流程对齐 typephp-compiler 的 examples/qt-taskboard/package-macos-app.sh：
 * 搭 bundle 骨架 → macdeployqt → 补 offscreen 插件 → 扫全部 Mach-O 补搬依赖并改写引用 → ad-hoc 签名 → 校验。
 * 我们的 PHP/PHPX 是静态链进二进制的（--enable-embed=static），所以没有 libphp.dylib 要搬。
 */
function packageAppBundle(string $path, string $name, string $exe, string $distDir): int
{
    $qtDir = findQt();
    if (!$qtDir) {
        error('未找到 Qt，无法部署 Qt 依赖');
        return 1;
    }
    $deploy = $qtDir . '/bin/macdeployqt';
    if (!is_executable($deploy)) {
        error("未找到 macdeployqt: $deploy");
        return 1;
    }
    $plist = $path . '/Info.macos.plist';
    if (!file_exists($plist)) {
        error("未找到 bundle 描述文件: $plist");
        return 1;
    }

    $bundle = $distDir . '/' . ucfirst($name) . '.app';
    $contents = $bundle . '/Contents';
    $frameworks = $contents . '/Frameworks';
    $appBinary = $contents . '/MacOS/' . $name;

    // 旧 bundle 的 install name 已经被改写过，留着会污染这一轮判断，整棵重建
    if (is_dir($bundle)) {
        deleteTree($bundle);
    }
    mkdir($contents . '/MacOS', 0755, true);
    mkdir($frameworks, 0755, true);

    info("打包到: $bundle");
    copy($plist, $contents . '/Info.plist');
    copy($exe, $appBinary);
    chmod($appBinary, 0755);

    if (is_dir($path . '/assets')) {
        copyDir($path . '/assets', $contents . '/Resources/assets');
    }

    info('运行 macdeployqt（部署 Qt framework、cocoa 插件与链接库）...');
    passthru(escapeshellarg($deploy) . ' ' . escapeshellarg($bundle) . ' -always-overwrite -no-codesign 2>&1', $qtExit);
    if ($qtExit !== 0) {
        warning("macdeployqt 返回 $qtExit，继续检查产物");
    }

    $headless = vendorHeadlessPlugin($qtDir, $contents);
    if ($headless !== '') {
        info('已补无头验收插件: ' . basename($headless));
    }

    $vendored = vendorBundleDeps($contents, $frameworks);
    if ($vendored) {
        info('已搬进 bundle：' . implode(', ', $vendored));
    }

    // 本地自测 ad-hoc 签名够用；分发给别人的机器需要正经签名 + 公证
    info('ad-hoc 签名...');
    passthru('codesign --force --deep --sign - ' . escapeshellarg($bundle) . ' 2>&1', $signExit);
    if ($signExit !== 0) {
        warning("codesign 返回 $signExit");
    }

    return verifyAppBundle($bundle, $appBinary);
}

/**
 * Linux 打包：产出 dist/<name>/ —— 二进制 + Qt 插件 + ldd 依赖闭包。
 *
 * Linux 的加载器不搜可执行文件同目录（和 Windows 相反），所以依赖集中放 lib/，
 * 再用 patchelf 把 rpath 改成 $ORIGIN/lib。这里必须是 DT_RPATH 而不是 DT_RUNPATH：
 * RUNPATH 只对本对象自己的 NEEDED 生效，那样 libQt6Gui 的 libglib/libEGL 这些
 * 二级依赖还是会回落到系统的 ld.so.cache，产物看着能跑其实没自包含。
 */
function packageLinuxDir(string $path, string $name, string $exe, string $distDir): int
{
    $patchelf = trim((string) shell_exec('command -v patchelf 2>/dev/null'));
    if ($patchelf === '') {
        error('未找到 patchelf，无法改写 rpath 做自包含打包');
        info('请先运行: apt install patchelf');
        return 1;
    }
    $qtDir = findQt();
    if (!$qtDir) {
        error('未找到 Qt，无法部署 Qt 插件与依赖');
        return 1;
    }
    $pluginDirs = linuxQtPluginDirs($qtDir);
    if (!$pluginDirs) {
        error("未找到 Qt 平台插件目录（$qtDir/lib/*/qt6/plugins/platforms）");
        return 1;
    }

    $root = $distDir . '/' . $name;
    if (is_dir($root)) {
        deleteTree($root);
    }
    $libDir = $root . '/lib';
    mkdir($libDir, 0755, true);

    info("打包到: $root");
    copy($exe, $root . '/' . $name);
    chmod($root . '/' . $name, 0755);
    if (is_dir($path . '/assets')) {
        copyDir($path . '/assets', $root . '/assets');
    }

    // 插件按产物内相对目录复刻一份，闭包用系统里的原件算（ldd 对插件同样给传递闭包）
    $closureRoots = [$exe];
    $plugins = [];
    foreach ($pluginDirs as $rel => $srcDir) {
        $dstDir = $root . '/plugins/' . $rel;
        if (!is_dir($dstDir)) {
            mkdir($dstDir, 0755, true);
        }
        foreach (glob($srcDir . '/*.so') ?: [] as $src) {
            $dst = $dstDir . '/' . basename($src);
            copy($src, $dst);
            $closureRoots[] = $src;
            $plugins[] = $dst;
        }
    }
    // Qt 只从 qt.conf 或环境变量找插件目录，写死成产物内的相对路径才能脱离开发环境
    file_put_contents($root . '/qt.conf', "[Paths]\nPlugins = plugins\n");

    $libs = [];
    foreach ($closureRoots as $object) {
        foreach (elfDeps($object) as $soname => $abs) {
            if (isset($libs[$soname]) || isSystemSoname($soname) || !file_exists($abs)) {
                continue;
            }
            // 落地名用 soname（libQt6Core.so.6），不是 readlink 后的真名，否则按 soname 找不到
            copy($abs, $libDir . '/' . $soname);
            $libs[$soname] = true;
        }
    }
    info('已搬入依赖: ' . count($libs) . " 个 .so，平台插件 " . count($plugins) . ' 个');

    if (!setLinuxRpath($patchelf, $root . '/' . $name, '$ORIGIN/lib')) {
        return 1;
    }
    foreach ($plugins as $plugin) {
        setLinuxRpath($patchelf, $plugin, '$ORIGIN/../../lib');
    }

    return verifyLinuxPackage($root, $name, $patchelf);
}

/** Debian/Ubuntu 是 lib/<三元组>/qt6/plugins，上游 tarball 常是扁平 plugins。 */
function linuxQtPluginDirs(string $qtDir): array
{
    $bases = [];
    $triple = linuxMultiarchTriple();
    if ($triple !== null) {
        $bases[] = $qtDir . '/lib/' . $triple . '/qt6/plugins';
    }
    $bases[] = $qtDir . '/lib/qt6/plugins';
    $bases[] = $qtDir . '/plugins';
    $base = '';
    foreach ($bases as $candidate) {
        if (is_dir($candidate . '/platforms')) {
            $base = $candidate;
            break;
        }
    }
    if ($base === '') {
        return [];
    }

    // platforms 全量拷：offscreen 是无头验收的前提，xcb 是真实桌面，minimal 兜底
    // xcbglintegrations 缺了 Qt 会退回 GLX 并告警，有就一起带上
    $found = [];
    foreach (['platforms', 'xcbglintegrations'] as $rel) {
        if (is_dir($base . '/' . $rel)) {
            $found[$rel] = $base . '/' . $rel;
        }
    }
    return $found;
}

/** 动态加载的 ELF 依赖：ldd 的 `soname => /abs/path`；vdso 与解释器行没有 `=>`，天然过滤。 */
function elfDeps(string $object): array
{
    $output = shell_exec('ldd ' . escapeshellarg($object) . ' 2>/dev/null');
    $deps = [];
    foreach (explode("\n", (string) $output) as $line) {
        if (preg_match('/^\s*(\S+)\s+=>\s+(\/\S+)/', $line, $m)) {
            $deps[$m[1]] = $m[2];
        }
    }
    return $deps;
}

/**
 * 这些留在目标系统上：glibc 家族的 ABI 由内核-用户态边界保证，搬了反而容易和
 * 目标机的 ld.so 版本不匹配（libstdc++ 不在此列，它按 GLIBCXX 符号版本卡，必须搬）。
 */
function isSystemSoname(string $soname): bool
{
    foreach (['libc.', 'libm.', 'libdl.', 'librt.', 'libpthread.', 'libresolv.',
              'libnsl.', 'libutil.', 'libgcc_s.', 'ld-linux-'] as $prefix) {
        if (str_starts_with($soname, $prefix)) {
            return true;
        }
    }
    return false;
}

/** 改写 rpath；--force-rpath 保证写的是可传递的 DT_RPATH。 */
function setLinuxRpath(string $patchelf, string $object, string $rpath): bool
{
    exec($patchelf . ' --force-rpath --set-rpath ' . escapeshellarg($rpath)
        . ' ' . escapeshellarg($object) . ' 2>&1', $out, $rc);
    if ($rc !== 0) {
        error('patchelf 改写 ' . basename($object) . ' 失败：' . implode(' ', $out));
        return false;
    }
    return true;
}

/**
 * 自检产物：依赖必须全在 dist/<name>/lib 内。
 *
 * ldd 会展开 $ORIGIN 并解析整张传递图，所以「每一行都落在产物里」就是自包含的实测证据；
 * 有漏的会直接显示成系统绝对路径。
 */
function verifyLinuxPackage(string $root, string $name, string $patchelf): int
{
    echo PHP_EOL;
    info('自检打包产物...');
    $ok = true;

    $exe = $root . '/' . $name;
    $libReal = realpath($root . '/lib') ?: ($root . '/lib');
    $leaked = [];
    // 可执行文件的 ldd 已经含传递闭包，但插件不在那条链上：libqxcb.so 的十几个 xcb 库
    // 只有对插件自己跑 ldd 才看得见，而它们恰恰是最容易漏搬的一批。
    $objects = [$exe];
    foreach (glob($root . '/plugins/*/*.so') ?: [] as $plugin) {
        $objects[] = $plugin;
    }
    foreach ($objects as $object) {
        foreach (elfDeps($object) as $soname => $abs) {
            if (isSystemSoname($soname)) {
                continue;
            }
            // $ORIGIN 是按传入路径的 dirname 文本展开的，可能带出 /./ 这种写法，先规范化再比
            $realAbs = realpath($abs) ?: $abs;
            if (!str_starts_with($realAbs, $libReal . '/')) {
                $leaked[] = basename($object) . ' 的 ' . $soname . " => $abs";
            }
        }
    }
    if ($leaked) {
        error('仍指向产物之外：');
        foreach (array_unique($leaked) as $line) {
            echo "  - $line" . PHP_EOL;
        }
        $ok = false;
    }

    if (!file_exists($root . '/plugins/platforms/libqoffscreen.so')) {
        error('产物内没有 libqoffscreen.so，无法在无 GUI 会话的机器上验收');
        $ok = false;
    }

    // DT_RUNPATH 一旦同时存在就会顶掉 DT_RPATH（patchelf 老版本踩过），直接读 dynamic 段确认
    exec('readelf -d ' . escapeshellarg($exe) . ' 2>/dev/null', $lines, $rdRc);
    $dynamic = implode("\n", $lines);
    if ($rdRc !== 0 || !str_contains($dynamic, '(RPATH)') || str_contains($dynamic, '(RUNPATH)')) {
        error("rpath 改写结果不是 DT_RPATH，二级依赖仍会回落系统库（patchelf: $patchelf）");
        $ok = false;
    }

    if (!$ok) {
        return 1;
    }

    success(sprintf('打包完成: %s (%.1f MB)', $root, dirSize($root) / 1048576));
    info('启动: ' . escapeshellarg($exe));
    info('无头验收: env -i QT_QPA_PLATFORM=offscreen ' . escapeshellarg($exe) . ' --selftest');
    return 0;
}

