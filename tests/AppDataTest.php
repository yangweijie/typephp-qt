<?php

declare(strict_types=1);

namespace TypePHP\Qt\Tests;

use PHPUnit\Framework\TestCase;
use TypePHP\Qt\AppData;

/**
 * AppData 测试 —— 目录解析与命名校验。
 *
 * 真实环境变量会被临时改写（putenv），tearDown 里还原，否则后续测试
 * 会写到测试目录里去。
 */
final class AppDataTest extends TestCase
{
    private string $root = '';

    /** @var array<string, string|false> 被改写过的环境变量原值。 */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qt_appdata_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);

        foreach (['APPDATA', 'LOCALAPPDATA', 'XDG_CONFIG_HOME', 'XDG_CACHE_HOME', 'XDG_DATA_HOME'] as $name) {
            $this->savedEnv[$name] = getenv($name);
        }

        // Windows 走 APPDATA/LOCALAPPDATA，Linux 走 XDG —— 两类都指向同一个临时根，
        // 这样测试在三个平台上都能跑，且不会碰用户真实的配置目录。
        putenv('APPDATA=' . $this->root . '/roaming');
        putenv('LOCALAPPDATA=' . $this->root . '/local');
        putenv('XDG_CONFIG_HOME=' . $this->root . '/xdg-config');
        putenv('XDG_CACHE_HOME=' . $this->root . '/xdg-cache');
        putenv('XDG_DATA_HOME=' . $this->root . '/xdg-data');
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        self::removeTree($this->root);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? self::removeTree($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    // ── 目录解析 ──

    public function testConfigDirIsCreatedAndEndsWithAppName(): void
    {
        $data = new AppData('MyApp');
        $dir = $data->configDir();

        $this->assertDirectoryExists($dir);
        $this->assertSame('MyApp', basename(str_replace('\\', '/', $dir)));
    }

    public function testCacheAndDataDirsDifferFromConfig(): void
    {
        $data = new AppData('MyApp');

        $this->assertNotSame($data->configDir(), $data->cacheDir());
        $this->assertNotSame($data->configDir(), $data->dataDir());
        $this->assertDirectoryExists($data->cacheDir());
        $this->assertDirectoryExists($data->dataDir());
    }

    public function testOrgNameAddsOneLevel(): void
    {
        $data = new AppData('MyApp', 'Acme');
        $parts = explode('/', str_replace('\\', '/', $data->configDir()));

        $this->assertSame('Acme', $parts[count($parts) - 2]);
        $this->assertSame('MyApp', $parts[count($parts) - 1]);
    }

    public function testEmptyOrgNameIsIgnored(): void
    {
        $plain = (new AppData('MyApp'))->configDir();
        $empty = (new AppData('MyApp', ''))->configDir();

        $this->assertSame($plain, $empty);
    }

    public function testDirectoryIsReusedOnSecondAccess(): void
    {
        $data = new AppData('MyApp');

        $this->assertSame($data->configDir(), $data->configDir());
    }

    // ── 文件路径 ──

    public function testConfigPathJoinsFileName(): void
    {
        $data = new AppData('MyApp');
        $path = $data->configPath('settings.json');

        $this->assertSame($data->configDir() . '/settings.json', $path);
    }

    public function testConfigPathWithNoArgumentReturnsDirectory(): void
    {
        $data = new AppData('MyApp');

        $this->assertSame($data->configDir(), $data->configPath());
    }

    public function testCachePathAndDataPathUseTheirOwnDirs(): void
    {
        $data = new AppData('MyApp');

        $this->assertSame($data->cacheDir() . '/t.bin', $data->cachePath('t.bin'));
        $this->assertSame($data->dataDir() . '/db.sqlite', $data->dataPath('db.sqlite'));
    }

    // ── 命名校验 ──

    public function testRejectsEmptyAppName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AppData('');
    }

    public function testRejectsPathTraversalAppName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AppData('..');
    }

    public function testRejectsSeparatorInAppName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AppData('a/b');
    }

    public function testRejectsSeparatorInOrgName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AppData('MyApp', 'a\\b');
    }

    public function testRejectsWindowsReservedCharacters(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AppData('My:App');
    }

    public function testAppNameAccessor(): void
    {
        $this->assertSame('MyApp', (new AppData('MyApp'))->appName());
    }
}
