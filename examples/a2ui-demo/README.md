# a2ui-demo — A2UI 离线回放示例

[A2UI](https://github.com/a2ui-project/a2ui)（Agent-to-User Interface，v1.0）是 agent 发**声明式 JSON** 描述 UI 意图、客户端用自己的原生组件目录渲染的协议。本示例演示本仓库的 A2UI 支持（`src/A2UI/` 纯 PHP 层，零新桥接函数）：

```
信封 fixture（agent 方向）
  → Renderer::consume()          六种消息分发 / 邻接表组件表 / DataModel
  → Renderer::toWidgetTree()     Catalog 映射 → WidgetTree 控件树
  → QtApp 渲染                    现有控件，无新增控件类型
用户点击按钮
  → Renderer::applyInput()       输入双绑写回本地模型（不发网络）
  → Renderer::buildAction()      Renderer→Agent 的 action 信封（context 派发时解析）
```

与 `agui-demo` 的分工：AG-UI 是流式事件协议（SSE 桥接原语 + 协议层），A2UI 是声明式 UI 协议；两者是上下层关系（AG-UI 是 A2UI 的标准传输绑定之一）。本示例的 smoke 段把 A2UI 信封装进 `file://` SSE 流逐帧取回（`qt_sse_*` 原语），证明「线上形态」与直连 consume 等价。

## 用法

```bash
php bin/qtphp build examples/a2ui-demo
QT_QPA_PLATFORM=offscreen examples/a2ui-demo/build/a2ui-demo --selftest   # rc=0 即全过
examples/a2ui-demo/build/a2ui-demo --bench 20000                          # 三行 METRIC
examples/a2ui-demo/build/a2ui-demo                                        # GUI（点击「提交名单」看 action 信封）
```

## fixture 覆盖面

- `createSurface`：dataModel+components 同帧（spec：先 dataModel 后 components）；Card/List/Text/TextField/Button/Divider/Row/Column 八种组件
- `updateDataModel`：路径 upsert（新增第三名员工）+ 单键更新（company 改名）
- 模板迭代：`children: {path, componentId}` 对 `/employees` 数组展开，实例 id 后缀 `__<index>`（帧间稳定，保住控件状态）
- 相对路径绑定（`name` → `/employees/N/name`）+ `@index(offset: 1)` + formatString 嵌套
- 双向绑定：TextField 的 value 写回本地模型，点击时随 action.context 回传（spec 表单提交模式）

范围外（F59 §59.4）：@checks 校验引擎、formatNumber/Currency/Date 等 catalog 函数、callAgentFunction RPC 双向、live agent。
