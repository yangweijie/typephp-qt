<?php

declare(strict_types=1);

namespace TypePHP\Qt\Tests\A2UI;

use PHPUnit\Framework\TestCase;
use TypePHP\Qt\A2UI\DataModel;

/**
 * DataModel 单测 —— updateDataModel 三语义 + JSON Pointer 的钉点。
 * 语义出处：spec「Agent to renderer updates」（upsert / null=删键 / 根替换）。
 */
final class DataModelTest extends TestCase
{
    public function testDecodePointerEscapes(): void
    {
        // ~1 → /、~0 → ~，顺序不能反（RFC 6901 §4：先 ~1 后 ~0 会把字面 "~1" 误解码）
        $this->assertSame(['a/b', 'c~d', '0'], DataModel::decodePointer('/a~1b/c~0d/0'));
        $this->assertSame([], DataModel::decodePointer(''));
        $this->assertSame([], DataModel::decodePointer('/'));  // A2UI 口径：/ = 根
    }

    public function testGetAndUpsertCreatesIntermediates(): void
    {
        $model = new DataModel();
        $model->applyUpdate('/user/name', 'Alice');
        $this->assertSame('Alice', $model->get('/user/name'));
        $this->assertSame(['user' => ['name' => 'Alice']], $model->all());
    }

    public function testUpdateExistingPathReplaces(): void
    {
        $model = new DataModel();
        $model->applyUpdate('/a/b', 1);
        $model->applyUpdate('/a/b', 2);
        $this->assertSame(2, $model->get('/a/b'));
    }

    public function testScalarIntermediateIsReplacedOnPathWrite(): void
    {
        $model = new DataModel();
        $model->applyUpdate('/a', 'scalar');
        $model->applyUpdate('/a/b', 1);  // 命中标量中间层：整段替换成容器（宽容）
        $this->assertSame(['b' => 1], $model->get('/a'));
    }

    public function testNullValueDeletesKey(): void
    {
        $model = new DataModel();
        $model->replaceModel(['a' => 1, 'user' => ['tmp' => 'x']]);
        $model->applyUpdate('/a', null);
        $this->assertSame(['user' => ['tmp' => 'x']], $model->all());
        $model->applyUpdate('/user/tmp', null);
        $this->assertSame(['user' => []], $model->all());
    }

    public function testNullValueRemovesListElementBySplice(): void
    {
        $model = new DataModel();
        $model->replaceModel(['list' => [10, 20, 30]]);
        $model->applyUpdate('/list/1', null);
        $this->assertSame([10, 30], $model->get('/list'), '列表删元素用 splice 保持连续，不留 null 洞');
    }

    public function testRootReplaceWithEmptyPath(): void
    {
        $model = new DataModel();
        $model->replaceModel(['old' => true]);
        foreach (['', '/', null] as $rootPath) {
            $model->applyUpdate($rootPath, ['fresh' => $rootPath]);
            $this->assertSame(['fresh' => $rootPath], $model->all());
        }
    }

    public function testRootNullClearsModel(): void
    {
        $model = new DataModel();
        $model->replaceModel(['a' => 1]);
        $model->applyUpdate('/', null);
        $this->assertSame([], $model->all());
    }

    public function testListIndexAssignmentExtendsList(): void
    {
        $model = new DataModel();
        $model->replaceModel(['list' => [10]]);
        $model->applyUpdate('/list/2', 30);  // upsert：越界下标 = 补洞赋值
        $this->assertSame([10, null, 30], $model->get('/list'));
    }

    public function testResolveRelative(): void
    {
        $this->assertSame('/employees/0/name', DataModel::resolveRelative('/employees/0', 'name'));
        $this->assertSame('/root', DataModel::resolveRelative('', 'root'));
        $this->assertSame('/abs', DataModel::resolveRelative('/employees/0', '/abs'));
        $this->assertSame('/', DataModel::resolveRelative('/x', ''));
    }

    public function testGetMissingReturnsNull(): void
    {
        $model = new DataModel();
        $this->assertNull($model->get('/no/such/path'));
    }
}
