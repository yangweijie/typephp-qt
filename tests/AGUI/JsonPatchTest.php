<?php

declare(strict_types=1);

namespace TypePHP\Qt\Tests\AGUI;

use PHPUnit\Framework\TestCase;
use TypePHP\Qt\AGUI\JsonPatch;

/**
 * JsonPatch（RFC 6902 子集）单测 —— 数据全部内联数组，不依赖网络/Qt/桥接函数。
 * 宽容语义钉死：单 op 失败 = warning + 跳过（STATE_DELTA 同语义），不中断流。
 */
final class JsonPatchTest extends TestCase
{
    private array $doc = ['a' => 1, 'b' => ['c' => 2, 'list' => [10, 20, 30]]];

    /** 六个 op 各一例。 */

    public function testAddObjectKey(): void
    {
        $result = JsonPatch::apply($this->doc, [['op' => 'add', 'path' => '/b/new', 'value' => 'x']]);
        $this->assertSame('x', $result['doc']['b']['new']);
        $this->assertSame([], $result['warnings']);
    }

    public function testAddListAppendWithDash(): void
    {
        $result = JsonPatch::apply($this->doc, [['op' => 'add', 'path' => '/b/list/-', 'value' => 40]]);
        $this->assertSame([10, 20, 30, 40], $result['doc']['b']['list']);
    }

    public function testAddListInsertShiftsElements(): void
    {
        $result = JsonPatch::apply($this->doc, [['op' => 'add', 'path' => '/b/list/1', 'value' => 15]]);
        $this->assertSame([10, 15, 20, 30], $result['doc']['b']['list'], 'add 在列表中间 = 插入（元素后移），不是替换');
    }

    public function testRemove(): void
    {
        $result = JsonPatch::apply($this->doc, [
            ['op' => 'remove', 'path' => '/b/c'],
            ['op' => 'remove', 'path' => '/b/list/0'],
        ]);
        $this->assertArrayNotHasKey('c', $result['doc']['b']);
        $this->assertSame([20, 30], $result['doc']['b']['list']);
    }

    public function testReplace(): void
    {
        $result = JsonPatch::apply($this->doc, [['op' => 'replace', 'path' => '/a', 'value' => 42]]);
        $this->assertSame(42, $result['doc']['a']);
    }

    public function testMove(): void
    {
        $result = JsonPatch::apply($this->doc, [['op' => 'move', 'from' => '/b/c', 'path' => '/moved']]);
        $this->assertSame(2, $result['doc']['moved']);
        $this->assertArrayNotHasKey('c', $result['doc']['b']);
    }

    public function testCopy(): void
    {
        $result = JsonPatch::apply($this->doc, [['op' => 'copy', 'from' => '/a', 'path' => '/aCopy']]);
        $this->assertSame(1, $result['doc']['aCopy']);
        $this->assertSame(1, $result['doc']['a']);
    }

    public function testTestOpPassesAndFails(): void
    {
        $ok = JsonPatch::apply($this->doc, [['op' => 'test', 'path' => '/a', 'value' => 1]]);
        $this->assertSame([], $ok['warnings']);

        $bad = JsonPatch::apply($this->doc, [['op' => 'test', 'path' => '/a', 'value' => 999]]);
        $this->assertCount(1, $bad['warnings']);
        $this->assertSame($this->doc, $bad['doc'], 'test 失败不改动 doc');
    }

    /** 嵌套路径 + 数组索引路径（经索引段下潜到对象里写值）。 */
    public function testNestedAndArrayIndexPaths(): void
    {
        $doc = ['o' => ['list' => [['a' => 1], ['b' => 2]]]];
        $result = JsonPatch::apply($doc, [
            ['op' => 'add', 'path' => '/o/list/0/new', 'value' => 'x'],
            ['op' => 'replace', 'path' => '/o/list/1/b', 'value' => 7],
        ]);
        $this->assertSame([['a' => 1, 'new' => 'x'], ['b' => 7]], $result['doc']['o']['list']);
        $this->assertSame([], $result['warnings']);
    }

    /** 坏 op 跳过留 warning：前后好 op 照常应用（不中断）。 */
    public function testBadOpIsSkippedWithWarning(): void
    {
        $result = JsonPatch::apply($this->doc, [
            ['op' => 'add', 'path' => '/ok1', 'value' => 1],
            ['op' => 'teleport', 'path' => '/nope'],
            ['op' => 'remove', 'path' => '/b/missing'],
            ['op' => 'add', 'path' => '/ok2', 'value' => 2],
        ]);
        $this->assertSame(1, $result['doc']['ok1']);
        $this->assertSame(2, $result['doc']['ok2']);
        $this->assertCount(2, $result['warnings'], '两个坏 op 各一条 warning');
    }

    /** 坏 patch（非数组 / 非 JSON）：原样返回 doc + warning。 */
    public function testBadPatchReturnsDocAsIs(): void
    {
        $result = JsonPatch::apply($this->doc, 'not json at all');
        $this->assertSame($this->doc, $result['doc']);
        $this->assertCount(1, $result['warnings']);

        $result = JsonPatch::apply($this->doc, ['op' => 'add', 'path' => '/x']);  // 单 op 对象不是列表
        $this->assertSame($this->doc, $result['doc']);
        $this->assertCount(1, $result['warnings']);
    }

    /** 输入 doc 不可变：apply 不碰原值。 */
    public function testInputDocIsImmutable(): void
    {
        JsonPatch::apply($this->doc, [['op' => 'add', 'path' => '/mut', 'value' => 1]]);
        $this->assertArrayNotHasKey('mut', $this->doc);
    }

    /** 空 patch = 零 op，合法且无事可做。 */
    public function testEmptyPatchIsNoop(): void
    {
        $result = JsonPatch::apply($this->doc, []);
        $this->assertSame($this->doc, $result['doc']);
        $this->assertSame([], $result['warnings']);
    }
}
