# A2UI

[A2UI](https://github.com/a2ui-project/a2ui)（Agent-to-User Interface，v1.0）是 agent 发
**声明式 JSON** 描述 UI 意图、客户端用自己的原生组件目录渲染的协议。TypePHP\Qt 把渲染器侧
实现为**纯 PHP 层 + 到现有控件树的映射**——零新桥接函数（传输复用既有 `qt_sse_*` 原语；
AG-UI 是 A2UI 的标准传输绑定之一，两者是上下层关系）。

| 层 | 位置 | 职责 |
|---|---|---|
| **DataModel** | `src/A2UI/DataModel.php` | JSON Pointer（RFC 6901）get / upsert / 删键；`updateDataModel` 三语义（存在→更新、不存在→创建、`value: null`→删键；path 缺省或 `/` = 整模型替换） |
| **Scope + Evaluator** | `src/A2UI/Scope.php`、`Evaluator.php` | 路径解析作用域链（模板项开 Collection Scope，相对路径相对当前 item 解析）；`@path` / `@call` / 字面量绑定、DynamicBoolean 强制转换表、`formatString` 的 `${...}` 插值、v1 函数子集（`required` / `email` / `regex` / `length` / `numeric` / `and` / `or` / `not` + `@index`） |
| **Catalog** | `src/A2UI/Catalog.php` | 18 个 Basic Catalog 组件映射到控件树类型（13 个原生、5 个占位 label）；UAX #31 id 校验 |
| **Renderer + Outbound** | `src/A2UI/Renderer.php`、`Outbound.php` | 六种 Agent→Renderer 信封进、控件树出；`buildAction()` 产出 Renderer→Agent 的 `action` 信封；rendererFunctionResponse / error 回执 |

映射把一切留在你现有的控件树里：

| A2UI 组件 | WidgetTree | 备注 |
|---|---|---|
| Text / Row / Column | `label` / `hbox` / `vbox` | |
| List | `scroll(vbox)` | 模板 `children: {path, componentId}` 对模型数组迭代 |
| Card / Tabs / Divider | `group` / `tabs+tab` / `separator` | |
| Button | `button` | 标签来自 child 的 Text；`action.event` → 事件绑定 |
| CheckBox / TextField / Slider / ChoicePicker | `checkbox` / `lineEdit` / `slider` / `combo` | 双向绑定写回本地模型 |
| Image | `image` | |
| Icon / Video / AudioPlayer / Modal / DateTimeInput | 占位 `label` | 绝不静默白屏——渲染器没有对应原生控件，spec 的 progressive rendering 允许占位 |

## 消息流

```php
use TypePHP\Qt\A2UI\Renderer;

$renderer = new Renderer();
$renderer->consume($envelope);          // 每次一条 Agent→Renderer 信封
$renderer->consume($anotherEnvelope);   // createSurface / updateComponents / updateDataModel /
                                        // deleteSurface / callRendererFunction / agentFunctionResponse

$out = $renderer->toWidgetTree('team'); // ['tree' => WidgetTree 节点, 'events' => 绑定表]
$app->view(fn (): array => $out['tree']);
```

- 同一 `surfaceId` 重复 `createSurface` = **INTEGRITY_ERROR**——渲染器把 `error` 信封排进
  outbox（`takeOutbound()`）。
- 组件 `id` 必须满足 UAX #31（`user_card` / `userCard` 合法；`user-card` / `1st_item` 拒收）。
  缺失引用跳过（渐进渲染）；组件在 `root` 定义之前缓冲不渲染，期间 `toWidgetTree()` 返回
  占位 label。
- 模板实例的控件 id 带 `__<index>` 后缀（`card__0`、`who__1`）——帧间稳定，diff 引擎复用
  控件，重渲染保住输入状态。嵌套模板叠加后缀。

## 双向绑定与 action

绑定的输入组件写回**本地**数据模型，本身从不发网络：

```php
// 用户在绑定 /email 的 TextField 里输入
$renderer->applyInput('team', 'field', 'jane@acme.dev');

// 用户点击 action.event 名为 submit_roster 的按钮
$action = $renderer->buildAction('team', 'go');
// ['version' => '1.0', 'action' => ['name', 'surfaceId', 'sourceComponentId',
//  'timestamp'（ISO 8601 UTC）, 'context' => 已解析绑定, 'userMessage'?]]
```

`context` 与 `userMessage` 在**派发时**解析而非渲染时——先重建绑定的作用域快照（按钮属于
哪个模板项），相对 `@path` 才能落到正确的 item，刚写回的输入值才是 agent 收到的值。这正是
spec 的 bind → interact → action → send 表单模式。

`callRendererFunction` 必有回执：v1 未注册任何渲染器函数，每次调用都收到
`rendererFunctionResponse` + `INVALID_FUNCTION_CALL`（spec 要求 void 函数也要回执）。

## 离线回放示例

`examples/a2ui-demo` 在内置信封 fixture 上跑全栈——零外部网络、无头可验收。三种模式：

| 模式 | 行为 |
|---|---|
| 无参数 | GUI 主循环：点「提交名单」，生成的 `action` 信封就地展示 |
| `--selftest` | 无头自检四段：① 信封 → `Renderer` → 控件树断言（模板迭代、相对路径、`@index`、upsert）；② 控件读回（`value()`）；③ `applyInput` → `buildAction` 派发断言；④ 桥接 smoke——fixture 经**真实 `file://` SSE 流**（A2UI-over-SSE 形态）逐帧取回 consume，150 帧上限。全过 → `selftest passed` + rc 0；任一失败 → rc 1 |
| `--bench [N]` | 纯 PHP 回放 N 条信封（缺省 20000），hrtime 计时，**在 Qt 创建之前** exit： |

```
METRIC a2ui_replay_messages_per_s=<int>
METRIC a2ui_replay_total_ms=<float>
METRIC a2ui_bench_messages=<int>
```

## 宽容默认

| 情形 | v1 行为 |
|---|---|
| 信封键未知（六种之外） | warning + 整条忽略 |
| `updateDataModel` 缺 `value` | 拒收——畸形消息不得被误执行成删数据 |
| 未注册的 catalog 函数（如 `formatDate`） | 解析为 `null` + warning |
| Root Scope 调 `@index` | `null` + warning（spec：必须按错误处理） |
| 未支持 / 未知组件 | 占位 label，不白屏 |
| 未识别的单-`@` 键 | `null` + warning（`@@key` 是字面 `@` 的转义） |

全部降级经 `Renderer::takeWarnings()` / `Evaluator::takeWarnings()` 收取——测试里断言它们。

## v1 范围外

- `@checks` 校验引擎与 `formatNumber` / `formatCurrency` / `formatDate` / `pluralize` /
  `openUrl` catalog 函数。
- 渲染器→agent 方向的 `callAgentFunction` RPC（渲染器从不发起）。
- JSON Schema 线上校验、inline catalogs、extensions 深消费。
- A2A / MCP 传输绑定——传输自带；`qt_sse_*` + AG-UI 已覆盖 SSE 绑定。
