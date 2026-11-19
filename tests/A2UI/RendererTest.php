<?php

declare(strict_types=1);

namespace TypePHP\Qt\Tests\A2UI;

use PHPUnit\Framework\TestCase;
use TypePHP\Qt\A2UI\Renderer;

/**
 * Renderer 单测 —— 信封六消息分发 / 邻接表渲染 / 模板迭代 / 事件绑定的钉点。
 * 宽容语义出处：spec「updateComponents」（缺失引用跳过）、「createSurface」
 * （重复 create = INTEGRITY_ERROR）、UI composition（root 缓冲）。
 */
final class RendererTest extends TestCase
{
    private Renderer $renderer;

    protected function setUp(): void
    {
        $this->renderer = new Renderer();
    }

    /** 信封快捷构造。 */
    private static function envelope(string $key, array $payload): array
    {
        return ['version' => '1.0', $key => $payload];
    }

    public function testCreateSurfaceWithComponentsAndDataModel(): void
    {
        // spec：同消息带 components+dataModel 时先 dataModel（首帧绑定有数据）
        $this->renderer->consume(self::envelope('createSurface', [
            'surfaceId' => 'card',
            'components' => [
                ['id' => 'root', 'component' => 'Column', 'children' => ['t']],
                ['id' => 't', 'component' => 'Text', 'text' => ['@path' => '/name']],
            ],
            'dataModel' => ['name' => 'John Doe'],
        ]));
        $this->assertTrue($this->renderer->hasSurface('card'));
        $this->assertSame('John Doe', $this->renderer->model('card')->get('/name'));
        $out = $this->renderer->toWidgetTree('card');
        $this->assertSame('vbox', $out['tree']['type']);
        $this->assertSame('label', $out['tree']['children'][0]['type']);
        $this->assertSame('John Doe', $out['tree']['children'][0]['text']);
        $this->assertSame([], $this->renderer->takeWarnings());
    }

    public function testDuplicateCreateSurfaceIsIntegrityError(): void
    {
        $this->renderer->consume(self::envelope('createSurface', ['surfaceId' => 's']));
        $this->renderer->consume(self::envelope('createSurface', ['surfaceId' => 's']));
        $this->assertSame(['s'], $this->renderer->surfaceIds());
        $outbound = $this->renderer->takeOutbound();
        $this->assertCount(1, $outbound);
        $this->assertArrayHasKey('error', $outbound[0]);
        $this->assertSame('INTEGRITY_ERROR', $outbound[0]['error']['code']);
        $this->assertStringContainsString('INTEGRITY_ERROR', $this->renderer->takeWarnings()[0]);
    }

    public function testUpdateComponentsOnMissingSurfaceWarns(): void
    {
        $this->renderer->consume(self::envelope('updateComponents', ['surfaceId' => 'ghost', 'components' => []]));
        $this->assertCount(1, $this->renderer->takeWarnings());
        $this->assertNull($this->renderer->toWidgetTree('ghost'));
    }

    public function testUnknownEnvelopeKeyWarns(): void
    {
        $this->renderer->consume(['version' => '1.0', 'experimentalKey' => []]);
        $warnings = $this->renderer->takeWarnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('experimentalKey', $warnings[0]);
    }

    public function testVersionMismatchWarns(): void
    {
        // 注意不能走 self::envelope()：array union 保留左值，version 会被 1.0 盖回
        $this->renderer->consume(['version' => '0.9', 'createSurface' => ['surfaceId' => 's']]);
        $warnings = $this->renderer->takeWarnings();
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('0.9', $warnings[0]);
    }

    public function testRootBufferingShowsPlaceholder(): void
    {
        // spec：root 未定义前其它组件缓冲不渲染
        $this->renderer->consume(self::envelope('createSurface', [
            'surfaceId' => 's',
            'components' => [['id' => 't', 'component' => 'Text', 'text' => 'waiting']],
        ]));
        $out = $this->renderer->toWidgetTree('s');
        $this->assertSame('label', $out['tree']['type']);
        $this->assertSame('a2ui_wait_root', $out['tree']['id']);
        $this->assertStringContainsString('root', $out['tree']['text']);
    }

    public function testInvalidComponentIdSkipped(): void
    {
        // UAX #31：`user-card` / `1st` 非法 → 组件跳过 + warning
        $this->renderer->consume(self::envelope('createSurface', [
            'surfaceId' => 's',
            'components' => [
                ['id' => 'root', 'component' => 'Column', 'children' => ['bad-id', 'ok']],
                ['id' => 'bad-id', 'component' => 'Text', 'text' => 'x'],
                ['id' => 'ok', 'component' => 'Text', 'text' => 'y'],
            ],
        ]));
        $out = $this->renderer->toWidgetTree('s');
        $this->assertCount(1, $out['tree']['children'], '非法 id 组件不入表，children 引用被跳过');
        $this->assertSame('y', $out['tree']['children'][0]['text']);
        $this->assertNotEmpty($this->renderer->takeWarnings());
    }

    public function testUpdateDataModelUpsertAndDelete(): void
    {
        $this->renderer->consume(self::envelope('createSurface', ['surfaceId' => 's']));
        $this->renderer->consume(self::envelope('updateDataModel', ['surfaceId' => 's', 'path' => '/a/b', 'value' => 1]));
        $this->assertSame(1, $this->renderer->model('s')->get('/a/b'));
        $this->renderer->consume(self::envelope('updateDataModel', ['surfaceId' => 's', 'path' => '/a/b', 'value' => null]));
        $this->assertSame(['a' => []], $this->renderer->model('s')->all());
    }

    public function testUpdateDataModelWithoutValueIsRejectedNotExecuted(): void
    {
        // 缺 value ≠ value null：畸形消息必须拒绝，不能误执行成删数据
        $this->renderer->consume(self::envelope('createSurface', ['surfaceId' => 's']));
        $this->renderer->consume(self::envelope('updateDataModel', ['surfaceId' => 's', 'path' => '/a', 'value' => 1]));
        $this->renderer->consume(self::envelope('updateDataModel', ['surfaceId' => 's', 'path' => '/a']));
        $this->assertSame(1, $this->renderer->model('s')->get('/a'), '缺 value 的消息被忽略，/a 仍在');
        $this->assertNotEmpty($this->renderer->takeWarnings());
    }

    public function testDeleteSurfaceRemovesEverything(): void
    {
        $this->renderer->consume(self::envelope('createSurface', ['surfaceId' => 's']));
        $this->renderer->consume(self::envelope('deleteSurface', ['surfaceId' => 's']));
        $this->assertFalse($this->renderer->hasSurface('s'));
        // 删除后指向它的更新 = warning + 忽略（surface 已不存在）
        $this->renderer->consume(self::envelope('updateDataModel', ['surfaceId' => 's', 'value' => []]));
        $this->assertNotEmpty($this->renderer->takeWarnings());
    }

    // ── 模板迭代（Collection Scope + 实例后缀 id） ─────────────────────

    private function consumeEmployeeSurface(): void
    {
        // spec「Example: scope resolution」的 employees 例 + 模板按钮
        $this->renderer->consume(self::envelope('createSurface', [
            'surfaceId' => 'team',
            'dataModel' => ['company' => 'Acme', 'employees' => [
                ['name' => 'Alice', 'role' => 'Engineer'],
                ['name' => 'Bob', 'role' => 'Designer'],
            ]],
            'components' => [
                ['id' => 'root', 'component' => 'List', 'children' => ['path' => '/employees', 'componentId' => 'card']],
                ['id' => 'card', 'component' => 'Column', 'children' => ['who', 'idx']],
                ['id' => 'who', 'component' => 'Text', 'text' => ['@path' => 'name']],
                ['id' => 'idx', 'component' => 'Text', 'text' => ['@call' => 'formatString', 'args' => ['value' => '#${@index(offset: 1)}']]],
            ],
        ]));
    }

    public function testTemplateIterationWithRelativePathsAndIndex(): void
    {
        $this->consumeEmployeeSurface();
        $out = $this->renderer->toWidgetTree('team');
        // scroll(vbox(实例列表))
        $this->assertSame('scroll', $out['tree']['type']);
        $items = $out['tree']['children'][0]['children'];
        $this->assertCount(2, $items);
        // 实例 id = 原组件 id + "__<index>"（嵌套叠加），帧间稳定才能保住控件状态
        $this->assertSame('Alice', $items[0]['children'][0]['text']);
        $this->assertSame('who__0', $items[0]['children'][0]['id']);
        $this->assertSame('Bob', $items[1]['children'][0]['text']);
        $this->assertSame('who__1', $items[1]['children'][0]['id']);
        // @index(offset:1)：#1 / #2
        $this->assertSame('#1', $items[0]['children'][1]['text']);
        $this->assertSame('#2', $items[1]['children'][1]['text']);
        $this->assertSame([], $this->renderer->takeWarnings());
    }

    public function testTemplateWithMissingOrScalarCollectionIsSkipped(): void
    {
        // 渐进渲染：路径未到 / 标量 = 空列表，不报错
        $this->renderer->consume(self::envelope('createSurface', [
            'surfaceId' => 's',
            'components' => [
                ['id' => 'root', 'component' => 'List', 'children' => ['path' => '/ghost', 'componentId' => 'card']],
                ['id' => 'card', 'component' => 'Text', 'text' => 'x'],
            ],
        ]));
        $out = $this->renderer->toWidgetTree('s');
        $this->assertSame('scroll', $out['tree']['type']);
        // node() 对空 children 不落键；vbox 层用 ?? [] 读
        $this->assertSame([], $out['tree']['children'][0]['children'] ?? []);
        $this->assertSame([], $this->renderer->takeWarnings());
    }

    // ── 占位与按钮事件 ────────────────────────────────────────────────

    public function testUnsupportedComponentRendersPlaceholder(): void
    {
        $this->renderer->consume(self::envelope('createSurface', [
            'surfaceId' => 's',
            'components' => [
                ['id' => 'root', 'component' => 'Column', 'children' => ['v', 'u']],
                ['id' => 'v', 'component' => 'Video', 'src' => 'x.mp4'],
                ['id' => 'u', 'component' => 'Mystery', 'payload' => 1],
            ],
        ]));
        $out = $this->renderer->toWidgetTree('s');
        $kids = $out['tree']['children'];
        $this->assertStringContainsString('Video', $kids[0]['text']);
        $this->assertStringContainsString('Mystery', $kids[1]['text'], '未知组件也占位，不静默白屏');
    }

    public function testButtonActionBindingAndDispatch(): void
    {
        $this->consumeEmployeeSurface();
        $this->renderer->consume(self::envelope('updateComponents', ['surfaceId' => 'team', 'components' => [
            // 模板里加按钮：context 相对路径绑定当前 item
            ['id' => 'card', 'component' => 'Column', 'children' => ['who', 'pick']],
            ['id' => 'pick', 'component' => 'Button', 'child' => 'pick_label', 'action' => ['event' => [
                'name' => 'select_employee',
                'userMessage' => 'picked ${name}',
                'context' => ['who' => ['@path' => 'name']],
            ]]],
            ['id' => 'pick_label', 'component' => 'Text', 'text' => '选择'],
        ]]));
        $out = $this->renderer->toWidgetTree('team');
        // 层级：scroll → children[0]=vbox → children[0]=card__0(Column) → children[1]=pick__0
        $btn0 = $out['tree']['children'][0]['children'][0]['children'][1];
        $this->assertSame('button', $btn0['type']);
        $this->assertSame('选择', $btn0['text']);
        $this->assertSame('pick__0', $btn0['id']);
        $this->assertArrayHasKey('pick__0', $out['events']);
        $this->assertArrayHasKey('pick__1', $out['events']);

        // 派发时解析：__0 实例的相对绑定落到 employees/0
        $action = $this->renderer->buildAction('team', 'pick__0');
        $this->assertNotNull($action);
        $this->assertSame('select_employee', $action['action']['name']);
        $this->assertSame('team', $action['action']['surfaceId']);
        $this->assertSame('pick', $action['action']['sourceComponentId'], 'sourceComponentId 用未加后缀的组件 id');
        $this->assertSame(['who' => 'Alice'], $action['action']['context']);
        $this->assertSame('picked Alice', $action['action']['userMessage']);
        $action1 = $this->renderer->buildAction('team', 'pick__1');
        $this->assertSame(['who' => 'Bob'], $action1['action']['context']);
        $this->assertSame('picked Bob', $action1['action']['userMessage']);
        $this->assertNotNull($action1['action']['timestamp']);
    }

    public function testBuildActionForUnknownWidgetReturnsNull(): void
    {
        $this->consumeEmployeeSurface();
        $this->renderer->toWidgetTree('team');
        $this->assertNull($this->renderer->buildAction('team', 'nope'));
    }

    public function testTwoWayBindingWritebackThenActionReadsLatest(): void
    {
        // spec 表单提交模式：bind → interact（写回本地模型）→ action（带最新值回传）
        $this->renderer->consume(self::envelope('createSurface', [
            'surfaceId' => 'form',
            'dataModel' => ['email' => ''],
            'components' => [
                ['id' => 'root', 'component' => 'Column', 'children' => ['field', 'go']],
                ['id' => 'field', 'component' => 'TextField', 'value' => ['@path' => '/email']],
                ['id' => 'go', 'component' => 'Button', 'child' => 'lbl', 'action' => ['event' => [
                    'name' => 'submit', 'context' => ['email' => ['@path' => '/email']],
                ]]],
                ['id' => 'lbl', 'component' => 'Text', 'text' => '提交'],
            ],
        ]));
        $out = $this->renderer->toWidgetTree('form');
        $this->assertSame('', $out['tree']['children'][0]['text'], '初值来自模型（空）');
        // 用户输入 → 写回本地模型（不产生 outbound）
        $this->assertTrue($this->renderer->applyInput('form', 'field', 'jane@example.com'));
        $this->assertSame('jane@example.com', $this->renderer->model('form')->get('/email'));
        $this->assertSame([], $this->renderer->takeOutbound());
        // 点击提交：context 在派发时解析到刚写回的值
        $action = $this->renderer->buildAction('form', 'go');
        $this->assertSame(['email' => 'jane@example.com'], $action['action']['context']);
    }

    public function testCheckboxSliderChoiceMapAndWriteback(): void
    {
        $this->renderer->consume(self::envelope('createSurface', [
            'surfaceId' => 'prefs',
            'dataModel' => ['subscribe' => true, 'level' => 60, 'pick' => 'b'],
            'components' => [
                ['id' => 'root', 'component' => 'Column', 'children' => ['sub', 'lvl', 'pick']],
                ['id' => 'sub', 'component' => 'CheckBox', 'label' => '订阅', 'value' => ['@path' => '/subscribe']],
                ['id' => 'lvl', 'component' => 'Slider', 'value' => ['@path' => '/level'], 'min' => 0, 'max' => 100],
                ['id' => 'pick', 'component' => 'ChoicePicker', 'value' => ['@path' => '/pick'],
                    'options' => ['a', 'b', 'c']],
            ],
        ]));
        $out = $this->renderer->toWidgetTree('prefs');
        $kids = $out['tree']['children'];
        $this->assertSame('checkbox', $kids[0]['type']);
        $this->assertSame('订阅', $kids[0]['text']);
        $this->assertTrue($kids[0]['checked']);
        $this->assertSame('slider', $kids[1]['type']);
        $this->assertSame(60, $kids[1]['value']);
        $this->assertSame('combo', $kids[2]['type']);
        $this->assertSame(['a', 'b', 'c'], $kids[2]['items']);
        $this->assertSame('b', $kids[2]['value']);
        // 双绑写回：toggle "1"/"0"、slider 数字
        $this->assertTrue($this->renderer->applyInput('prefs', 'sub', false));
        $this->assertFalse($this->renderer->model('prefs')->get('/subscribe'));
        $this->assertTrue($this->renderer->applyInput('prefs', 'lvl', 30));
        $this->assertSame(30, $this->renderer->model('prefs')->get('/level'));
    }

    // ── outbox（rendererFunctionResponse / agentFunctionResponse） ────

    public function testCallRendererFunctionRejectsWithResponse(): void
    {
        // v1 未注册渲染器函数 ⇒ 恒 INVALID_FUNCTION_CALL 回执（spec：必须回，void 也要）
        $this->renderer->consume(self::envelope('callRendererFunction', [
            'functionCallId' => 'fc1',
            'callFunction' => ['@call' => 'getScreenResolution', 'catalogId' => 'https://example.com/catalog.json'],
        ]));
        $outbound = $this->renderer->takeOutbound();
        $this->assertCount(1, $outbound);
        $this->assertArrayHasKey('rendererFunctionResponse', $outbound[0]);
        $this->assertSame('fc1', $outbound[0]['rendererFunctionResponse']['functionCallId']);
        $this->assertSame('INVALID_FUNCTION_CALL', $outbound[0]['rendererFunctionResponse']['error']['code']);
    }

    public function testAgentFunctionResponseWithoutCallIsUnsolicited(): void
    {
        $this->renderer->consume(self::envelope('agentFunctionResponse', ['functionCallId' => 'fc9', 'value' => 1]));
        $warnings = $this->renderer->takeWarnings();
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('fc9', $warnings[0]);
        $this->assertSame([], $this->renderer->takeOutbound());
    }
}
