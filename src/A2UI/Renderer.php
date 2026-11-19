<?php

declare(strict_types=1);

namespace TypePHP\Qt\A2UI;

/**
 * Renderer — A2UI 信封消费与渲染主入口（v1.0 spec「Envelope message structure」
 * +「UI composition: the adjacency list model」）。
 *
 * 信封规则：顶层 version + 恰好一个协议键（六选一）；未知键 = warning + 忽略
 * （未知消息宽容是仓库兼容默认）。surfaceId 渲染器生命周期内全局唯一：
 * 重复 createSurface = INTEGRITY_ERROR（spec 状态完整性错误，回执 error 信封）。
 *
 * 邻接表语义：组件扁平存表，root 挂 Surface 容器；root 未到之前其它组件缓冲不渲染
 * （spec 原文 "buffered until a root component is defined"）→ 占位树；缺失引用 =
 * 跳过（spec "skipping invalid references"）；未支持组件 = 占位 label（不静默白屏，
 * qml 占位纪律）。
 *
 * 模板实例的控件 id = 原组件 id + "__<index>" 后缀（嵌套模板叠加后缀）：动态容器
 * 孩子必须显式 id 是本仓库渲染层的硬规则（AGENTS.md 渲染坑），同模板不同项的同一
 * 组件在后缀下互异且帧间稳定，diff 才能复用控件保住输入状态。
 *
 * 事件绑定表：action.event 组件登记 {name, context(未解析), userMessage(未解析),
 * scopeBase/scopeIndex（模板实例的作用域快照）}；点击后用 buildAction() 重建作用域、
 * 解析动态绑定、产出 Outbound::action 信封——context 的解析发生在**派发时**而非渲染时
 * （spec：context 是「动态绑定解析后」的键值对，而双绑输入的最新值要等点击那一刻）。
 */
final class Renderer
{
    /** @var array<string, array<string, mixed>> surfaceId → 状态 */
    private array $surfaces = [];

    /** @var array<string, array<string, array<string, mixed>>> surfaceId → 控件id → 事件绑定 */
    private array $bindings = [];

    /** @var array<int, array<string, mixed>> rendererFunctionResponse / error 信封队列 */
    private array $outbox = [];

    /** @var array<int, string> */
    private array $warnings = [];

    /** 模板实例化的深度闸（病态嵌套/组件环的运行时兜底；结构校验是 spec 校验器的职责）。 */
    private const MAX_DEPTH = 64;

    public function consume(array $envelope): void
    {
        if (isset($envelope['version']) && $envelope['version'] !== '1.0') {
            $this->warnings[] = '信封 version 非 1.0：' . self::short($envelope['version']);
        }
        foreach (['createSurface', 'updateComponents', 'updateDataModel', 'deleteSurface', 'callRendererFunction', 'agentFunctionResponse'] as $key) {
            if (array_key_exists($key, $envelope)) {
                $this->{'consume' . ucfirst($key)}($envelope[$key]);
                return;
            }
        }
        $this->warnings[] = '未知信封键（六选一之外），整条忽略：' . self::short(array_keys($envelope));
    }

    /** @return array<int, string> 取走全部告警（幂等清空）。 */
    public function takeWarnings(): array
    {
        $warnings = $this->warnings;
        $this->warnings = [];
        return $warnings;
    }

    /** 取走 outbox（rendererFunctionResponse / error 信封；spec 要求的必达回执）。 */
    public function takeOutbound(): array
    {
        $outbox = $this->outbox;
        $this->outbox = [];
        return $outbox;
    }

    public function hasSurface(string $surfaceId): bool
    {
        return isset($this->surfaces[$surfaceId]);
    }

    public function surfaceIds(): array
    {
        return array_keys($this->surfaces);
    }

    /** surface 数据模型（sendDataModel 回传快照 / 单测读数）；未知 surface 返回 null。 */
    public function model(string $surfaceId): ?DataModel
    {
        return $this->surfaces[$surfaceId]['model'] ?? null;
    }

    // ── 信封分发（六消息） ─────────────────────────────────────────────

    private function consumeCreateSurface(array $msg): void
    {
        $surfaceId = is_string($msg['surfaceId'] ?? null) ? $msg['surfaceId'] : '';
        if ($surfaceId === '') {
            $this->warnings[] = 'createSurface 缺 surfaceId，整条忽略';
            return;
        }
        if (isset($this->surfaces[$surfaceId])) {
            // spec：重复 create = 状态完整性错误（必须先 deleteSurface 才能重建）
            $this->warnings[] = "surface \"{$surfaceId}\" 重复 createSurface（INTEGRITY_ERROR）";
            $this->outbox[] = Outbound::error('INTEGRITY_ERROR', "Surface \"{$surfaceId}\" already exists", $surfaceId);
            return;
        }
        $surface = [
            'catalogId' => is_string($msg['catalogId'] ?? null) ? $msg['catalogId'] : null,
            'sendDataModel' => ($msg['sendDataModel'] ?? false) === true,
            'components' => [],
            'model' => new DataModel(),
        ];
        $this->surfaces[$surfaceId] = $surface;
        // spec：同消息带 dataModel+components 时**先 dataModel**（首帧绑定有数据）
        if (is_array($msg['dataModel'] ?? null)) {
            $surface['model']->replaceModel($msg['dataModel']);
        }
        if (is_array($msg['components'] ?? null)) {
            $this->applyComponents($surfaceId, $msg['components']);
        }
    }

    private function consumeUpdateComponents(array $msg): void
    {
        $surfaceId = is_string($msg['surfaceId'] ?? null) ? $msg['surfaceId'] : '';
        if (!isset($this->surfaces[$surfaceId])) {
            $this->warnings[] = "updateComponents 指向未创建 surface \"{$surfaceId}\"，忽略";
            return;
        }
        if (!is_array($msg['components'] ?? null)) {
            $this->warnings[] = 'updateComponents 缺 components 数组，忽略';
            return;
        }
        $this->applyComponents($surfaceId, $msg['components']);
    }

    /** 组件表更新：按 id 整条替换（spec：updateComponents 替换整条组件定义）。 */
    private function applyComponents(string $surfaceId, array $components): void
    {
        foreach ($components as $component) {
            if (!is_array($component)) {
                $this->warnings[] = 'components 里的非对象条目，跳过';
                continue;
            }
            $id = is_string($component['id'] ?? null) ? $component['id'] : '';
            if ($id === '' || !Catalog::isValidId($id)) {
                $this->warnings[] = '组件 id 缺失或违反 UAX #31，跳过：' . self::short($id);
                continue;
            }
            $this->surfaces[$surfaceId]['components'][$id] = $component;
        }
    }

    private function consumeUpdateDataModel(array $msg): void
    {
        $surfaceId = is_string($msg['surfaceId'] ?? null) ? $msg['surfaceId'] : '';
        if (!isset($this->surfaces[$surfaceId])) {
            $this->warnings[] = "updateDataModel 指向未创建 surface \"{$surfaceId}\"，忽略";
            return;
        }
        if (!array_key_exists('value', $msg)) {
            // value 必填且 null 有「删键」语义 —— 缺 value ≠ value null，
            // 不能把畸形消息误执行成删数据
            $this->warnings[] = 'updateDataModel 缺 value（spec 必填），忽略';
            return;
        }
        $path = isset($msg['path']) && is_string($msg['path'] ?? null) ? $msg['path'] : null;
        $this->surfaces[$surfaceId]['model']->applyUpdate($path, $msg['value']);
    }

    private function consumeDeleteSurface(array $msg): void
    {
        $surfaceId = is_string($msg['surfaceId'] ?? null) ? $msg['surfaceId'] : '';
        if (!isset($this->surfaces[$surfaceId])) {
            $this->warnings[] = "deleteSurface 指向未知 surface \"{$surfaceId}\"，无操作";
            return;
        }
        unset($this->surfaces[$surfaceId], $this->bindings[$surfaceId]);
    }

    private function consumeCallRendererFunction(array $msg): void
    {
        $callId = is_string($msg['functionCallId'] ?? null) ? $msg['functionCallId'] : '';
        $callFunction = is_array($msg['callFunction'] ?? null) ? $msg['callFunction'] : [];
        $callName = is_string($callFunction['@call'] ?? null) ? $callFunction['@call'] : '';
        if ($callId === '' || $callName === '') {
            $this->warnings[] = 'callRendererFunction 畸形（缺 functionCallId / @call），忽略';
            return;
        }
        // v1 未注册任何渲染器函数 ⇒ 恒 INVALID_FUNCTION_CALL（spec：未注册 = 必须
        // 立即拒绝并回执 rendererFunctionResponse，返回 void 也要回）
        $this->outbox[] = Outbound::rendererFunctionResponse($callId, null, [
            'code' => 'INVALID_FUNCTION_CALL',
            'message' => "Renderer function \"{$callName}\" is not registered (v1 registers no renderer functions).",
        ]);
    }

    private function consumeAgentFunctionResponse(array $msg): void
    {
        // v1 从不发起 callAgentFunction ⇒ 收到的 agentFunctionResponse 都是无主回执：
        // 记 warning 忽略（宽容语义），functionCallId 留在告警文本里供排查
        $callId = is_string($msg['functionCallId'] ?? null) ? $msg['functionCallId'] : '';
        $this->warnings[] = "无主 agentFunctionResponse（v1 不发起 callAgentFunction），忽略：{$callId}";
    }

    // ── 渲染：邻接表 → WidgetTree 控件树 ─────────────────────────────

    /**
     * 渲染主入口。返回 ['tree' => WidgetTree 节点数组, 'events' => 绑定表]；
     * surface 不存在返回 null（告警留痕）。root 未定义 = 占位树（缓冲语义）。
     *
     * @return array{tree: array, events: array<string, array<string, mixed>>}|null
     */
    public function toWidgetTree(string $surfaceId): ?array
    {
        if (!isset($this->surfaces[$surfaceId])) {
            $this->warnings[] = "toWidgetTree 指向未创建 surface \"{$surfaceId}\"";
            return null;
        }
        $surface = $this->surfaces[$surfaceId];
        $this->bindings[$surfaceId] = [];
        $evaluator = new Evaluator($surface['model']);
        $root = $surface['components'][Catalog::ROOT_ID] ?? null;
        if (!is_array($root)) {
            // spec：root 未到之前其它组件缓冲不渲染 —— 占位树 + 提示
            return [
                'tree' => \TypePHP\Qt\WidgetTree::label('[a2ui] 等待 root 组件…', ['id' => 'a2ui_wait_root']),
                'events' => [],
            ];
        }
        $tree = $this->buildNode($surfaceId, $evaluator, $root, Scope::root(), '', 0);
        foreach ($evaluator->takeWarnings() as $warning) {
            $this->warnings[] = $warning;
        }
        if ($tree === null) {
            $tree = \TypePHP\Qt\WidgetTree::label('[a2ui] root 组件不可渲染', ['id' => 'a2ui_bad_root']);
        }
        return ['tree' => $tree, 'events' => $this->bindings[$surfaceId]];
    }

    /**
     * 单组件 → WidgetTree 节点。$suffix 是模板实例后缀（"" = 非模板实例）；
     * 返回 null = 该引用跳过（spec "skipping invalid references"）。
     */
    private function buildNode(string $surfaceId, Evaluator $evaluator, array $component, Scope $scope, string $suffix, int $depth): ?array
    {
        if ($depth > self::MAX_DEPTH) {
            $this->warnings[] = '组件树深度超过 ' . (string) self::MAX_DEPTH . '（疑似组件环/病态嵌套），跳过';
            return null;
        }
        $type = is_string($component['component'] ?? null) ? $component['component'] : '';
        if ($type === '' || !Catalog::isValidId($type)) {
            $this->warnings[] = '组件缺 component 类型名或类型名违反 UAX #31，跳过';
            return null;
        }
        $componentId = is_string($component['id'] ?? null) ? $component['id'] : '';
        $widgetId = $componentId . $suffix;
        $props = ['id' => $widgetId];

        // 未支持 / 占位组件：占位 label，不静默白屏（qml 占位纪律）
        if (!Catalog::isSupported($type)) {
            $text = Catalog::isPlaceholder($type)
                ? '[a2ui] 未支持组件 ' . $type
                : '[a2ui] 未知组件 ' . $type;
            return \TypePHP\Qt\WidgetTree::label($text, $props);
        }

        switch (Catalog::widgetType($type)) {
            case 'label':  // Text
                $text = $evaluator->resolve($component['text'] ?? '', $scope);
                return \TypePHP\Qt\WidgetTree::label(Evaluator::stringify($text), $props);

            case 'hbox':   // Row
            case 'vbox':   // Column
                // 空孩子也渲染（渐进渲染：数据未到 ≠ 结构不存在）；空容器比
                // 「整树占位」更贴近 spec 的缓冲语义
                $children = $this->buildChildren($surfaceId, $evaluator, $component['children'] ?? null, $scope, $suffix, $depth);
                return $type === 'hbox'
                    ? \TypePHP\Qt\WidgetTree::hbox($children, $props)
                    : \TypePHP\Qt\WidgetTree::vbox($children, $props);

            case 'scroll': // List：可滚动列表 = scroll(vbox(孩子))
                $children = $this->buildChildren($surfaceId, $evaluator, $component['children'] ?? null, $scope, $suffix, $depth);
                return \TypePHP\Qt\WidgetTree::scroll([\TypePHP\Qt\WidgetTree::vbox($children)], $props);

            case 'group':  // Card：卡片容器 ≈ group（title 可选，未背书键宽容取 title）
                $children = $this->buildChildren($surfaceId, $evaluator, $component['children'] ?? null, $scope, $suffix, $depth);
                $title = $evaluator->resolve($component['title'] ?? '', $scope);
                return \TypePHP\Qt\WidgetTree::group(Evaluator::stringify($title), $children, $props);

            case 'separator': // Divider
                return \TypePHP\Qt\WidgetTree::separator($props);

            case 'tabs':   // Tabs：页集合键协议正文未背书，v1 接受 tabs/items 候选
                $pages = $component['tabs'] ?? $component['items'] ?? null;
                if (!is_array($pages)) {
                    $this->warnings[] = "Tabs 组件 \"{$componentId}\" 无 tabs/items 页集合，跳过";
                    return null;
                }
                $tabs = [];
                foreach ($pages as $page) {
                    if (!is_array($page)) {
                        continue;
                    }
                    $title = Evaluator::stringify($evaluator->resolve($page['title'] ?? '', $scope));
                    $childId = is_string($page['child'] ?? null) ? $page['child'] : '';
                    $childNode = $childId !== ''
                        ? $this->buildRef($surfaceId, $evaluator, $childId, $scope, $suffix, $depth)
                        : null;
                    if ($childNode === null) {
                        $childNode = \TypePHP\Qt\WidgetTree::label('[a2ui] 空页', ['id' => $widgetId . '_page_' . (string) count($tabs)]);
                    }
                    $tabs[] = \TypePHP\Qt\WidgetTree::tab($title, [$childNode]);
                }
                return $tabs === [] ? null : \TypePHP\Qt\WidgetTree::tabs($tabs, $props);

            case 'button': // Button：标签取 child 子组件的文本；action.event 登记绑定
                return $this->buildButton($surfaceId, $evaluator, $component, $scope, $suffix, $depth);

            case 'checkbox': // CheckBox：value 双绑（布尔）；文本取 label/text 候选
                $label = $evaluator->resolve($component['label'] ?? $component['text'] ?? '', $scope);
                $checked = $evaluator->resolveBoolean($component['value'] ?? false, $scope);
                $this->recordWriteback($surfaceId, $widgetId, $component['value'] ?? null, $scope, 'toggle');
                return \TypePHP\Qt\WidgetTree::checkbox(Evaluator::stringify($label), $checked, $props);

            case 'lineEdit': // TextField：value 双绑（字符串）
                $value = $evaluator->resolve($component['value'] ?? '', $scope);
                $this->recordWriteback($surfaceId, $widgetId, $component['value'] ?? null, $scope, 'change');
                return \TypePHP\Qt\WidgetTree::lineEdit(Evaluator::stringify($value), $props);

            case 'slider': // Slider：value 双绑（数字）；min/max 整数才应用
                $value = $evaluator->resolve($component['value'] ?? 0, $scope);
                if (is_int($component['min'] ?? null)) {
                    $props['min'] = $component['min'];
                }
                if (is_int($component['max'] ?? null)) {
                    $props['max'] = $component['max'];
                }
                $this->recordWriteback($surfaceId, $widgetId, $component['value'] ?? null, $scope, 'change');
                return \TypePHP\Qt\WidgetTree::slider(is_int($value) ? $value : (int) round((float) $value), $props);

            case 'combo':  // ChoicePicker：单选映射 combo；options 候选键（未背书，宽容）
                $options = $component['options'] ?? $component['items'] ?? [];
                $items = [];
                foreach (is_array($options) ? $options : [] as $option) {
                    $items[] = is_array($option)
                        ? Evaluator::stringify($evaluator->resolve($option['label'] ?? $option['value'] ?? '', $scope))
                        : Evaluator::stringify($option);
                }
                $value = Evaluator::stringify($evaluator->resolve($component['value'] ?? '', $scope));
                $this->recordWriteback($surfaceId, $widgetId, $component['value'] ?? null, $scope, 'change');
                return \TypePHP\Qt\WidgetTree::combo($items, $value, $props);

            case 'image':  // Image：src/url 候选键（未背书，宽容）
                $src = $component['src'] ?? $component['url'] ?? '';
                return \TypePHP\Qt\WidgetTree::image(is_string($src) ? $src : '', $props);

            default:       // MAP 表内未覆盖（理论不可达，兜底占位）
                return \TypePHP\Qt\WidgetTree::label('[a2ui] 未映射组件 ' . $type, $props);
        }
    }

    /** Button 专用：标签来自 child 子组件；action.event → 事件绑定表。 */
    private function buildButton(string $surfaceId, Evaluator $evaluator, array $component, Scope $scope, string $suffix, int $depth): array
    {
        $componentId = is_string($component['id'] ?? null) ? $component['id'] : '';
        $widgetId = $componentId . $suffix;
        $label = $componentId;
        $childId = is_string($component['child'] ?? null) ? $component['child'] : '';
        if ($childId !== '') {
            $childNode = $this->buildRef($surfaceId, $evaluator, $childId, $scope, $suffix, $depth);
            if ($childNode !== null && ($childNode['type'] ?? '') === 'label') {
                $label = (string) ($childNode['text'] ?? '');
            }
        }
        $props = ['id' => $widgetId];
        $action = is_array($component['action'] ?? null) ? $component['action'] : null;
        if ($action !== null && is_array($action['event'] ?? null)) {
            $event = $action['event'];
            $name = is_string($event['name'] ?? null) ? $event['name'] : '';
            if ($name !== '') {
                $this->bindings[$surfaceId][$widgetId] = [
                    'componentId' => $componentId,
                    'name' => $name,
                    'userMessage' => $event['userMessage'] ?? null,
                    'context' => is_array($event['context'] ?? null) ? $event['context'] : [],
                    'scopeBase' => $scope->base(),
                    'scopeIndex' => $scope->templateIndex() ?? -1,
                ];
            }
        } elseif ($action !== null) {
            // action.functionCall（本地函数动作）v1 范围外：登记 warning，按钮仍可渲染
            $this->warnings[] = "Button \"{$componentId}\" 的 action.functionCall 未支持（v1 范围外），仅渲染";
        }
        return \TypePHP\Qt\WidgetTree::button($label, $props);
    }

    /**
     * children 两形态分发：ComponentId 数组 → 逐个 buildRef；
     * 模板对象 {path, componentId} → 对模型数组迭代，每项开 Collection Scope 并给
     * 实例后缀 "__<index>"（嵌套模板叠加）。
     */
    private function buildChildren(string $surfaceId, Evaluator $evaluator, mixed $children, Scope $scope, string $suffix, int $depth): array
    {
        if (is_array($children) && isset($children['path']) && isset($children['componentId'])) {
            return $this->buildTemplate($surfaceId, $evaluator, $children, $scope, $suffix, $depth);
        }
        if (!is_array($children)) {
            return [];
        }
        $nodes = [];
        foreach ($children as $childId) {
            if (!is_string($childId)) {
                $this->warnings[] = 'children 数组里的非 ComponentId 条目，跳过';
                continue;
            }
            $node = $this->buildRef($surfaceId, $evaluator, $childId, $scope, $suffix, $depth);
            if ($node !== null) {
                $nodes[] = $node;
            }
        }
        return $nodes;
    }

    /** 模板迭代：path 解析 → 数据模型数组 → 逐项实例化模板子树。 */
    private function buildTemplate(string $surfaceId, Evaluator $evaluator, array $template, Scope $scope, string $suffix, int $depth): array
    {
        $path = is_string($template['path'] ?? null) ? $template['path'] : '';
        $templateId = is_string($template['componentId'] ?? null) ? $template['componentId'] : '';
        if ($path === '' || $templateId === '') {
            $this->warnings[] = 'children 模板缺 path/componentId，跳过';
            return [];
        }
        // spec：模板 path 是**纯字符串属性**（不是 {"@path"} 对象）
        $collection = $this->model($surfaceId)?->get($scope->resolve($path));
        if (!is_array($collection) || !array_is_list($collection) || $collection === []) {
            return [];  // 渐进渲染：数据未到 = 空列表，不报错
        }
        $nodes = [];
        foreach ($collection as $index => $item) {
            if (!is_array($item) && !is_scalar($item)) {
                continue;
            }
            $itemPointer = DataModel::resolveRelative($scope->resolve($path), (string) $index);
            $itemScope = $scope->child($itemPointer, $index);
            $node = $this->buildRef($surfaceId, $evaluator, $templateId, $itemScope, $suffix . '__' . (string) $index, $depth);
            if ($node !== null) {
                $nodes[] = $node;
            }
        }
        return $nodes;
    }

    /** 按 id 从组件表取组件并构节点；缺失引用返回 null（跳过语义）。 */
    private function buildRef(string $surfaceId, Evaluator $evaluator, string $componentId, Scope $scope, string $suffix, int $depth): ?array
    {
        $component = $this->surfaces[$surfaceId]['components'][$componentId] ?? null;
        if (!is_array($component)) {
            return null;  // spec：缺失引用跳过（渐进渲染缓冲，不报错）
        }
        return $this->buildNode($surfaceId, $evaluator, $component, $scope, $suffix, $depth + 1);
    }

    // ── 事件派发与双绑写回 ─────────────────────────────────────────────

    /**
     * 控件点击 → outbound action 信封（绑定表命中时）。context/userMessage 在
     * **此刻**解析：用渲染时快照的作用域重建 Scope（模板实例内的相对路径绑定
     * 才能解析到正确的 item），再把点击时已写回模型的最新值读进 context ——
     * 这正是 spec 表单提交模式（bind → interact → action → send）的语义。
     */
    public function buildAction(string $surfaceId, string $widgetId): ?array
    {
        $binding = $this->bindings[$surfaceId][$widgetId] ?? null;
        if (!is_array($binding)) {
            return null;
        }
        $scope = $this->rebuildScope($binding);
        $surface = $this->surfaces[$surfaceId];
        $evaluator = new Evaluator($surface['model']);
        $context = $evaluator->resolveDeep($binding['context'], $scope);
        foreach ($evaluator->takeWarnings() as $warning) {
            $this->warnings[] = $warning;
        }
        $userMessage = null;
        if (is_string($binding['userMessage'] ?? null) && $binding['userMessage'] !== '') {
            // userMessage 是 DynamicString：先 resolve（@path/@call 槽位），再过一遍
            // formatString —— 纯字符串形态里的 ${...} 相对绑定也要展开（spec 的
            // userMessage 例子是字面量，但模板实例里 "picked ${name}" 是常态）
            $resolved = $evaluator->resolve($binding['userMessage'], $scope);
            $userMessage = $evaluator->formatString(Evaluator::stringify($resolved), $scope);
        }
        return Outbound::action(
            (string) $binding['name'],
            $surfaceId,
            (string) $binding['componentId'],
            $context,
            $userMessage,
        );
    }

    /**
     * 双绑写回入口（spec「Two-way binding」：输入组件的本地写回**不**自动发网络）。
     * 应用层把 input 控件的 change/toggle 事件值递进来；绑定表有 writeback 指针才落。
     */
    public function applyInput(string $surfaceId, string $widgetId, mixed $value): bool
    {
        $binding = $this->bindings[$surfaceId][$widgetId] ?? null;
        $pointer = is_array($binding) ? ($binding['writeback'] ?? null) : null;
        if (!is_string($pointer) || $pointer === '') {
            return false;
        }
        $this->model($surfaceId)?->applyUpdate($pointer, $value);
        return true;
    }

    /** 渲染时登记 writeback：value 槽是 {"@path"} 绑定才有效（字面量无从写回）。 */
    private function recordWriteback(string $surfaceId, string $widgetId, mixed $valueSlot, Scope $scope, string $inputType): void
    {
        if (!is_array($valueSlot) || !is_string($valueSlot['@path'] ?? null)) {
            return;
        }
        $this->bindings[$surfaceId][$widgetId] = [
            'writeback' => $scope->resolve($valueSlot['@path']),
            'inputType' => $inputType,
        ];
    }

    /** 用渲染时快照的作用域（base/index）重建 Scope。 */
    private function rebuildScope(array $binding): Scope
    {
        $scope = Scope::root();
        $base = is_string($binding['scopeBase'] ?? null) ? $binding['scopeBase'] : '';
        $index = is_int($binding['scopeIndex'] ?? null) ? $binding['scopeIndex'] : -1;
        if ($index >= 0 && $base !== '') {
            $scope = $scope->child($base, $index);
        }
        return $scope;
    }

    /** 告警文本里的值摘要（截断，防超长 payload 撑爆日志）。 */
    private static function short(mixed $value): string
    {
        $text = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
        if ($text === false || $text === null) {
            $text = '';
        }
        return mb_strlen($text) > 120 ? mb_substr($text, 0, 117) . '…' : $text;
    }
}
