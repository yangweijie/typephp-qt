<?php

declare(strict_types=1);

namespace TypePHP\Qt\Tests\A2UI;

use PHPUnit\Framework\TestCase;
use TypePHP\Qt\A2UI\Catalog;

/**
 * Catalog 单测 —— 映射表完整性 / 占位清单 / UAX #31 规则（spec「Catalog Entity
 * Naming Rules」的有效与非法示例逐字钉）。
 */
final class CatalogTest extends TestCase
{
    public function testBasicCatalogCoverage(): void
    {
        // spec「Basic Component Catalog」18 组件 = 13 映射 + 5 占位，两集合互斥且并集完整
        $mapped = array_keys(Catalog::MAP);
        $this->assertSame(
            ['Text', 'Row', 'Column', 'List', 'Card', 'Tabs', 'Divider', 'Button',
                'CheckBox', 'TextField', 'Slider', 'ChoicePicker', 'Image'],
            $mapped,
        );
        $this->assertSame(['Icon', 'Video', 'AudioPlayer', 'Modal', 'DateTimeInput'], Catalog::PLACEHOLDER);
        $this->assertSame([], array_intersect($mapped, Catalog::PLACEHOLDER));
        $this->assertCount(18, array_merge($mapped, Catalog::PLACEHOLDER));
    }

    public function testWidgetTypeMapping(): void
    {
        $this->assertSame('label', Catalog::widgetType('Text'));
        $this->assertSame('hbox', Catalog::widgetType('Row'));
        $this->assertSame('vbox', Catalog::widgetType('Column'));
        $this->assertSame('scroll', Catalog::widgetType('List'));
        $this->assertSame('group', Catalog::widgetType('Card'));
        $this->assertNull(Catalog::widgetType('Icon'));       // 占位类型无映射
        $this->assertNull(Catalog::widgetType('NotAWidget')); // 未知类型
    }

    public function testUax31ValidIds(): void
    {
        // spec 原文有效例
        foreach (['UserProfileCard', 'submit_form', 'item_id_1', '_internal_state', 'userCard'] as $id) {
            $this->assertTrue(Catalog::isValidId($id), $id);
        }
    }

    public function testUax31InvalidIds(): void
    {
        // spec 原文非法例：空格 / 首字符数字 / 连字符 / 符号
        foreach (['User Card', '1stItem', 'submit-form', 'user#name', 'calc$val', ''] as $id) {
            $this->assertFalse(Catalog::isValidId($id), $id);
        }
    }

    public function testRootIdIsProtocolReserved(): void
    {
        $this->assertSame('root', Catalog::ROOT_ID);
        $this->assertTrue(Catalog::isValidId(Catalog::ROOT_ID));
    }
}
