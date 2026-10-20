<?php

declare(strict_types=1);

namespace TypePHP\Qt\Tests;

use PHPUnit\Framework\TestCase;
use TypePHP\Qt\JsonStore;

/**
 * JsonStore 测试 —— 原子写入、错误处理、键值语义。
 */
final class JsonStoreTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/qt_jsonstore_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0777, true);
    }

    protected function tearDown(): void
    {
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

    private function store(string $name = 'settings.json'): JsonStore
    {
        return new JsonStore($this->root . '/' . $name);
    }

    // ── 载入 ──

    public function testMissingFileLoadsAsEmpty(): void
    {
        $store = $this->store();

        $this->assertSame([], $store->all());
        $this->assertNull($store->get('nope'));
        $this->assertFalse($store->has('nope'));
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $this->assertSame('fallback', $this->store()->get('nope', 'fallback'));
    }

    public function testEmptyFileLoadsAsEmpty(): void
    {
        file_put_contents($this->root . '/empty.json', "  \n");
        $store = $this->store('empty.json');

        $this->assertSame([], $store->all());
    }

    public function testInvalidJsonThrows(): void
    {
        file_put_contents($this->root . '/bad.json', '{not json');

        $this->expectException(\RuntimeException::class);
        $this->store('bad.json')->load();
    }

    public function testNonObjectTopLevelThrows(): void
    {
        file_put_contents($this->root . '/list.json', '[1,2,3]');

        $this->expectException(\RuntimeException::class);
        $this->store('list.json')->load();
    }

    public function testLoadIsLazy(): void
    {
        file_put_contents($this->root . '/lazy.json', '{"a":1}');
        $store = $this->store('lazy.json');

        // 未调 load() 也能读到值 —— ensureLoaded() 会补上。
        $this->assertSame(1, $store->get('a'));
    }

    // ── 键值 ──

    public function testSetGetDelete(): void
    {
        $store = $this->store();
        $store->set('theme', 'dark');

        $this->assertSame('dark', $store->get('theme'));
        $this->assertTrue($store->has('theme'));

        $this->assertTrue($store->delete('theme'));
        $this->assertFalse($store->has('theme'));
        $this->assertFalse($store->delete('theme'));
    }

    public function testNestedArraysSurviveRoundTrip(): void
    {
        $store = $this->store();
        $store->set('window', ['width' => 800, 'height' => 600, 'tags' => ['a', 'b']]);
        $store->flush();

        $reloaded = $this->store();
        $this->assertSame(['width' => 800, 'height' => 600, 'tags' => ['a', 'b']], $reloaded->get('window'));
    }

    public function testReplaceOverwritesEverything(): void
    {
        $store = $this->store();
        $store->set('keep', 1)->replace(['only' => 2]);

        $this->assertSame(['only' => 2], $store->all());
    }

    // ── 落盘 ──

    public function testFlushCreatesFileAndDirectory(): void
    {
        $store = new JsonStore($this->root . '/nested/deep/settings.json');
        $store->set('a', 1)->flush();

        $this->assertFileExists($this->root . '/nested/deep/settings.json');
    }

    public function testFlushWritesUtf8Unescaped(): void
    {
        $store = $this->store();
        $store->set('greeting', '你好，世界')->flush();

        $raw = file_get_contents($this->root . '/settings.json');
        $this->assertStringContainsString('你好，世界', $raw);
    }

    public function testFlushIsPrettyPrintedAndEndsWithNewline(): void
    {
        $store = $this->store();
        $store->set('a', 1)->flush();

        $raw = file_get_contents($this->root . '/settings.json');
        $this->assertStringContainsString("\n", $raw);
        $this->assertStringEndsWith("\n", $raw);
    }

    public function testSecondFlushWithSameContentIsSkipped(): void
    {
        $store = $this->store();
        $store->set('a', 1);

        $this->assertTrue($store->flush());
        // 内容没变 → 不重写，避免每帧调 flush 时的磁盘抖动。
        $this->assertFalse($store->flush());
    }

    public function testFlushAfterChangeWritesAgain(): void
    {
        $store = $this->store();
        $store->set('a', 1)->flush();
        $store->set('a', 2);

        $this->assertTrue($store->flush());
        $this->assertSame(2, $this->store()->get('a'));
    }

    public function testAtomicWriteLeavesNoTempFiles(): void
    {
        $store = $this->store();
        $store->set('a', 1)->flush();

        $leftovers = array_filter(
            scandir($this->root) ?: [],
            static fn (string $f): bool => str_ends_with($f, '.tmp')
        );
        $this->assertSame([], array_values($leftovers));
    }

    public function testWriteFailureThrowsAndLeavesNoTempFiles(): void
    {
        // 父路径是个普通文件 → mkdir 必失败，写入路径根本走不到 rename。
        file_put_contents($this->root . '/blocker', 'x');
        $store = new JsonStore($this->root . '/blocker/settings.json');

        try {
            $store->set('a', 1)->flush();
            $this->fail('应当抛异常');
        } catch (\RuntimeException) {
            // 期望路径
        }

        $entries = array_values(array_diff(scandir($this->root) ?: [], ['.', '..']));
        $this->assertSame(['blocker'], $entries);
    }

    public function testPathAccessor(): void
    {
        $this->assertSame($this->root . '/settings.json', $this->store()->path());
    }

    public function testRejectsEmptyPath(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new JsonStore('');
    }
}
