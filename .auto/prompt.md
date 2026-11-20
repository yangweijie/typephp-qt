# A2UI 支持实验段（a2ui-support）

## Goal

给 TypePHP\Qt 添加 A2UI（Agent-to-User Interface，a2ui-project/a2ui v1.0）支持。
分两步走：

1. **从零到一**（由主会话 workflow 完成，不在本 loop 内）：A2UI 信封纯 PHP 层
   （src/A2UI/ 六类：DataModel/Scope/Evaluator/Catalog/Outbound/Renderer，零新桥接
   函数）+ examples/a2ui-demo（离线回放可无头验收，Phase 43 / F59 / F60）。
2. **本 loop 的迭代对象**：回放吞吐。`.auto/measure.sh` 跑 a2ui-demo 的 --bench 模式
   （内置信封序列回放 → 求值 → 构树），输出 `METRIC a2ui_replay_messages_per_s=<n>`。

## Metric

`a2ui_replay_messages_per_s`（higher is better）——回放模式下每秒消费的 A2UI 信封
消息数。噪声大时 repeat:3 取中位数；单轮收益 < ±2%（段 1 实测噪声带）视为无信号，
以「未改代码复测」标定后停 loop。

## Scope（可改的文件）

- `src/A2UI/*.php`（协议层六类；DataModel::get 已按 F60 用 std::any 降级，别改回）
- `src/WidgetTree.php`（仅限映射所需的纯函数级微调；改契约必须同步 stub/FakeBridge）

## Out of scope（动了就 discard）

- `examples/a2ui-demo/src/main.php`（fixture 分布——改它就是过拟合自己的尺子）
- `cpp-src/*.cc` / `php-src/qt.stub.php`（零新桥接是本段的架构前提，F59）
- `src/QtApp.php` / `src/AGUI/`（AG-UI 层已收口，别动）
- C++ 侧 / 构建配置

## 停 loop 条件（段 1 教训前置）

1. 单轮收益 < 2%（噪声带），先 noop 复测确认再收手；
2. 原则内靶点尽（每消息哈希探测/重复求值/临时数组三类收敛完）；
3. checks 红（phpunit/lint）= 该轮 discard。

## 回归底线

phpunit 320 tests / 696 assertions 全绿；`qtphp lint` 45⇄45；
`--selftest` 19/19 passed（判据不能因优化降级）。
