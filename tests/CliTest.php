<?php

declare(strict_types=1);

namespace TypePHP\Qt\Tests;

use PHPUnit\Framework\TestCase;

/**
 * CLI 辅助函数测试 —— 颜色降级、桥接源注入、项目元数据解析。
 *
 * bin/qtphp 末尾是 `exit(main($argv))`，整体 require 会把测试进程带走，所以这里
 * 按文件加载真正要用到的实现（都是全局函数，无命名空间）。
 */
final class CliTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        require_once __DIR__ . '/../bin/lib/output.php';
        require_once __DIR__ . '/../bin/lib/discovery.php';
        require_once __DIR__ . '/../bin/lib/project.php';
        require_once __DIR__ . '/../bin/lib/build.php';

        $this->root = sys_get_temp_dir() . '/qt_cli_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
        putenv('NO_COLOR');
        putenv('CLICOLOR_FORCE');
        self::removeTree($this->root);
    }

    // ── 颜色降级 ──

    public function testColorKeepsAnsiWhenForced(): void
    {
        putenv('CLICOLOR_FORCE=1');
        putenv('NO_COLOR');

        self::assertSame("\033[31mx\033[0m", \color('x', 'red'));
    }

    public function testColorDegradesToPlainTextWithNoColor(): void
    {
        // 重定向/CI 里带 \033 会变成日志里的乱码
        putenv('NO_COLOR=1');
        putenv('CLICOLOR_FORCE');

        self::assertSame('x', \color('x', 'red'));
        self::assertFalse(\colorEnabled());
    }

    public function testNoColorBeatsForcedColor(): void
    {
        // 强制开是为了本地看彩色；NO_COLOR 是用户明确的退出请求，优先级更高
        putenv('CLICOLOR_FORCE=1');
        putenv('NO_COLOR=1');

        self::assertFalse(\colorEnabled());
    }

    // ── sources 注入 ──

    public function testInjectIntoExistingSourcesKeepsIndent(): void
    {
        $yml = "name: demo\nsources:\n  - src/main.php\nlink-libs:\n  - Qt6Core\n";

        $out = \injectSourcesIntoProjectYaml($yml, ['D:\\pkg\\cpp-src']);

        self::assertIsString($out);
        // Windows 路径反斜杠在 YAML 里是转义符，必须换成 / 并用单引号
        self::assertStringContainsString("sources:\n  - 'D:/pkg/cpp-src'\n  - src/main.php\n", $out);
        // 后面的段落不能被吃掉
        self::assertStringContainsString("link-libs:\n  - Qt6Core\n", $out);
    }

    public function testInjectAppendsSourcesBlockWhenMissing(): void
    {
        $out = \injectSourcesIntoProjectYaml("name: demo\n", ['/pkg/qt.stub.php']);

        self::assertIsString($out);
        self::assertStringStartsWith("name: demo\n", $out);
        self::assertStringContainsString("sources:\n  - '/pkg/qt.stub.php'", $out);
    }

    public function testInjectKeepsExistingIndentWidth(): void
    {
        $yml = "sources:\n    - src/main.php\n";

        $out = (string) \injectSourcesIntoProjectYaml($yml, ['/p']);

        self::assertStringContainsString("sources:\n    - '/p'\n    - src/main.php\n", $out);
    }

    public function testInjectUsesTwoSpaceIndentWhenListIsEmpty(): void
    {
        $out = (string) \injectSourcesIntoProjectYaml("sources:\n", ['/p']);

        self::assertSame("sources:\n  - '/p'\n", $out);
    }

    public function testInjectRefusesInlineSourcesArray(): void
    {
        // sources: [a, b] 塞第二行会变成语法错误 —— 宁可退出注入，也不能产出坏 yml
        self::assertNull(\injectSourcesIntoProjectYaml("sources: [a/]\n", ['/pkg/a']));
    }

    public function testInjectWithNoPathsIsIdentity(): void
    {
        self::assertSame("name: demo\n", \injectSourcesIntoProjectYaml("name: demo\n", []));
    }

    // ── vendor extra.bridge ──

    public function testBridgeSourcesEmptyWithoutVendor(): void
    {
        self::assertSame([], \bridgeSourcesFromVendor($this->root));
    }

    public function testBridgeSourcesReadComposerTwoLayout(): void
    {
        $pkg = $this->root . '/vendor/typephp/qt';
        mkdir($pkg . '/php-src', 0777, true);
        mkdir($pkg . '/cpp-src', 0777, true);
        file_put_contents($pkg . '/php-src/qt.stub.php', "<?php\n");
        file_put_contents($pkg . '/cpp-src/qt_loop.cc', "//\n");
        mkdir($this->root . '/vendor/composer', 0777, true);
        file_put_contents($this->root . '/vendor/composer/installed.json', json_encode([
            'packages' => [[
                'name' => 'typephp/qt',
                // composer 2 的 install-path 相对 vendor/composer，不是项目根
                'install-path' => '../typephp/qt',
                'extra' => ['bridge' => [
                    'stub' => 'php-src/qt.stub.php',
                    'sources' => ['cpp-src', 'php-src/qt.stub.php'],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR));

        $paths = \bridgeSourcesFromVendor($this->root);

        // stub 与 sources 里重复指向的同一文件只保留一次
        self::assertCount(2, $paths);
        self::assertStringEndsWith('php-src/qt.stub.php', str_replace('\\', '/', $paths[0]));
        self::assertStringEndsWith('cpp-src', str_replace('\\', '/', $paths[1]));
    }

    public function testBridgeSourcesReadComposerOneLayout(): void
    {
        // composer 1 的 installed.json 是顶层数组，且没有 install-path —— 包目录只能由包名推
        $pkg = $this->root . '/vendor/typephp/qt';
        mkdir($pkg . '/php-src', 0777, true);
        file_put_contents($pkg . '/php-src/qt.stub.php', "<?php\n");
        mkdir($this->root . '/vendor/composer', 0777, true);
        file_put_contents($this->root . '/vendor/composer/installed.json', json_encode([[
            'name' => 'typephp/qt',
            'version' => '1.0.0',
            'extra' => ['bridge' => [
                'stub' => 'php-src/qt.stub.php',
                'sources' => ['php-src/qt.stub.php'],
            ]],
        ]], JSON_THROW_ON_ERROR));

        $paths = \bridgeSourcesFromVendor($this->root);

        // stub 与 sources 指同一文件，按绝对路径去重后只剩一条
        self::assertCount(1, $paths);
        self::assertStringEndsWith('php-src/qt.stub.php', str_replace('\\', '/', $paths[0]));
    }

    public function testBridgeSourcesIgnoresPackagesWithoutBridge(): void
    {
        mkdir($this->root . '/vendor/composer', 0777, true);
        mkdir($this->root . '/vendor/acme/tool', 0777, true);
        file_put_contents($this->root . '/vendor/composer/installed.json', json_encode([
            'packages' => [[
                'name' => 'acme/tool',
                'install-path' => '../acme/tool',
                'extra' => ['something-else' => true],
            ]],
        ], JSON_THROW_ON_ERROR));

        self::assertSame([], \bridgeSourcesFromVendor($this->root));
    }

    // ── 项目元数据 ──

    public function testProjectNamePrefersYamlOverDirectoryName(): void
    {
        $dir = $this->root . '/typephp-smartgit';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/project.yml', "name: typephp_smartgit\noutput: build/typephp_smartgit\n");

        self::assertSame('typephp_smartgit', \projectName($dir));
    }

    public function testProjectNameFallsBackToDirectoryName(): void
    {
        $dir = $this->root . '/plainapp';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/project.yml', "mode: bin\n");

        self::assertSame('plainapp', \projectName($dir));
    }

    public function testBuiltBinaryFollowsOutputField(): void
    {
        $dir = $this->root . '/app2';
        mkdir($dir . '/build', 0777, true);
        file_put_contents($dir . '/project.yml', "name: app_two\noutput: build/app_two\n");
        $binary = $dir . '/build/app_two' . (PHP_OS_FAMILY === 'Windows' ? '.exe' : '');
        touch($binary);

        self::assertSame(
            str_replace('\\', '/', $binary),
            str_replace('\\', '/', (string) \builtBinary($dir))
        );
    }

    public function testBuiltBinaryIsNullBeforeBuild(): void
    {
        $dir = $this->root . '/app3';
        mkdir($dir, 0777, true);
        file_put_contents($dir . '/project.yml', "name: app_three\n");

        self::assertNull(\builtBinary($dir));
    }

    // ── 脚手架模板 ──

    public function testNewTemplateBridgeSourcesMatchCppSrc(): void
    {
        // 桥接 C++ 拆分（qt_bridge.cc → qt_loop/render/decor/widgets/webview）时，
        // 模板里漏改一个名字，`qtphp new` 生成的项目就必然编译失败（Source file not exists），
        // 而这条路径没有任何单测会走到 —— build 要 Qt、要编译器。只能静态核对：
        // 模板引用的每个 .cc 都要存在，cpp-src/ 下的每个 .cc 也都要被引用。
        $template = (string) file_get_contents(__DIR__ . '/../bin/lib/new.php');
        preg_match_all('#cpp-src/([A-Za-z0-9_.-]+\.cc)#', $template, $matches);
        $referenced = array_values(array_unique($matches[1]));

        $onDisk = array_map('basename', glob(__DIR__ . '/../cpp-src/*.cc') ?: []);

        sort($referenced);
        sort($onDisk);
        self::assertSame($onDisk, $referenced, '脚手架模板的桥接源列表与 cpp-src/ 实际文件不一致');
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($dir);
    }
}
