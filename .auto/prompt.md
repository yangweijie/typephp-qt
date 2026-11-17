# AGUI 支持实验段（segment 1）

## Goal

给 TypePHP\Qt 添加 AG-UI（Agent User Interaction Protocol，https://docs.ag-ui.com）支持：
桌面应用可以消费智能体的 AG-UI 事件流（SSE）并渲染成控件树。分两步走：

1. **从零到一**（由主会话的 workflow 完成，不在本 loop 内）：AG-UI 事件解码纯 PHP 层
   + SSE 流式桥接原语四件套 + examples/agui-demo（离线回放可无头验收）。
2. **本 loop 的迭代对象**：回放吞吐。`.auto/measure.sh` 跑 agui-demo 的 --bench 模式
   （内置事件序列回放 → 解码 → 分发），输出 `METRIC agui_events_per_sec=<n>`。

## Metric

`agui_events_per_sec`（higher is better）——回放模式下每秒解码分发的 AG-UI 事件数。
噪声大时 repeat:3 取中位数。

## Scope（可改的文件）

- `src/AGUI/*.php`（解码器/事件类型）、`php-src/qt.stub.php` + `src/FakeBridge.php` +
  `cpp-src/qt_*.cc`（SSE 原语的实现层，若优化需要动到桥接）
- `examples/agui-demo/`（回放与 --bench 模式）
- 文档：仅当优化改变了对外行为

## Off Limits

- `.auto/measure.sh` / `.auto/checks.sh` 冻结不可改
- `examples/hello` 控件树与出图基线
- `php vendor/bin/phpunit --no-coverage` 与 `php bin/qtphp lint` 必须始终绿（checks.sh 内容）
- 不 commit（autoresearch 的 keep 会自动 commit，例外）

## What's Been Tried

- **R1 基线（keep，a2f1b1e）**：agui_events_per_sec = **447974**（3 次 490433 / 447974 /
  443228 的中位数；total_ms ≈ 44.65 / 20000 事件），checks 全绿（phpunit 261 + lint 45⇄45）。
  注：从零到一由 workflow dwfrun-10f2c084 完成（契约四件套 41→45、src/AGUI 四类、
  examples/agui-demo、中英文档），基线读数详见 progress.md Session 38。
- **R2 keep（01890e3）→ 561737（+25.4%）**：expandOne 热路径 6 处现场构造的 in_array 清单
  + 每次调用构造 31 元素的 knownTypes() → 7 个 private const 预建清单。
- **R3 keep（580e79a）→ 622292（+10.8%）**：in_array 对预建清单仍 O(n) 线性 →
  7 张 type=>true const 哈希表 + isset O(1)。
- **R4 keep（bf816ea）→ 832549（+33.8%）**：closeLane 快路径——lane 无打开项时直返 []，
  跳掉每事件的 3 族字面量 + 3×closeOne + 3×array_merge（回放中绝大多数事件 lane 是空的）。
- **R5 keep（2f563d5）→ 892375（+7.2%，累计 2.0×）**：feed/expandOne/expandChunk 按引用写
  $out，消每事件临时数组 + 二次遍历。
- **R6 keep（ff89928）→ 919931（+3.1%）**：expandOne 每事件 6 张 type=>true 表各探一次
  → 合并为单张位掩码表 CLASS_OF（一次 ?? 取回 + 位运算判定 7 类属性）。
- **R7 keep（3487b88）→ 928821（+1.0%）**：expandOne 对 $event['type'] 的 4-5 次同键哈希探测
  收敛到局部 $type（legacy 映射后同步）。
- **R8 keep（b74b85b）→ 937413（+0.9%）**：findOpenLane 每 chunk 事件全 lane
  foreach → 先直探父 lane（'' 恒为首键；结果与原循环等价）。
- **R9 noop（噪声带标定，无代码改动）**：R8 态直接复测 ×3 → 951806/953014/961446，
  中位 953014，较 R8 漂移 +1.7% ⇒ **测量噪声带 ≥ ±2%，R7/R8 的 ≤1%「收益」全在带内**。
  有效收益段 = R2–R6（447974 → 919931，+105%，约 2.05×）；R7/R8 中性（语义等价，
  不回滚）。
- **停点判断（loop 收手，9/20）**：单轮收益 25 → 11 → 34 → 7 → 3 → 1 → 1 →（标定），
  原则内靶点已尽（Decoder 每事件哈希探测全覆盖、Session 每事件 2 次最小查表），
  再动就是 fixture 分布过拟合。纯 PHP 探针：full 管线纯 PHP 8.1ms vs AOT 21.4ms
  （@20000），AOT 地板封顶。重启条件：AOT 运行时变快，或 fixture 分布变了
  （subagent lane / chunk 占比显著上升），或换机复测基线。
